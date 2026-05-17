# タスクリスト: 設定値の階層整理実装

## 実行順序の方針

- **コード側を先に固める → フォーム HTML → ドキュメント** の順で進める。
- 1 タスク = 1 コミットを原則。
- 同一ファイル（Downloader.php / BatchProcessor.php / Execute.php）が複数タスクで触られるため、衝突しないよう以下の順を守る。
- 各コミット前に `php -l` を必ず実行。

---

## Task 1: `Downloader` 環境設定の `.env` 化（C-3, C-4 の前半）

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] コンストラクタ（L38-60）の `config('wxr_import_local_path_base')` ブロックを以下に置き換える:
      ```php
      $this->localPathBase = (string) env(
          'WXR_IMPORT_LOCAL_PATH_BASE',
          ARCHIVES_DIR . 'wxr-import/source/'
      );
      ```
- [ ] 同コンストラクタの `config('wxr_import_allowed_private_hosts')` ブロックを以下に置き換える:
      ```php
      $this->allowedPrivateHosts = $this->normalizeHostList(
          (string) env('WXR_IMPORT_ALLOWED_PRIVATE_HOSTS', '')
      );
      ```
- [ ] `private int $maxFileSize = 50 * 1024 * 1024;` のハードコード初期値を削除し、プロパティ宣言を `private int $maxFileSize;` に変更。コンストラクタで以下を追加:
      ```php
      $this->maxFileSize = (int) env('WXR_IMPORT_MAX_FILE_SIZE', '52428800');
      ```
- [ ] 同様に `private int $downloadDelay = 500000;` を `private int $downloadDelay;` に変更し、コンストラクタで以下を追加:
      ```php
      $this->downloadDelay = (int) env('WXR_IMPORT_DOWNLOAD_DELAY_MICROSECONDS', '500000');
      ```
- [ ] コンストラクタ末尾の `ensureDownloadDirectory()` / `ensureLocalPathBase()` 呼び出しはそのまま残す。
- [ ] `php -l src/Services/Media/Downloader.php` で構文チェック。
- [ ] コミット: `Read Downloader env-level settings via env() with defaults`

**完了条件**:
- `.env` 未設定でも従来通り `ARCHIVES_DIR . 'wxr-import/source/'` / 50MB / 500_000μs が使われる。
- `grep -n "config('wxr_import_" src/Services/Media/Downloader.php` が 0 件。

---

## Task 2: `Downloader::configure()` の責務縮小（AC-5）

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] `configure()` メソッド（L787-805）から `local_path_base` ブロックと `allowed_private_hosts` ブロックを削除。
- [ ] 残すのは `max_file_size` と `download_delay` のみ。
- [ ] PHPDoc コメントを以下のように更新:
      ```php
      /**
       * テストや特殊運用での上書き用フック。
       * 通常運用では .env 経由で値が入るためこのメソッドは不要だが、
       * 単体テスト等で一時的に値を差し替えたい場合のために残す。
       *
       * local_path_base / allowed_private_hosts は .env 専用とし、
       * configure() からは差し替え不可。
       *
       * @param array{
       *     max_file_size?: int,
       *     download_delay?: int,
       * } $config
       */
      ```
- [ ] `php -l src/Services/Media/Downloader.php`
- [ ] コミット: `Drop local_path_base/allowed_private_hosts from Downloader::configure()`

**完了条件**:
- `configure()` の引数配列のキーは `max_file_size` / `download_delay` のみ。
- `Downloader.php` 内で `local_path_base` / `allowed_private_hosts` を `configure()` 経由で代入する箇所が無い。

---

## Task 3: `BatchProcessor` の `config()` → `env()` 置換と `configure()` 呼び出し削除（C-3, AC-5）

**ファイル**: `src/Services/Import/BatchProcessor.php`

- [ ] `processAll()` 内の `$this->downloader->configure([...])` 呼び出し（旧 L107-111）を**まるごと削除**する。コメントとして「Downloader は .env から自前で取得済み」を1行残す:
      ```php
      // 0. Downloader はコンストラクタで .env から設定済み。configure() の追加呼び出しは不要。
      ```
- [ ] `processEntryBatch()` 内の `(int) (config('wxr_import_batch_pause_microseconds') ?: 0)` を以下に置き換え:
      ```php
      $pause = (int) env('WXR_IMPORT_BATCH_PAUSE_MICROSECONDS', '0');
      ```
- [ ] `grep -n "config('wxr_import_" src/Services/Import/BatchProcessor.php` が 0 件になることを確認。
- [ ] `php -l src/Services/Import/BatchProcessor.php`
- [ ] コミット: `Use env() for BatchProcessor pause and drop Downloader reconfigure step`

**完了条件**:
- `BatchProcessor.php` 内で `config('wxr_import_*')` の出現が 0 件。
- `processAll()` 内に `$this->downloader->configure(...)` の呼び出しが無い。

---

## Task 4: `Execute::getExecutionSettings()` の整理（C-1 のサーバ側, C-5）

**ファイル**: `src/POST/WxrImport/Execute.php`

