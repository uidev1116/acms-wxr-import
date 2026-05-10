# 設計: メディアダウンロード／メディア抽出のセキュリティ対策

## 全体方針

- **a-blog cms コアが提供する API を優先利用する**。自前で同等機能を再実装しない。
- 各脅威対策は **独立してコミット可能** な粒度に分割（レビュー容易性のため）。
- 既存パブリック API（`Downloader::downloadMedia()`, `Downloader::configure()`,
  `MediaExtractor::extractMedia()`）のシグネチャは変更しない。
- 内部ヘルパーの整理（ハードコード allowlist の撤去）は伴うが、外部呼び出し点である
  `BatchProcessor` 等の修正は不要。

## 変更対象ファイル

| ファイル | 変更概要 |
|----------|----------|
| `src/Services/Media/Downloader.php` | LFI / SSRF / MIME の 3 対策を統合実装 |
| `src/Services/WXR/MediaExtractor.php` | `unserialize()` を 1 行修正 |

その他ファイルへの直接変更は無し。

---

## T-2: `unserialize` の安全化（最小差分）

### Before
```php
// src/Services/WXR/MediaExtractor.php:76
$metadata = unserialize($metadataValue);
```

### After
```php
$metadata = unserialize($metadataValue, ['allowed_classes' => false]);
```

### 影響範囲
- `$metadata` には配列・スカラのみが復元される。
- 既存呼び出し側で参照しているのは `$metadata['width']`, `$metadata['height']`,
  `$metadata['filesize']`, `$metadata['mime-type']`, `$metadata['sizes']` のみ。
  すべて配列・スカラのため、挙動上の差異は生じない。
- WordPress 本体の `maybe_unserialize` も実質同等の方針（コア側はクラス復元しない設計）。

---

## T-1: ローカル取り込みパスのディレクトリトラバーサル防止

### 採用するコア API

| API | 用途 |
|-----|------|
| `LocalStorage::validateDirectoryTraversalPath($path, $publicDir, $checkExists = true): bool` | 絶対化／`safeRealpath` 後にベース配下チェック。`secret_file_name` blocklist 付き |
| `LocalStorage::get($path, $publicDir): string\|false` | 内部で `validateDirectoryTraversalPath` を再走させたうえで `file_get_contents()` |

### 変更内容

#### コンストラクタ
- 許可ベースディレクトリ `$localPathBase` をプロパティ化。
- デフォルトは `ARCHIVES_DIR . 'wxr-import/source/'`。
- `config('wxr_import_local_path_base')` が設定されていれば上書き。
- ディレクトリ未存在時は作成する（`ensureDirectory()` 同様）。

#### `resolveLocalFilePath()`
- `file://` プレフィックスの剥がしロジックは現状維持。
- `https?://` は即 `null`。
- パスに対する `validateDirectoryTraversalPath($path, $this->localPathBase, true)` を実行。
  - true なら `realpath($path)` を返す。
  - false ならログ出力して `null`。
- `DOCUMENT_ROOT` 相対の場合は `DOCUMENT_ROOT` 結合後に同じバリデータを通す。
- 自前の `realpath` + `strpos` 比較ロジックは削除。

#### `copyLocalFile()`
- `@file_get_contents($sourcePath)` を `LocalStorage::get($sourcePath, $this->localPathBase)` に置換。
- 戻り値 `false` でエラー化。
- 既存のサイズチェック・`LocalStorage::put()` ロジックは維持。

### 影響範囲
- 許可ベース外のローカル絶対パス・`file://` URL・`DOCUMENT_ROOT` 相対は **全て拒否**。
- 既存ユーザーが運用上ローカルファイルを取り込んでいた場合、対象を許可ベース配下に配置する運用に変更が必要（README に追記が必要だが本タスク対象外）。

---

## T-3: SSRF 防止（プロトコル制限・リダイレクト自前化・到達先 IP 検証）

### 設計

#### `download()` の cURL 設定
- `CURLOPT_FOLLOWLOCATION` を `false` に。
- `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` を `CURLPROTO_HTTP | CURLPROTO_HTTPS` に限定。
- それ以外（タイムアウト・UA・SSL 検証・MAXFILESIZE）は維持。

