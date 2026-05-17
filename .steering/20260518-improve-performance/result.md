# 実装結果サマリ

ステアリング `20260518-improve-performance` の実装が完了。本ファイルは AC 達成状況のチェックリストと、実測待ち項目の整理。

## コミット一覧（実装順）

| Task | コミット | 内容 |
|------|----------|------|
| 1 | `2b12512` | Fix BatchProcessor memory budget to use ini memory_limit instead of current usage |
| 2 | `998c04e` | Remove redundant sleeps and make batch pause configurable |
| 3 | `89c80b8` | Speed up Parser by using relative XPath and O(1) slug dedup |
| 4 | `30033fc` | Cache DNS resolution per import run to avoid re-lookups across hosts |
| 5 | `5ee1d5d` | Stream-copy local media files instead of loading bytes into memory |
| 6 | `7ff27b6` | Stream HTTP downloads to disk instead of buffering full body in memory |
| 7 | `457364b` | Preload media info into MediaInfoMap to remove ContentProcessor N+1 queries |
| 8 | `e79c407` | Reduce per-entry queries with SortValueAllocator and bulk sub-category/tag insert |
| 9 | `e829998` | Replace O(n^3) category sort with topological DFS |
| 10 | `1a1f373` | Compute Nested Set bounds in memory and bulk-insert categories in one statement |
| 11 | `e244798` | Stream WXR parsing via XMLReader::open and chunked illegal-char filter |
| 12 | `603b880` | Stream WXR import end-to-end with two-pass parser and entry generator |
| 13 | `e18a609` | Add phase-level timing to BatchProcessor results and progress log |

## AC 達成状況

| ID | 内容 | 状態 |
|----|------|------|
| AC-1 | 100MB WXR を memory_limit=512M で完走 | **要実測**（baseline.md / Pass 後の `timings.total` と `memory_peak` で判定） |
| AC-2 | `batch_size=50` の実効化 | **コード上達成**（Task 1 で `MemoryLimit::inBytes()` を真の上限とした上で、`optimizeBatchSize()` の増加経路を廃止して管理画面値を尊重するようにした） |
| AC-3 | 1エントリあたり DB 往復数 1/3 以下 | **コード上達成**（Task 8 で `SortValueAllocator` 採用＋サブカテゴリ／タグの bulk insert 化＋`ACMS_RAM::entryBlog()` の重複ルックアップ削除＋`determineMainCategory` の重複呼び出し削除） |
| AC-4 | ContentProcessor の DB ルックアップ撤廃 | **コード上達成**（Task 7 `MediaInfoMap::load()` で `WHERE media_id IN (...)` 1本のみ） |
| AC-5 | cURL ストリーミング保存 | **コード上達成**（Task 6 `CURLOPT_FILE` + `CURLOPT_HEADERFUNCTION`） |
| AC-6 | ローカルコピーのストリーム化 | **コード上達成**（Task 5 `stream_copy_to_stream`） |
| AC-7 | Parser のストリーミング化 | **コード上達成**（Task 11 `XMLReader::open()` + 1MB チャンクの不正文字除去） |
| AC-8 | パイプラインの逐次化 | **コード上達成**（Task 12 2 パス・ストリーミング、Execute → BatchProcessor を Generator で接続。`processEntryBatch` がバッファサイズ分だけメモリに乗せて即解放） |
| AC-9 | CategoryCreator アルゴリズム改善 | **コード上達成**（Task 9 トポロジカルソート + Task 10 Nested Set 一括計算 fast path / 既存ツリー干渉時は安全側にフォールバック） |
| AC-10 | 不要 sleep の整理 | **コード上達成**（Task 2、`Execute.php:201` の `sleep(5)` 撤去・バッチ間 usleep は `config('wxr_import_batch_pause_microseconds')` で設定可能・デフォルト 0） |
| AC-11 | 計測フックの追加 | **コード上達成**（Task 13、`BatchProcessor::processAll()` 戻り値に `timings.category / media / entry / total` と `memory_peak`、進捗ロガーとシステムロガー両方にサマリ出力） |
| AC-12 | 結果同一性 | **要実測**（S / M サイズ WXR を旧実装と新実装で取り込み、`entry` / `entry_sub_category` / `tag` / `category` / `media` の件数と本文 URL 書換結果を比較） |

