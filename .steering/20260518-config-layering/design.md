# 設計: 設定値の階層整理

## 全体方針

- **`env('KEY', $default)` と `config('key', $default)` の第二引数を必ず使う**。`?:` で if 文相当のフォールバックを書かない（既存コードの `is_string($configured) && $configured !== '' ? ...` のような手動フォールバックを撤去する）。
- **設定の意味で配置場所を決める**:
  - 環境固定（本番／開発で値が変わる、画面から触らせたくない、シークレットに準ずる）→ `.env`
  - ブログ単位で変えたい・UI 操作したい → `config()`
  - 業務判断（今回どうしたい）→ フォーム
- **既存パブリック挙動を温存**: `.env` を一切書かない環境でも、現行と完全に同じ既定値で動く。
- **各タスクは独立コミット可能**な粒度。

---

## 変更対象ファイル

| ファイル | 主な変更 |
|----------|----------|
| `src/Services/Media/Downloader.php` | 環境設定の読み出しを `env()` 第二引数つきに統一。`configure()` から `local_path_base` / `allowed_private_hosts` 引数を削除 |
| `src/Services/Import/BatchProcessor.php` | `config('wxr_import_batch_pause_microseconds')` を `env()` に置換。`downloader->configure([...])` の呼び出しを削除 |
| `src/POST/WxrImport/Execute.php` | `getExecutionSettings()` から `local_path_base` / `allowed_private_hosts` を削除し、`batch_size` の既定を `env('WXR_IMPORT_DEFAULT_BATCH_SIZE', '50')` に変更 |
| `src/template/admin/main.html` | `<details>` 詳細設定ブロック（`local_path_base` / `allowed_private_hosts` 入力欄）を削除 |
| `README.md` | 「設定階層」セクションを追加。`.env` のキー一覧とサンプルもコードブロックで直書きする |

---

## C-1, C-2: フォームから危険設定／環境固定設定を撤去

### Before（`src/template/admin/main.html:285-336`）

```html
<details>
  <summary>詳細設定（通常は変更不要）</summary>
  <table>
    <tr>
      <th>ベースディレクトリ</th>
      <td><input type="text" name="local_path_base" ...></td>
    </tr>
    <tr>
      <th>プライベート許可ホスト</th>
      <td><input type="text" name="allowed_private_hosts" ...></td>
    </tr>
  </table>
</details>
```

### After

詳細設定 `<details>` ブロックをまるごと削除し、その上の `<tr>` の閉じタグだけ整える（HTML 構造を壊さない範囲で）。POST されても無視される側を厳しくし、画面からも消す。

### Before（`src/POST/WxrImport/Execute.php:106-118`）

```php
private function getExecutionSettings(): array
{
    return [
        'batch_size' => (int)($this->Post->get('batch_size') ?: 50),
        'include_media' => $this->Post->get('include_media') === 'on',
        'create_categories' => $this->Post->get('create_categories') === 'on',
        'create_tags' => $this->Post->get('create_tags') === 'on',
        'target_blog_id' => BID,
        'local_path_base' => trim((string)$this->Post->get('local_path_base')),
        'allowed_private_hosts' => trim((string)$this->Post->get('allowed_private_hosts')),
    ];
}
```

### After

```php
private function getExecutionSettings(): array
{
    // 業務判断はフォーム、環境固定設定は Downloader / BatchProcessor 側で .env を直接読む。
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

`local_path_base` / `allowed_private_hosts` キーは settings 配列から消えるため、後段の `BatchProcessor::processAll()` が `$settings['local_path_base'] ?? ''` で参照していた `configure()` 呼び出しごと削除する（C-5 で扱う）。

---

## C-3, C-4: Downloader の `.env` 化

### Before（`src/Services/Media/Downloader.php:30, 33, 40-60`）

```php
private int $maxFileSize = 50 * 1024 * 1024;
private int $downloadDelay = 500000;

public function __construct()
{
    $this->downloadDir = ARCHIVES_DIR . 'wxr-import/media/';

    $configured = config('wxr_import_local_path_base');
    $this->localPathBase = is_string($configured) && $configured !== ''
        ? $configured
        : ARCHIVES_DIR . 'wxr-import/source/';

    $allowedHosts = config('wxr_import_allowed_private_hosts');
    if (!is_string($allowedHosts) && !is_array($allowedHosts)) {
        $allowedHosts = [];
    }
    $this->allowedPrivateHosts = $this->normalizeHostList($allowedHosts);

    $this->ensureDownloadDirectory();
    $this->ensureLocalPathBase();
}
```

### After

```php
private int $maxFileSize;
private int $downloadDelay;

