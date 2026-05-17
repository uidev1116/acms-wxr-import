# 要求内容: WXRImport プラグインのパフォーマンス改善

## 背景

`WXRImport` プラグインは README で「大量データ対応の最適化処理」「バッチ処理」を謳い、推奨環境としてメモリ 512MB を提示している。
しかし現状の実装をレビューしたところ、**「数百MBのWXR / 数千エントリ / 数千メディア」規模では実用に耐えない／不必要に遅くなる**
問題が、Parser・BatchProcessor・Downloader・ContentProcessor・EntryImporter・CategoryCreator の各層に分散して存在する。

特に問題なのは次の3点で、設計判断を伴う改修が必要なため本ステアリングを起こす:

1. **ストリーミング設計が途中で崩れている**
   Parser は `\Generator` を返しているが、`Execute.php` で全件をメモリ配列に展開してから BatchProcessor に渡しているため、
   WXR本文（最大MB級 × 件数分）が同時にメモリ常駐する。
2. **「最適化」ロジックが実質機能していない**
   `BatchProcessor::$memoryLimit` の算出式がバグっており、ユーザーが管理画面で指定した `batch_size` が
   ほぼ常に `MIN_BATCH_SIZE = 5` に縮められる。
3. **N+1 クエリと O(n²)〜O(n³) アルゴリズムの存在**
   ContentProcessor の `getMediaInfo()`、EntryImporter のサブカテゴリ／タグ／ソート値クエリ、
   CategoryCreator の `sortCategoriesByHierarchy()` と Nested Set 更新が件数比例以上で悪化する。

## 解決したい課題（性能上のボトルネック）

| ID | 課題 | 該当箇所 | 影響 |
|----|------|----------|------|
| P-1 | WXRファイル全体を3〜4重にメモリへ保持 | `Services/WXR/Parser.php:115-135, 606-616` | 100MB の WXR で数百MB のメモリを瞬間消費。OOM の主因 |
| P-2 | `BatchProcessor::$memoryLimit` 計算バグ | `Services/Import/BatchProcessor.php:55, 477-498` | `batch_size` が実質無視され、常に最小化される |
| P-3 | Parser の Generator を Execute 側で全件 buffering | `POST/WxrImport/Execute.php:149-172` | WXREntry／WXRMedia が全件メモリ常駐 |
| P-4 | cURL のヘッダ込みフルバッファ取得 | `Services/Media/Downloader.php:633-672` | 最大50MB×2のメモリコピー。並列化したときに致命的 |
| P-5 | ContentProcessor の N+1 SELECT | `Services/Content/ContentProcessor.php:140-197, 356` | 画像1枚ごとに `media` テーブルへ1〜2クエリ |
| P-6 | EntryImporter のクエリ膨張（1件あたり15〜30クエリ） | `Services/Import/EntryImporter.php:127-167, 275-348, 416-450` | 1000エントリで数万クエリ |
| P-7 | CategoryCreator のソートが O(n³) | `Services/Import/CategoryCreator.php:74-108` | 数百カテゴリで顕著に遅延 |
| P-8 | Nested Set 更新が毎回フルテーブルUPDATE | `Services/Import/CategoryCreator.php:315-330` | 子カテゴリ作成ごとに全行UPDATE。O(N²) |
| P-9 | XPath `//` を item ごとに15回以上発行 | `Services/WXR/Parser.php:205-265, 519-576` | Parser 単体のCPU負荷が大きい |
| P-10 | ローカルコピーで全バイト経由 | `Services/Media/Downloader.php:199-261` | 50MB のローカルコピーで 50MB のメモリ消費 |
| P-11 | 固定 `usleep` の積み上げ | `Services/Import/BatchProcessor.php:294, 395, 409`, `Execute.php:201` | ローカル取り込みでも無条件で発生し、数百秒の純粋待機 |
| P-12 | DNS解決を URL ごとに再実行 | `Services/Media/Downloader.php:749` | 同一ホストでもキャッシュなし |
| P-13 | `extractTerms` の重複排除が O(n²) | `Services/WXR/Parser.php:469-478` | タクソノミ数×タグ数で増加 |

