# タスクリスト: セキュリティ対策実装

## 実行順序の方針

- 小さく独立しているものから先行（T-2 → T-1 → T-3 → T-4）。
- 1 タスク = 1 コミット を原則とする。
- T-5（SVG）は実装変更なし、確認のみ。

---

## Task 1: `unserialize` の安全化 (T-2)

**ファイル**: `src/Services/WXR/MediaExtractor.php`

- [ ] L76: `unserialize($metadataValue)` を
      `unserialize($metadataValue, ['allowed_classes' => false])` に変更
- [ ] `php -l src/Services/WXR/MediaExtractor.php` で構文チェック
- [ ] コミット: `Harden unserialize against PHP Object Injection in MediaExtractor`

**完了条件**: 修正後ファイルで `php -l` が通る。

---

## Task 2: ローカル取り込みパスのトラバーサル防止 (T-1)

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] `use Acms\Services\Facades\LocalStorage;` が既に存在することを確認
- [ ] プロパティ `private string $localPathBase;` を追加
- [ ] コンストラクタで初期化:
      ```php
      $this->localPathBase = (string)(config('wxr_import_local_path_base')
          ?: ARCHIVES_DIR . 'wxr-import/source/');
      if (!LocalStorage::exists($this->localPathBase)) {
          LocalStorage::makeDirectory($this->localPathBase);
      }
      ```
- [ ] `resolveLocalFilePath()` を全面書き換え:
  - `file://` 剥がしロジックは維持
  - `https?://` は即 null
  - パス組み立て後に `LocalStorage::validateDirectoryTraversalPath($path, $this->localPathBase, true)` を呼ぶ
  - true なら `@realpath($path)`、false なら警告ログを出して null
  - `DOCUMENT_ROOT` 相対も同じバリデータを通す
- [ ] `copyLocalFile()`:
  - `@file_get_contents($sourcePath)` を
    `LocalStorage::get($sourcePath, $this->localPathBase)` に置換
  - 戻り値 `false` で `['success' => false, 'error' => '...']`
- [ ] `php -l` で構文チェック
- [ ] コミット: `Restrict local path import to allowed base directory (LFI guard)`

**完了条件**:
- 許可ベース外パス指定で `resolveLocalFilePath()` が null を返す
- `config.server.php` 等の secret_file_name はベース配下にあっても拒否される

---

## Task 3: SSRF 防止 (T-3)

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] `download()` 内 cURL 設定を変更:
  - `CURLOPT_FOLLOWLOCATION => false`
  - `CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS`
  - `CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS`
- [ ] private メソッド `isPublicHost(string $host): bool` を追加:
  - `gethostbynamel($host)` で IP 解決
  - 各 IP を `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` で検証
  - 1 つでも非 public なら false
- [ ] private メソッド `validateUrlForFetch(string $url): bool` を追加:
  - スキームが http/https
  - ホスト名取得 → isPublicHost で検証
- [ ] `download()` ロジックを書き換え（リダイレクトを自前で最大 5 ホップ追跡）:
  - 各ホップ前に `validateUrlForFetch` を呼ぶ
  - `CURLOPT_HEADER => true` でヘッダ取得 → ヘッダ／ボディ分離
  - 3xx かつ `Location` で次ホップへ。`http_build_url` 相当で絶対 URL 化
  - 2xx でボディを返す。それ以外はエラー
  - 5 ホップ超過でエラー
- [ ] `php -l` で構文チェック
- [ ] コミット: `Restrict HTTP fetch to public hosts and inspect each redirect hop (SSRF guard)`

**完了条件**:
- `http://169.254.169.254/` 指定でエラー
- 公開 URL → 169.254 へのリダイレクトもエラー
- 公開 URL の正常画像は成功

---

## Task 4: コアの MIME バリデータに置換 (T-4)

**ファイル**: `src/Services/Media/Downloader.php`