#### リダイレクト追跡ループ
- 自前の関数 `fetchWithRedirectCheck(string $url, int $maxHops = 5): array` を `download()` 内に置く（private）。
- 各ホップで以下を実施:
  1. `validateUrl($url)` を呼ぶ。失敗で即終了。
  2. cURL リクエスト発行（`CURLOPT_HEADER => true` で 1 回だけヘッダ付き取得 → ボディとヘッダ分離）。
  3. HTTP ステータスが 3xx かつ `Location` ヘッダがある場合、相対 URL は絶対化したうえで次ホップへ。
  4. 2xx ならボディを返す。それ以外はエラー。
- 上限 5 ホップ。超過でエラー。

#### `validateUrl(string $url): bool` 仕様
- `parse_url` でスキームを取得。`http`/`https` 以外なら `false`。
- ホスト名（`PHP_URL_HOST`）を取得。空なら `false`。
- `gethostbynamel($host)` で A レコードを解決。空なら `false`。
- 各 IP に対し `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` で
  public IP 判定。1 つでも非 public があれば `false`。
- IPv6 については `gethostbynamel` が IPv4 のみ返す制限を許容する（IPv6 経路を使う環境ではフォロー実装で別途強化）。
- 全 IP が public なら `true`。

### 既知の限界（受け入れる）
- **DNS rebinding** に対する完全防御ではない（検証時と取得時で DNS 解決が走る）。
  完全防御は `CURLOPT_RESOLVE` による IP 固定が必要だが、本タスクでは
  「リダイレクト先含む全 URL のホスト名検証」までを到達点とする。
- IPv6 の private/loopback (`::1`, `fc00::/7`) は `gethostbynamel` 経由では検知しないため、
  将来 `dns_get_record($host, DNS_AAAA)` 統合を別タスクで検討。

### 影響範囲
- 公開 URL に対する正規のダウンロードは引き続き成功。
- プライベート IP・loopback・link-local（メタデータ）・予約レンジへの直接 URL はエラー。
- 公開 URL 経由でリダイレクトで内部に飛ばすパターンも、各ホップで検証されるためブロック。

---

## T-4: ダウンロード後の実 MIME 検証をコアに委譲

### 採用するコア API
- `Acms\Services\Common\MimeTypeValidator::validateAllowedByContent(string $path, array $allowedExtensions): bool`
- 内部で `finfo` + `exif_imagetype` で実 MIME を判定し、Symfony Mime で MIME → 拡張子集合に変換、
  許可拡張子と intersect。

### 変更内容

#### 削除
- プロパティ `$allowedMimeTypes`
- メソッド `isMimeTypeAllowed()`
- `downloadMedia()` 内の DL 前 MIME 検証ブロック
- `configure()` の `allowed_mime_types` ハンドリング（後方互換のため null 受理し no-op に）

#### 追加
- private メソッド `buildAllowedExtensions(): array`
  - 戻り値:
    ```php
    array_merge(
        configArray('file_extension_image'),
        configArray('file_extension_document'),
        configArray('file_extension_archive'),
        configArray('file_extension_movie'),
        configArray('file_extension_audio')
    );
    ```
- `downloadFile()` のファイル保存直後に検証:
  ```php
  $validator = new MimeTypeValidator();
  if (!$validator->validateAllowedByContent($localPath, $this->buildAllowedExtensions())) {
      LocalStorage::remove($localPath);
      return ['success' => false, 'error' => '許可されていないファイル形式です'];
  }
  ```
- `copyLocalFile()` も同様に保存直後に検証。

### 影響範囲
- DL 前の早期 MIME 拒否は無くなる（実 MIME 確定は DL 後にしかできないため）。
- 大量の不正ファイルを送りつけられた場合、無駄な転送が発生する可能性はあるが、
  サイズ上限 (50MB) とレート制限で実害は限定的。