public function __construct()
{
    $this->downloadDir = ARCHIVES_DIR . 'wxr-import/media/';

    // 環境固定値は .env から第二引数のデフォルトつきで読む
    $this->localPathBase = (string) env(
        'WXR_IMPORT_LOCAL_PATH_BASE',
        ARCHIVES_DIR . 'wxr-import/source/'
    );

    $this->allowedPrivateHosts = $this->normalizeHostList(
        (string) env('WXR_IMPORT_ALLOWED_PRIVATE_HOSTS', '')
    );

    $this->maxFileSize = (int) env('WXR_IMPORT_MAX_FILE_SIZE', '52428800');
    $this->downloadDelay = (int) env('WXR_IMPORT_DOWNLOAD_DELAY_MICROSECONDS', '500000');

    $this->ensureDownloadDirectory();
    $this->ensureLocalPathBase();
}
```

### `normalizeHostList()` の引数縮小

現状は `string|array` を受け付けているが、`env()` は常に string を返すため `string` 一本化できる。ただし `configure()` を残す関係でユニオン型は維持しても害は無いので維持する（API 安定性優先）。

### `configure()` の縮小（AC-5）

### Before（`src/Services/Media/Downloader.php:787-805`）

```php
public function configure(array $config): void
{
    if (isset($config['max_file_size'])) {
        $this->maxFileSize = max(1024, (int)$config['max_file_size']);
    }
    if (isset($config['download_delay'])) {
        $this->downloadDelay = max(0, (int)$config['download_delay']);
    }
    if (isset($config['local_path_base']) && is_string($config['local_path_base']) && $config['local_path_base'] !== '') {
        $this->localPathBase = $config['local_path_base'];
        $this->ensureLocalPathBase();
    }
    if (isset($config['allowed_private_hosts'])) {
        $this->allowedPrivateHosts = $this->normalizeHostList($config['allowed_private_hosts']);
    }
}
```

### After

```php
/**
 * テストや特殊運用での上書き用フック。
 * 通常運用では .env 経由で値が入るためこのメソッドは不要だが、
 * 単体テスト等で一時的に値を差し替えたい場合のために残す。
 *
 * local_path_base / allowed_private_hosts は本メソッドからは差し替え不可
 * （.env 専用）とすることで、フォーム経由での誤上書きを防ぐ。
 *
 * @param array{
 *     max_file_size?: int,
 *     download_delay?: int,
 * } $config
 */
public function configure(array $config): void
{
    if (isset($config['max_file_size'])) {
        $this->maxFileSize = max(1024, (int) $config['max_file_size']);
    }
    if (isset($config['download_delay'])) {
        $this->downloadDelay = max(0, (int) $config['download_delay']);
    }
}
```

---

## C-3: BatchProcessor の `.env` 化

### Before（`src/Services/Import/BatchProcessor.php:107-111, 383`）

```php
// processAll() 内
$this->downloader->configure([
    'local_path_base' => $settings['local_path_base'] ?? '',
    'allowed_private_hosts' => $settings['allowed_private_hosts'] ?? '',
]);

// processEntryBatch() 内
$pause = (int) (config('wxr_import_batch_pause_microseconds') ?: 0);
if ($pause > 0) {
    usleep($pause);
}
```

### After

```php
// processAll() 内: configure() 呼び出しを削除（Downloader が .env で自前取得済み）

// processEntryBatch() 内
$pause = (int) env('WXR_IMPORT_BATCH_PAUSE_MICROSECONDS', '0');
if ($pause > 0) {
    usleep($pause);
}
```

`processAll()` の `$this->downloader->configure([...])` は削除する。`$settings['local_path_base']` 等の参照が消えるため、`Execute` 側からの settings 配列縮小と整合する。

---

## C-6: ドキュメント整備（README インライン方式）

a-blog cms 標準の `.env` は `Dotenv\Dotenv::createImmutable(SCRIPT_DIR)->load()` で `SCRIPT_DIR/.env`（インストール先の `ablogcms/.env`）だけを読む。プラグイン側に独立した `.env.example` を置いてもユーザーがそこから直接コピーする運用にならず、README を見れば良い体験になる。

そのため **キー一覧と各キーの説明は README.md 内のコードブロックで直書き** する。独立した `.env.example` ファイルは作成しない。

README に追記するコードブロックの内容は次のとおり:

```
# ------------------------------------------------------------------
# WXRImport プラグインの環境設定
#
# 実環境では a-blog cms 設置ディレクトリの `.env` に追記してください。
# 本ファイルはキー一覧の参考資料です。
#
# どのキーも省略可能。省略時は WXRImport 内のデフォルトが使われます。
# ------------------------------------------------------------------

# ローカル取り込みの許可ベースディレクトリ。
# wp:attachment_url にローカルパス／file:// が指定された場合、ここで指定された
# ディレクトリ配下のファイルのみ読み込みを許可します（LFI 防御）。
# デフォルト: ARCHIVES_DIR . 'wxr-import/source/'
WXR_IMPORT_LOCAL_PATH_BASE=

# private/予約 IP に解決されるホストでも許可するホスト名のカンマ／空白区切り。
# 例: host.docker.internal,localhost
# 本番では必ず空欄のままにしてください（SSRF 防御を緩める設定です）。
# デフォルト: 空（厳格にブロック）
WXR_IMPORT_ALLOWED_PRIVATE_HOSTS=

