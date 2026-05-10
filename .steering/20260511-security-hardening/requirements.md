# 要求内容: メディアダウンロード／メディア抽出のセキュリティ対策

## 背景

`WXRImport` プラグインは管理者が外部から受領した WXR XML を取り込む性質上、
**XML 内に出現する値（attachment_url, postmeta など）は信頼できない外部入力** として扱う必要がある。
にもかかわらず、現在の実装は以下の系統で攻撃面を残している。

- `Services/Media/Downloader.php` におけるローカルファイル解決・HTTP 取得
- `Services/WXR/MediaExtractor.php` における `unserialize()`

実運用想定上、移行元が完全に信頼できるとは限らず（移行代行・他社受領 WXR 等）、
管理者操作だけを前提にした「内部信頼」モデルは脆い。

## 解決したい課題（脅威）

| ID | 脅威 | 該当箇所 | 影響 |
|----|------|----------|------|
| T-1 | ローカルファイル読み出し (LFI) | `Downloader::resolveLocalFilePath()` / `copyLocalFile()` | `config.server.php` 等の機密ファイルがメディアライブラリ（公開領域）に流出 |
| T-2 | PHP Object Injection | `MediaExtractor::extractFileInfo()` の `unserialize()` | Composer 依存に POP gadget があれば RCE |
| T-3 | SSRF（内部ネットワーク／クラウドメタデータ到達） | `Downloader::download()` の cURL 設定 | IAM 一時クレデンシャル等の窃取 |
| T-4 | MIME 宣言値依存 | `Downloader::isMimeTypeAllowed()` | 攻撃者制御の WXR 申告値で allowlist を素通り |
| T-5 | （リファレンス確認のみ） SVG 由来 Stored XSS | `Downloader::$allowedMimeTypes` に `image/svg+xml` | 実装上は `Media::storeFile()` の `sanitizeSvg()` で守られている前提を維持 |

## 受け入れ条件（Acceptance Criteria）

### AC-1: ローカル取り込みは「許可ベースディレクトリ配下」のみ
- WXR の `<wp:attachment_url>` がローカルパス／`file://` URL を指していても、
  許可ベースディレクトリの **realpath 配下に着地しない** ものは拒否される。
- 許可ベースディレクトリは config で上書き可能。デフォルトは `ARCHIVES_DIR . 'wxr-import/source/'`。
- `config.server.php`, `.env`, `.htaccess` 等の **コア側 `secret_file_name` blocklist** が適用される。
- 検証はコアの `Storage::validateDirectoryTraversalPath()` を利用する（自前実装を作らない）。
- 読み込みは `LocalStorage::get($path, $publicDir)` 経由（多重防御）。

### AC-2: `unserialize` のクラス復元を禁止
- `MediaExtractor::extractFileInfo()` 内の `unserialize($metadataValue)` を
  `unserialize($metadataValue, ['allowed_classes' => false])` に変更。
- 配列・スカラのみ復元され、PHP オブジェクト復元による gadget chain は不可能となる。

### AC-3: HTTP ダウンロードのスキーム／リダイレクト／到達先の制限
- cURL の `CURLOPT_PROTOCOLS` と `CURLOPT_REDIR_PROTOCOLS` を `http` / `https` のみに制限。
- `CURLOPT_FOLLOWLOCATION` は無効化し、**自前で最大 5 ホップまでリダイレクト追跡**。
  各ホップで以下を実施する:
  - URL スキームが `http` / `https` であること
  - ホスト名を DNS 解決し、すべての解決 IP が **public IP** であること
    （`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`）
- 検証に失敗した場合はエラーとして即終了し、ファイル保存しない。

### AC-4: ダウンロード後の実 MIME 検証をコアの `MimeTypeValidator` に委譲
- `Downloader::$allowedMimeTypes` ハードコード allowlist と `isMimeTypeAllowed()` を廃止。
- 許可拡張子は `configArray('file_extension_image' | 'document' | 'archive' | 'movie' | 'audio')`
  の合算で構築（`Media::storeFile()` 内の allowlist と整合）。
- ダウンロード or ローカルコピー完了後に
  `MimeTypeValidator::validateAllowedByContent($localPath, $allowedExtensions)` を実行。
  違反時は **ファイルを削除してエラー** とする。
- DL 前の WXR 申告値ベースの MIME チェックは廃止（信用しない）。

### AC-5: SVG は引き続き許可。サニタイズはコアに委譲（現状維持）
- `MediaImporter::storeLocalSvg()` → `Media::storeFile(MEDIA_LIBRARY_DIR, ...)` 経路で
  コアの `sanitizeSvg()` が走ることに依存する。
- 本対応では SVG 個別の対策は追加せず、上記前提が崩れていないことを設計フェーズで確認する。

## 制約事項

- 後方互換: 既存ユーザーの WXR インポート画面の UI は変更しない。
- config 追加は最小限。デフォルト値で動く（追加設定なしで安全）こと。
- 既存ログ出力フォーマット（`【WXRImport plugin】...`）を踏襲する。
- バグ修正・セキュリティ修正という性質上、新機能は加えない（リファクタも最小限）。

## 対象外（Out of Scope）

- XML パーサ (`Services/WXR/Parser.php`) の XXE 対策確認（別タスク）
- メディアライブラリ配下の `.htaccess` による PHP 実行禁止確認（インフラ側）
- 認可（`sessionWithAdministration()`）の強化
- 本文 `<img>` の外部 URL 一括取り込み機能の追加（要件外）