- [ ] `getExecutionSettings()`（L106-118）を以下に置き換える:
      ```php
      private function getExecutionSettings(): array
      {
          // 業務判断はフォーム、環境固定設定は Downloader / BatchProcessor が .env を自前で読む。
          // batch_size のみ「フォーム > .env 既定」のハイブリッドを許す。
          return [
              'batch_size' => (int) ($this->Post->get('batch_size') ?: env('WXR_IMPORT_DEFAULT_BATCH_SIZE', '50')),
              'include_media' => $this->Post->get('include_media') === 'on',
              'create_categories' => $this->Post->get('create_categories') === 'on',
              'create_tags' => $this->Post->get('create_tags') === 'on',
              'target_blog_id' => BID,
          ];
      }
      ```
- [ ] PHPDoc の `@return array{...}` 部分から `local_path_base` / `allowed_private_hosts` を削除。
- [ ] `php -l src/POST/WxrImport/Execute.php`
- [ ] コミット: `Limit getExecutionSettings to business toggles and env-driven batch_size`

**完了条件**:
- 返り値配列のキーは `batch_size` / `include_media` / `create_categories` / `create_tags` / `target_blog_id` の5つのみ。
- `Execute.php` 内に `local_path_base` / `allowed_private_hosts` の文字列が出現しない（grep 0 件）。

---

## Task 5: 管理画面フォームから危険入力欄を削除（C-1 のクライアント側, AC-1）

**ファイル**: `src/template/admin/main.html`

- [ ] L285-336 付近の「詳細設定」`<details>` ブロックをまるごと削除する。具体的には:
  - `<th></th>` 直前の `<tr>` 開始から、`</details></td></tr>` までを削除
  - 直前の業務判断 `<tr>` の閉じタグ整合を確認
- [ ] 削除後の HTML が `<table>` 構造を壊していないかブラウザで目視確認（または `tidy -e` 等での構造チェック）。
- [ ] コミット: `Remove unsafe local_path_base/allowed_private_hosts inputs from admin form`

**完了条件**:
- `grep -n "local_path_base\|allowed_private_hosts" src/template/admin/main.html` が 0 件。
- 管理画面の「インポート」フォームを開いて、業務判断 3 チェックボックスとボタンだけが見える。

---

## Task 6: README.md に「設定階層」セクションを追加（AC-6）

**ファイル**: `README.md`

独立した `.env.example` ファイルは作らず、**README 内にコードブロックで直書き** する方針とする（`Dotenv` の読み込み先は `ablogcms/.env` で、プラグイン側に置いた `.env.example` は単純コピー対象にならないため、README 1 枚で完結させた方が UX が良い）。

- [ ] 既存の「Tips」セクションと「トラブルシューティング」セクションの間に「## 設定階層」を新設する。
- [ ] 内容:
  - 3 層モデル（`.env` / DB コンフィグ / 管理画面フォーム）を表で示す
  - 主要 `.env` キー（6 個）の早見表
  - design.md「C-6: ドキュメント整備」のコードブロックをそのまま貼る
  - `WXR_IMPORT_ALLOWED_PRIVATE_HOSTS` の本番運用注意（SSRF 防御）
- [ ] **未リリースのため Breaking Changes 節は作成しない**。
- [ ] コミット: `Document the .env-based configuration layer in README`

**完了条件**:
- README に「設定階層」セクションが存在し、`.env` キー一覧と本番運用注意が含まれている。
- リポジトリルートに `.env.example` ファイルは作成しない。

---

## Task 7: 最終チェック（AC-3 / AC-7）

**実装変更なし。検証のみ**:

- [ ] `grep -rn "config('wxr_import_\|config(\"wxr_import_" src/` が**0 件**であることを確認（C-3 / AC-3 達成）。
- [ ] `grep -rn "local_path_base\|allowed_private_hosts" src/` が `Downloader.php` / `Execute.php` のコメント以外で 0 件であることを確認（フォーム伝播の撤去確認）。
- [ ] `find src -name '*.php' -exec php -l {} \;` で全 PHP ファイル lint OK。
- [ ] 旧 `20260511-security-hardening` の LFI / SSRF テストケースを再実施し、`.env` 未設定で全て期待どおり拒否されることを確認（セキュリティ後退の不在）。
- [ ] 旧 `20260518-improve-performance` のフェーズ計測ログが `BatchProcessor::processAll()` 戻り値に出続けることを確認。
- [ ] `git log master --oneline` でコミット 6 本（Task 1-6）が積まれていることを確認。

**完了条件**: 上記全てパス。

---

## 補足: コミット粒度と衝突回避

主要ファイルの当たり方:

| ファイル | 触るタスク |
|----------|-----------|
| `src/Services/Media/Downloader.php` | Task 1, 2 |
| `src/Services/Import/BatchProcessor.php` | Task 3 |
| `src/POST/WxrImport/Execute.php` | Task 4 |
| `src/template/admin/main.html` | Task 5 |
| `README.md` | Task 6 |

Task 1 → 2 は同一ファイルだが触る場所が異なる（コンストラクタ vs `configure()`）。同じ順で進める限り衝突しない。

**特に注意**: Task 3 の `configure([...])` 呼び出し削除と Task 2 の `configure()` シグネチャ縮小は依存関係がある（先に呼び出し元の削除＝Task 3 完了後に Task 2 のシグネチャ変更を入れた方が、不要な引数渡しで PHP の警告が出るリスクを下げられる）。順序を **Task 1 → Task 3 → Task 2 → Task 4-6 → Task 7（検証）** とすることで、シグネチャ縮小時には誰も古い API を呼んでいない状態を保てる。タスクリストの上記順序はこのリスクを織り込んだ並びになっている。

各コミット前に `php -l` を必ず実行。