- [ ] `use Acms\Services\Common\MimeTypeValidator;` を追加
- [ ] プロパティ `$allowedMimeTypes` 削除
- [ ] メソッド `isMimeTypeAllowed()` 削除
- [ ] `downloadMedia()` 内の DL 前 MIME 検証ブロック（L73-79）削除
- [ ] `configure()` の `allowed_mime_types` ハンドリングを削除（または no-op 維持）
- [ ] private メソッド `buildAllowedExtensions(): array` 追加:
      ```php
      return array_values(array_unique(array_merge(
          ['svg'],
          configArray('file_extension_image'),
          configArray('file_extension_document'),
          configArray('file_extension_archive'),
          configArray('file_extension_movie'),
          configArray('file_extension_audio')
      )));
      ```
- [ ] `downloadFile()` の保存直後に検証を追加:
      ```php
      $validator = new MimeTypeValidator();
      if (!$validator->validateAllowedByContent($localPath, $this->buildAllowedExtensions())) {
          LocalStorage::remove($localPath);
          return ['success' => false, 'error' => '許可されていないファイル形式です'];
      }
      ```
- [ ] `copyLocalFile()` も同様に検証
- [ ] `php -l` で構文チェック
- [ ] コミット: `Validate downloaded file MIME by content using core MimeTypeValidator`

**完了条件**:
- 拡張子 png だが中身 ZIP のファイルが拒否される
- 正常な jpg / png / pdf / svg は受理される

---

## Task 5: SVG サニタイズパスの確認 (T-5)

**実装変更なし。確認のみ**:

- [x] `MediaImporter::storeLocalSvg()` が `Media::storeFile(MEDIA_LIBRARY_DIR, ...)` を
      呼んでいることを再確認 → `src/Services/Import/MediaImporter.php:187-211` で確認
- [x] `Downloader::buildAllowedExtensions()` の戻り値に `'svg'` を明示的に含めた
      （`configArray('file_extension_image')` の構成有無に依らずカバー）

### 確認結果（SVG 取り込み経路）

```
Downloader::downloadFile()
  → LocalStorage::put() でファイル保存
  → validateStoredFile() → MimeTypeValidator::validateAllowedByContent($localPath, [..., 'svg'])
    → svg 拡張子が allowlist にあるため通過
  ↓
MediaImporter::importMedia()
  → prepareFileInfo() で LocalStorage::getMimeType($localPath) を取得（コンテンツベース）
  → mime_type が image/svg+xml なら storeLocalSvg() へ分岐
  ↓
Media::storeFile(MEDIA_LIBRARY_DIR, $tmp_name, $name, true)
  → コア側で MIME に 'svg' を含む場合 sanitizeSvg($dirty) を通してから
    PublicStorage::put($file, $clean) で保存
```

→ SVG ファイルはコアの `sanitizeSvg()` を必ず通る経路にあるため、
  プラグイン側で追加のサニタイズは不要。

---

## Task 6: 構文／lint チェックと最終確認

- [ ] `find src -name '*.php' -exec php -l {} \;` で全ファイル構文チェック
- [ ] `npx phpcs --standard=phpcs.xml src/` を実行（リント設定が存在する場合）
- [ ] `git diff master` で意図通りの差分のみが入っていることを確認
- [ ] 各コミットメッセージが規約に沿っていることを確認

---

## 補足: コミット粒度の見直し

タスク 1 件ごとに 1 コミットを推奨するが、もし Task 2 → Task 4 が互いに同じ
`Downloader.php` を触るため衝突しやすい場合は、以下の順で進めることで
コンフリクトを最小化する:

1. Task 1 (MediaExtractor.php) ← 独立
2. Task 2 (Downloader.php LFI)
3. Task 3 (Downloader.php SSRF) ← Task 2 と同一ファイルだが領域が異なる
4. Task 4 (Downloader.php MIME) ← 同上
5. Task 5 (確認のみ)
6. Task 6 (最終チェック)

各コミット前に `php -l` を必ず実行。