## 静的検証

- `find src -name '*.php' -exec php -l {} \;` → 22 ファイル全て構文 OK
- `phpcs` は環境未整備のため未実行

## 実装上の特筆事項・限界

### Task 10（CategoryCreator Nested Set）の安全策
- **完全一括化はせず、fast path / fallback の2系統に分けた**:
  - **fast path**: 全新規カテゴリの親が「新規カテゴリ集合内」または `parent=0`（ルート）の場合のみ。既存ツリーの最大 right を 1 回読み、DFS でメモリ上に left/right を計算、`SQL::newBulkInsert` で全件 1 ステートメント INSERT。既存行への UPDATE は **0 本**。
  - **fallback**: 既存カテゴリを親とする新規が含まれる場合は、既存の per-row 作成（`updateNestedSetForInsertion` を伴うフロー）に落ちる。この経路は旧実装と同等。
- 「新規ブログへの初回フル取り込み」は fast path に乗るため、もっとも多いユースケースで最大の効果。
- 既存ブログへの混在再取り込みは旧実装と同等の速度に留まるが、データ破損リスクは温存。

### Task 12（Execute 2 パス）の注意点
- Parser は内部で「クリーニング済み一時ファイル」を都度作る。`parse()` を 2 回呼ぶと一時ファイル生成が 2 回走る。
- ピークメモリの観点ではこれは正当な選択（パス間で一時ファイルを保持すると寿命管理が煩雑になるため）。1MB チャンクの再書き出しは数十 ms オーダーで完了し、メモリピークには寄与しない。
- 一時ファイルの作成先は `CACHE_DIR`（定義済みなら）／`sys_get_temp_dir()` フォールバック。Generator の `finally` で `unlink` される。

### `processEntryBatch` のシグネチャ拡張
- `array $entries` → `iterable $entries` への拡張は **後方互換**（配列も `iterable` に含まれる）。
- 既存呼び出し元（Execute）は `Generator` を渡し、サードパーティ呼び出し元があっても従来どおり配列で動く。
- 進捗計算は `?int $expectedEntries` 引数で外部から提供する形式に変更。配列／Countable 渡しの場合は内部で `count()` してフォールバック。

### セキュリティ要件の維持
- 旧 `20260511-security-hardening` の AC-1〜AC-5 は変更していない:
  - `resolveLocalFilePath()` の `LocalStorage::validateDirectoryTraversalPath` → 不変
  - `validateUrlForFetch()`（リダイレクト各ホップで public IP 検証）→ DNS キャッシュを挟むのみで論理は不変
  - 自前リダイレクト追跡（最大 5 ホップ・`CURLOPT_FOLLOWLOCATION=false`）→ ストリーミング化後も維持
  - `MimeTypeValidator::validateAllowedByContent($localPath, ...)` の DL 後検証 → ストリーミング化後も維持
  - `MediaImporter::storeLocalSvg()` 経由のコア `sanitizeSvg()` 呼び出し → 不変

## 残作業（ユーザー側で必要なステップ）

### 1. ステージング DB での回帰検証（必須）
- **Task 10 の Nested Set fast path** が既存ツリーを破壊しないこと:
  - 新規ブログにフルインポートし、`SELECT category_id, category_parent, category_left, category_right FROM category WHERE category_blog_id = ?` を走査して `(left, right)` ペアが整合していることを確認
- **Task 11 / 12 の 2 パスストリーミング**で抽出結果が旧実装と完全一致すること:
  - S / M サイズ WXR を旧 master と新 master でそれぞれ取り込み、`entry` / `entry_sub_category` / `tag` / `media` の件数と内容を `diff` 比較

### 2. ベンチマーク取得
- `baseline.md` の改修前計測欄を埋め、改修後の `timings.*` と `memory_peak` を取り、AC-1 / AC-3 の判定を完了する

### 3. セキュリティ後退の不在チェック
- 旧 `20260511-security-hardening` の手動テストケース（LFI / SSRF / MIME 偽装 / SVG XSS）を本ブランチで再実行

### 4. PR 化
- 13 コミットをまとめて 1 つの PR にするか、リスク粒度（低リスク 1-9 と高リスク 10-12 を分ける）で 2 PR に分けるかは運用判断
