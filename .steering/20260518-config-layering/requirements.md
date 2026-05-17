# 要求内容: 設定値の階層整理（`.env` / `config()` / フォーム の責務分離）

## 背景

`WXRImport` プラグインの設定値は、現在以下の3経路がごちゃ混ぜになっている。

| 設定 | 現状の経路 | 問題点 |
|------|------------|--------|
| `local_path_base` | フォーム入力 → 空なら `config('wxr_import_local_path_base')` → 空なら `ARCHIVES_DIR . 'wxr-import/source/'` | 環境ごとに変わるパスをフォームで毎回入力させる UX。タイプミスのリスク |
| `allowed_private_hosts` | フォーム入力 → 空なら `config('wxr_import_allowed_private_hosts')` | **SSRF 防御を緩めるセキュリティ設定がフォームから有効化できる**。本番に開発設定 (`host.docker.internal`) が混入する事故が起こりうる |
| `wxr_import_batch_pause_microseconds`（直近 `20260518-improve-performance` で追加） | `config('wxr_import_batch_pause_microseconds')` | 環境固定が自然な値を、ブログ単位のコンフィグから読んでいる |
| `max_file_size` | プロパティ初期値 50MB のハードコード、`Downloader::configure()` でのみ上書き可 | 環境ごとに変えたい値（`upload_max_filesize` と整合）が `.env` から制御できない |
| `download_delay` | プロパティ初期値 500_000μs のハードコード、`Downloader::configure()` でのみ上書き可 | 同上 |
| `batch_size` | フォーム入力 → 空なら 50 ハードコード | フォーム HTML 未掲載のため事実上ハードコード固定。環境ごとの既定が指定できない |

a-blog cms 標準で `.env`（`vlucas/phpdotenv`）と `env(string $key, string $default = '')` が `ablogcms/php/standalone.php:38` で提供されていることが判明したため、これらを利用して **「環境固定」 vs 「ブログごとに永続」 vs 「都度入力」** の3層を明示的に分離する。

## a-blog cms における設定階層モデル

| 層 | API | 寿命 | 使いどころ |
|----|-----|------|------------|
| (1) `.env` | `env('KEY', $default)` | デプロイ単位 | 環境ごとに変わる／本番に開発設定を混入させたくない／画面から触らせたくない |
| (2) `config.server.php` | `define()` 定数 | デプロイ単位 | DSN・LIB_DIR 等の低レベル定数（本タスクでは不使用） |
| (3) DB コンフィグ | `config('key', $default)` | ブログ単位（永続） | UI から運用者が触る／ブログごとに違う値 |
| (4) フォーム | `$this->Post->get()` | 1 リクエスト | その回限りの**業務判断** |

`env()` も `config()` も**第二引数にデフォルト値を取れる**ため、最終フォールバックを引数として明示すればフォールバック分岐の if 文を書かなくて済む。本タスクではこの記法を一貫して採用する。

## 解決したい課題

| ID | 課題 | 該当箇所 | 影響 |
|----|------|----------|------|
| C-1 | `allowed_private_hosts` がフォームから有効化できる | `src/POST/WxrImport/Execute.php:115-116`, `src/template/admin/main.html:317-325` | 本番環境で誤って SSRF 防御を緩める事故が起こりうる |
| C-2 | `local_path_base` のような環境固定値を毎回入力させる UX | `src/template/admin/main.html:296-310` | タイプミスで取り込み失敗 |
| C-3 | `batch_pause_microseconds` を `config()` から読んでいる | `src/Services/Import/BatchProcessor.php:383` | 環境固定の値が「ブログ単位の設定」になっていて意味的にずれている |
| C-4 | `max_file_size` / `download_delay` を `.env` から制御できない | `src/Services/Media/Downloader.php:30, 33` | 環境ごとに変えたいサーバ寄りの値がコードに埋まっている |
| C-5 | `batch_size` の既定値（50）が環境ごとに上書きできない | `src/POST/WxrImport/Execute.php:109` | サーバの体力に応じた既定値を `.env` で固定できない |
| C-6 | 設定経路がフォーム / config / ハードコードに分散しドキュメントもない | プラグイン全体 | 運用者がどこをどう触れば何が変わるか分からない |

## ユーザーストーリー

- 運用者として、**本番サーバの `.env` に `WXR_IMPORT_LOCAL_PATH_BASE` を1度書けば**、移行作業のたびに長いパスを入力し直さずに済むようにしたい。
- 運用者として、**`allowed_private_hosts` のような危険設定が管理画面から見えない／触れない**ことで、誤って本番で開発用ホストを許可する事故を防ぎたい。
- 運用者として、**1ファイル（`.env`）を見ればプラグインの環境固定設定が全て分かる**ようにしたい。
- 移行担当者として、フォーム画面は「**今回どう取り込むか**」の業務判断（メディア取得・カテゴリ作成・タグ作成）に集中したい。

## 受け入れ条件（Acceptance Criteria）

### AC-1: フォームは業務判断専用にする
- `src/template/admin/main.html` の「詳細設定」`<details>` ブロック（`local_path_base` / `allowed_private_hosts` の入力欄）を**削除**する。
- 残るフォーム項目は `include_media` / `create_categories` / `create_tags` の3チェックボックスのみ（業務判断）。
- POST 経由で `local_path_base` / `allowed_private_hosts` が送られても**`Execute::getExecutionSettings()` では受け取らない**（クライアントが手動で POST しても無視）。