# メディアファイルの最大サイズ（バイト）。
# php.ini の upload_max_filesize / post_max_size と整合する値を設定してください。
# デフォルト: 52428800（50MB）
WXR_IMPORT_MAX_FILE_SIZE=

# 同一ドメインへの連続ダウンロード間隔（マイクロ秒）。
# デフォルト: 500000（0.5 秒）
WXR_IMPORT_DOWNLOAD_DELAY_MICROSECONDS=

# エントリーバッチ間の追加ポーズ（マイクロ秒）。
# DB 負荷が高い環境で各バッチ後に休止を入れたい場合のみ設定してください。
# デフォルト: 0（無効）
WXR_IMPORT_BATCH_PAUSE_MICROSECONDS=

# 管理画面で batch_size を入力しなかった場合の既定値。
# デフォルト: 50
WXR_IMPORT_DEFAULT_BATCH_SIZE=
```

---

## README.md への追記内容

README に追加するセクション構成:

1. **「## 設定階層」**: フォーム／`.env`／`config()` の3層モデルを表で示し、「環境ごとに変わる」「画面から触らせたくない」「セキュリティに直結」は `.env`、業務判断はフォーム、と簡潔に明文化する。
2. **「### 利用可能な `.env` キー」**: 上記の「C-6: ドキュメント整備」のコードブロックを、主要キーの早見表（テーブル）と一緒に直書きする。
3. **`WXR_IMPORT_ALLOWED_PRIVATE_HOSTS` の本番運用注意**: SSRF 防御を緩める設定であるため本番では空欄のままにする旨を明記。

**未リリースのため Breaking Changes 節は作成しない**。本プラグインは正式リリース前のためユーザーが旧 `config('wxr_import_*')` キーで運用している前提を取らない。

---

## 既存ロジックとの整合

### 性能改修（`20260518-improve-performance`）との関係
- `BatchProcessor` の sleep 解消（旧 Task 2）で導入した `config('wxr_import_batch_pause_microseconds')` を本タスクで `env()` に差し替える。デフォルト 0 の挙動は不変。
- 他の性能改修箇所には触らない。

### セキュリティ改修（`20260511-security-hardening`）との関係
- `resolveLocalFilePath()` / `validateUrlForFetch()` の防御ロジックは不変。設定値の取得経路だけが変わる。
- `allowed_private_hosts` を画面から消すことは AC-3（SSRF 防御）を**強化する方向**の変更であり、後退はない。

### `Downloader::configure()` の互換性
- 旧シグネチャ `array{max_file_size?, download_delay?, local_path_base?, allowed_private_hosts?}` から後者2つを削除する。`BatchProcessor::processAll()` 以外に呼び出し元がないことを `grep` で確認してから削除する。

---

## テスト観点

### 1. 既定値での後方互換
- `.env` に `WXR_IMPORT_*` のキーを**一切書かない**状態で取り込みが完走する。挙動は改修前と完全に同一（ピーク負荷・経路・成否すべて）。

### 2. `.env` で各値を上書きしたとき
- `WXR_IMPORT_LOCAL_PATH_BASE=/var/import-source/` を設定すると、`wp:attachment_url=/var/import-source/foo.png` が許可される。
- `WXR_IMPORT_ALLOWED_PRIVATE_HOSTS=host.docker.internal` を設定すると、`http://host.docker.internal/...` の取得が成功する（開発環境想定）。空欄では拒否される。
- `WXR_IMPORT_MAX_FILE_SIZE=10485760`（10MB）を設定すると、10MB 超のファイルが拒否される。
- `WXR_IMPORT_BATCH_PAUSE_MICROSECONDS=200000` を設定すると、バッチ間で約 0.2 秒の sleep が観測される。

### 3. フォームからの注入耐性
- POST に `local_path_base=/etc/passwd` / `allowed_private_hosts=evil` を強制的に含めてリクエストしても、`Execute::getExecutionSettings()` がこれらを参照しないため Downloader の挙動には影響しない（**画面で削除済み + サーバ側でも読み取らない** の二重防御）。

### 4. セキュリティ後退の不在
- 旧 `20260511-security-hardening` の LFI / SSRF / MIME 偽装テストケースを再実施し、すべて期待どおり拒否されることを確認。

---

## ロールバック計画

- 各タスクは独立コミット。問題発生時は粒度単位で `git revert`。
- `.env.example` / README の追加は文字通り「追加」のみで挙動には影響しないため、コードコミットと分離して扱う。
- `Downloader::configure()` の API 縮小は呼び出し元（`BatchProcessor::processAll()`）が `configure()` を呼ばなくなった後に行うことで、PHP のメソッドシグネチャ不整合（不要引数渡し）を発生させない（tasklist.md の実行順序 1→3→2 で担保）。
- 未リリースのため旧 `config('wxr_import_*')` 経路のフォールバックは不要。コード側で旧キーを読む実装は残さない。