- 拡張子 allowlist は `Media::storeFile()` 内の allowlist と同一構成のため、
  Downloader を通過した後に `MediaImporter` → `Media::storeFile()` でも再検証され二重防御となる。

---

## T-5: SVG サニタイズ（現状維持を保証）

### 確認事項（実装変更なし）
- `MediaImporter::storeLocalSvg()` (`src/Services/Import/MediaImporter.php:188-211`) が
  `Media::storeFile(MEDIA_LIBRARY_DIR, $fileInfo['tmp_name'], $fileInfo['name'], true)` を呼び出す。
- コア `Media::storeFile()` (`ablogcms/php/Services/Media/Helper.php:1630-1703`) は
  MIME に `svg` を含む場合 `sanitizeSvg($dirty)` を通してから `PublicStorage::put()` する。
- → SVG ファイルはコアのサニタイザ経由で保存されることが保証される。

### Downloader 側 allowlist
- `image/svg+xml` を受理する経路は T-4 の `buildAllowedExtensions()` 内に
  `configArray('file_extension_image')` 経由で `svg` が含まれている前提（要確認）。
- もし `file_extension_image` に `svg` が含まれない場合、`Media::storeFile()` 内の
  `['svg', ...]` 明示追加（Helper.php:1672-1678）に依存する形になり、
  Downloader の DL 後検証は通らなくなる。
- 設計上の安全策として、`buildAllowedExtensions()` の戻り値に `'svg'` を明示的に追加する
  （重複は intersect 側で吸収される）。

---

## 既存ロジックとの整合

### `Downloader::configure()` の後方互換
- 既存呼び出し側で `allowed_mime_types` を渡しているコードを念のため検索する。
  → 検索の結果存在しない場合は引数自体を削除、存在する場合は no-op で残す。
- `max_file_size`, `download_delay` は維持。

### ログ出力
- 既存の `Logger::error/warning('【WXRImport plugin】...', ...)` フォーマットを踏襲。
- 追加ログ:
  - LFI 拒否時: `warning` で `requested` / `resolved` / `base` を出力。
  - SSRF 拒否時: `warning` で `url` / `host` / `resolved_ips` を出力。
  - MIME 拒否時: `warning` で `path` / `sniffed_mime` を出力。

### エラー戻り値
- 既存 `['success' => false, 'error' => '...']` 形式を踏襲し、上位呼び出し側
  （`BatchProcessor`, `MediaImporter`）の変更は不要。

---

## テスト観点（手動／単体問わず）

1. **T-2**: 不正 serialized 文字列 `O:8:"stdClass":0:{}` を含む WXR で例外が出ないこと、
   `width` 等が 0 になるだけで処理が続行すること。
2. **T-1**:
   - `attachment_url = file:///etc/passwd` → 拒否
   - `attachment_url = /var/www/html/config.server.php` → 拒否（secret_file_name 含む）
   - `attachment_url = file:///{ARCHIVES_DIR}/wxr-import/source/sample.png` → 成功
   - `attachment_url = file:///{ARCHIVES_DIR}/wxr-import/source/../../../etc/passwd` → 拒否
3. **T-3**:
   - `http://169.254.169.254/...` → 拒否
   - `http://localhost:6379` → 拒否
   - `http://public.example.com/redirect?to=http://169.254.169.254` → 各ホップ検証で拒否
   - `https://example.com/normal.png` → 成功
4. **T-4**:
   - 拡張子 `.png` だが中身が ZIP のファイル → 実 MIME 不一致で拒否
   - 拡張子 `.svg` で中身が正常 SVG → 受理 → コアの sanitizeSvg 経由で保存
   - 拡張子 `.exe` → 拒否
5. **T-5**:
   - 悪意の `<script>alert(1)</script>` 入り SVG → 保存後にスクリプトが除去されている

---

## ロールバック計画

- 各対策は独立コミットのため、問題発生時は該当コミットを `git revert` で個別に戻せる。
- 特に T-1（ローカルパス制限）は既存ユーザーの運用に影響する可能性があるため、
  `config('wxr_import_local_path_base')` で運用調整可能としており、
  config 変更で対応可能な範囲を確保する。