### AC-2: 環境固定設定は `.env` から読む
- 以下のキーを `env('KEY', $default)` で読み、第二引数で **適切なデフォルト** を与える。`config()` 経由の旧読み出しは廃止する。

| .env キー | 型 | デフォルト（第二引数） | 適用箇所 |
|-----------|-----|----------------------|----------|
| `WXR_IMPORT_LOCAL_PATH_BASE` | string | `ARCHIVES_DIR . 'wxr-import/source/'` | `Downloader::$localPathBase` の初期化 |
| `WXR_IMPORT_ALLOWED_PRIVATE_HOSTS` | string（カンマ／空白区切り） | `''`（誰も許可しない＝厳格） | `Downloader::$allowedPrivateHosts` の初期化 |
| `WXR_IMPORT_MAX_FILE_SIZE` | int（バイト） | `52428800`（50MB） | `Downloader::$maxFileSize` の初期化 |
| `WXR_IMPORT_DOWNLOAD_DELAY_MICROSECONDS` | int（マイクロ秒） | `500000`（0.5 秒） | `Downloader::$downloadDelay` の初期化 |
| `WXR_IMPORT_BATCH_PAUSE_MICROSECONDS` | int（マイクロ秒） | `0`（無効） | `BatchProcessor` バッチ間ポーズ |
| `WXR_IMPORT_DEFAULT_BATCH_SIZE` | int | `50` | `Execute::getExecutionSettings()` の batch_size 既定値 |

### AC-3: `config('wxr_import_*')` 呼び出しを廃止する
- `src/Services/Media/Downloader.php:52`（`wxr_import_local_path_base`）→ `env()` に置換。
- `src/Services/Media/Downloader.php:60`（`wxr_import_allowed_private_hosts`）→ `env()` に置換。
- `src/Services/Import/BatchProcessor.php:383`（`wxr_import_batch_pause_microseconds`）→ `env()` に置換。
- どのキーも `config()` 経由では参照しない（コードベース全体から `config('wxr_import_*')` を grep して 0 件にする）。

### AC-4: `batch_size` のハイブリッド既定
- `Execute::getExecutionSettings()` で `batch_size` の解決順を **「フォーム入力 → `env('WXR_IMPORT_DEFAULT_BATCH_SIZE', '50')` 」** とする。
- 本ステアリングでは UI フォームに `batch_size` 入力欄は追加しない（既存仕様維持）。将来追加された場合に上書きが効くようにする下準備のみ。

### AC-5: `Downloader::configure()` の責務縮小
- `local_path_base` / `allowed_private_hosts` を `configure()` 引数から削除する。
- `max_file_size` / `download_delay` は `configure()` で受け取れる仕様を残す（テスト容易性のため）。ただし運用ではコンストラクタの `env()` 読み出しが効くため、`configure()` 経由の上書きは特殊用途扱い。
- `BatchProcessor::processAll()` 内の `$this->downloader->configure([...])` 呼び出し（旧 `local_path_base` / `allowed_private_hosts` のフォーム伝播）を**削除**する。

### AC-6: ドキュメント整備
- リポジトリルートに `.env.example` を新設し、上記6キーをコメント付きで記載する。
- `README.md` に「設定階層」セクションを追加し、フォーム／`.env` / `config()` の使い分け方針を1段落で明文化する。

### AC-7: ロールバック容易性
- 各タスクは独立してコミット可能とし、問題発生時に粒度単位で revert できるようにする。
- 本プラグインは未リリースのため、旧 `config('wxr_import_*')` キーから新 `WXR_IMPORT_*` への移行ガイドは作成しない（ユーザーが本番運用していない前提）。

## 制約事項

- **既存の挙動を変えない範囲を守る**: デフォルト値（50MB / 0.5s / 50 / 0 等）は現行と完全一致させる。`.env` を一切書かなくても旧来通り動作する。
- セキュリティ要件（旧 `20260511-security-hardening` の AC-1〜AC-5）は引き続き満たす。`allowed_private_hosts` の管理経路を変えるだけで防御ロジックには手を入れない。
- 性能改修（`20260518-improve-performance`）の結果を後退させない。`BatchProcessor` の `env('WXR_IMPORT_BATCH_PAUSE_MICROSECONDS', '0')` 読み出しは、デフォルト 0 でバッチ間 sleep が発生しない挙動を維持する。
- フォーム HTML から削除した入力欄に対して、サーバ側で「POST されても無視」する形にする（POST 自体を弾く検証は追加しない＝攻撃面が広がらない範囲で済ませる）。

## 対象外（Out of Scope）

- `batch_size` の UI 入力欄追加（フォーム HTML 拡張）。本ステアリングは設定階層整理に閉じ、UI 改善は別ステアリング。
- ブログごとに値を変えたい設定（`config()` 経由）の新設。現状の WXRImport プラグインに per-blog の固有値は無いため、今回は `env()` への一本化に留める。
- a-blog cms コア側の `config()` 関数仕様の改修（外部 API）。
- `.env` を編集する管理画面（GUI）。`.env` はサーバ管理者が SSH 等で編集する前提を維持。
- 既存 `config('main_image_field_name', ...)` のようなコア側設定への手出し。