## 想定ユーザーストーリー

- 移行担当として、**500MBのWXR（5,000エントリー／3,000メディア）を、PHP `memory_limit=512M` のサーバーで完走させたい**。
- 移行担当として、管理画面で `batch_size=50` を指定したとき、**その値が実際に反映されてほしい**。
- 移行担当として、ローカルパス取り込み時は **ネットワーク向けのレート遅延が発生せず**、CPU 限界の速度で完走してほしい。
- 移行担当として、進捗ログを見て **どのフェーズ（Parse / Category / Media / Entry）に時間がかかったか**把握したい。

## 受け入れ条件（Acceptance Criteria）

### AC-1: メモリ上限の達成
- 公式推奨環境（メモリ 512MB）下で、**100MB の WXR ファイル**（エントリー1,000件・メディア500件・各画像 1MB 程度）を OOM なしで完走できる。
- 完走時のピークメモリは **WXRファイルサイズ × 1.5 以下** を目標とする（現状は概算 ×4〜×5）。

### AC-2: `batch_size` 設定の実効化
- 管理画面で `batch_size=50` を指定したとき、メモリに余裕がある状態では **少なくともその値（ないし当該値の意図に沿った範囲）でバッチが実行される**。
- メモリ逼迫時のみ縮小される、という当初の設計意図どおりに動く。

### AC-3: 1エントリあたり DB 往復数の削減
- 1エントリあたりのクエリ発行数を **現状の概ね 1/3 以下** に削減する。具体的には:
  - サブカテゴリ／タグ INSERT を `INSERT ... VALUES (...), (...)` の multi-row 化。
  - エントリーの sort 系3クエリ（`nextEntrySort` / `nextEntryUserSort` / `nextEntryCategorySort`）をバッチ先頭で一括取得し、メモリ上で払い出す。
  - `ACMS_RAM::entryBlog($eid)` の重複ルックアップ廃止（`$settings['target_blog_id']` を渡す）。
  - `determineMainCategory` の重複呼び出し廃止。

### AC-4: メディア情報の事前一括取得
- ContentProcessor が本文内ブロックを処理する際に、`media` テーブルへの SELECT が **エントリー数や画像数に比例しない**。
- BatchProcessor 開始時点で `mediaMapping` 全体に対して **1 本の `WHERE media_id IN (...)`** で一括取得し、ContentProcessor へ注入する。
- `getMediaInfo()` の重複呼び出し（`buildFileBlockHtml` 経路と `replaceMediaUrl` 経路）も解消する。

### AC-5: cURL ダウンロードのストリーミング保存
- HTTP ダウンロードはレスポンスボディを **ファイルへ直接書き出し（`CURLOPT_FILE` + `CURLOPT_HEADERFUNCTION`）**、メモリ常駐量を数十KB程度に抑える。
- リダイレクト追跡・SSRF対策・MIME検証は **AC-3（旧 security-hardening の AC-3／AC-4）を後退させない**。
- 最大ファイルサイズ制限（`maxFileSize`）の挙動も維持する。

### AC-6: ローカルコピー経路のストリーミング化
- `Downloader::copyLocalFile()` が **ファイル全体を文字列にロードしない**。`stream_copy_to_stream` 等で実装する。
- 経路依存のセキュリティ検証（`validateDirectoryTraversalPath`, `MimeTypeValidator`）は維持する。

### AC-7: Parser のストリーミング化
- Parser が WXR ファイルを **`XMLReader::open($filePath)` でストリーム開く**。`$data = LocalStorage::get(...)` で全体を文字列化しない。
- `validateXml()` 相当の検証もストリームベースで行う、または **取り込み中に問題があれば即時失敗** に置き換える。
- 不正文字（既存の `LocalStorage::removeIllegalCharacters`）の扱いは、ストリーム読み込み下でも等価な結果になるよう設計フェーズで決定する。
- 1 item あたりの DOMXPath クエリは、`//` を `./` 系に置き換えるなどしてサブツリー走査を削減する。

### AC-8: 取り込みパイプラインの逐次化
- Execute → BatchProcessor のフローで、**「全件をメモリ配列に展開する段」を廃止**するか、少なくとも本文等の大きい部分が同時に1バッチ分しか乗らないようにする。
- カテゴリ／メディア／エントリの依存関係上、カテゴリは先行する必要があるが、エントリ本文はバッチごとにストリームで処理する。

### AC-9: CategoryCreator のアルゴリズム改善
- `sortCategoriesByHierarchy()` を **O(n+e) のトポロジカルソート** に置き換える（`$processed` を連想配列化、または Kahn のアルゴリズム）。
- 子カテゴリ作成時の Nested Set 更新（`updateNestedSetForInsertion`）を **「親の right 以降を `+(2 × 新規子孫数)` で1回シフトし、新規ノードは一括 INSERT」** に変更する。
- 既存のカテゴリツリーが破壊されないこと（既存 left/right 値の整合性）を回帰テストで担保する。

### AC-10: 不要な待機の整理
- `BatchProcessor` のバッチ間／件間 `usleep` は **設定可能（デフォルト0、または0.1秒以下）** にする。少なくとも **ローカルパス取り込み経路では適用されない** こと。
- `Downloader::applyRateLimit()` は **HTTPダウンロード時のみ** に限定する（現状もそうだが、BatchProcessor 側が二重に遅延を入れている）。
- `Execute.php:201` の `sleep(5)` は撤去する。

### AC-11: 計測フックの追加
- BatchProcessor の戻り値に **フェーズ別（Parse / Category / Media / Entry）の経過時間** を追加する。
- 進捗ロガーにフェーズ完了時のサマリを残し、運用上のボトルネック特定を可能にする。
- 計測結果は受け入れテスト時の判断材料にする（AC-1 / AC-3 検証のため）。

### AC-12: 結果の同一性
- すべての改修後、**既存の移行結果と整合**すること。具体的には:
  - エントリー件数・メディア件数・カテゴリ件数・タグ件数が同一。
  - 本文内のメディア URL 書換結果が同一。
  - WordPress カスタムフィールドの保存名（`wp_*`）と値が同一。
  - WordPress アイキャッチが a-blog cms メイン画像へ引き継がれる挙動が同一。
- 回帰確認用の小規模 WXR（既存テストで使うもの、なければ作成）で before/after の DB 状態を比較する。

## 制約事項

- 既存ユーザーの管理画面 UI（フォーム項目）は **本ステアリングでは変更しない**。`batch_size` のヒント文言更新程度に留める。
- `Services/WXR/Parser::parse()` の戻り値型（`\Generator`）と yield アイテム構造は変更しない（呼び出し側のみ変更可）。
- `BatchProcessor::processAll()` の公開シグネチャは原則維持する（内部のストリーミング化は許容）。
- a-blog cms コア API（`Common::saveField`, `Media::insertMedia`, `Media::storeImage` 等）の使い方は変更しない。
- セキュリティ要件（旧 `20260511-security-hardening` の AC-1〜AC-5）を後退させない。
- 動作要件 PHP 8.1+ / a-blog cms 3.2.20+ を維持する。

## 対象外（Out of Scope）

- 並列ダウンロード（`curl_multi_*` の導入）。設計上の選択肢としては議論するが、本ステアリングでは実装しない。
- 増分インポート／再開機能（途中失敗からの続行）。
- 進捗 UI のデザイン変更（既存の `20260510-replace-underscore-progress-ui` の範疇）。
- a-blog cms コア側の API 改修（コアの `saveField` などが内部で DELETE→INSERT する点はそのまま受け入れる）。
- XML パーサの XXE 対策確認（`libxml_disable_entity_loader` 等は別タスク）。
- WXR ファイルの分割アップロード対応。
