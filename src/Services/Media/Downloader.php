<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Media;

use Acms\Services\Facades\Logger;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Common;
use Acms\Services\Common\MimeTypeValidator;
use Acms\Plugins\WxrImport\Services\WXR\WXRMedia;

/**
 * WordPressメディアファイルのダウンロード機能
 */
class Downloader
{

    /** @var string ダウンロードディレクトリのベースパス */
    private string $downloadDir;

    /** @var string ローカル取り込みの許可ベースディレクトリ（この配下のみ受理） */
    private string $localPathBase;

    /** @var array<int, string> private IP に解決されても許可するホスト名（lowercase 比較） */
    private array $allowedPrivateHosts = [];


    /** @var int 最大ファイルサイズ（バイト） */
    private int $maxFileSize;

    /** @var int ダウンロード間隔（マイクロ秒） */
    private int $downloadDelay;

    /** @var array<string, float> ドメイン別の最後のアクセス時刻 */
    private static array $lastAccessTimes = [];

    /**
     * ホスト名 → 解決済み IP のキャッシュ。
     * インポート1回（プロセス1回）の寿命だけ保持し、同一ホスト URL の DNS 解決を都度走らせない。
     *
     * @var array<string, list<string>|false>
     */
    private static array $dnsCache = [];

    public function __construct()
    {
        $this->downloadDir = ARCHIVES_DIR . 'wxr-import/media/';

        // 環境固定値は .env から第二引数のデフォルトつきで読む。
        // ablogcms/.env に WXR_IMPORT_* キーを書けば上書きできる。

        // ローカル取り込みの許可ベース。
        $this->localPathBase = (string) env(
            'WXR_IMPORT_LOCAL_PATH_BASE',
            ARCHIVES_DIR . 'wxr-import/source/'
        );

        // 開発環境向けに、private 解決されても許可するホスト名を opt-in で設定可能にする。
        // 例: WXR_IMPORT_ALLOWED_PRIVATE_HOSTS=host.docker.internal,localhost
        // 本番は空欄のまま（SSRF 対策を厳格に保つ）。
        $this->allowedPrivateHosts = $this->normalizeHostList(
            (string) env('WXR_IMPORT_ALLOWED_PRIVATE_HOSTS', '')
        );

        // メディア最大サイズ（バイト）。デフォルト 50MB。
        $this->maxFileSize = (int) env('WXR_IMPORT_MAX_FILE_SIZE', '52428800');

        // 同一ドメインへの連続ダウンロード間隔（マイクロ秒）。デフォルト 0.5 秒。
        $this->downloadDelay = (int) env('WXR_IMPORT_DOWNLOAD_DELAY_MICROSECONDS', '500000');

        $this->ensureDownloadDirectory();
        $this->ensureLocalPathBase();
    }

    /**
     * メディアファイルをダウンロード
     *
     * @param WXRMedia $media
     * @return array{
     *     success: bool,
     *     local_path?: string,
     *     file_name?: string,
     *     file_size?: int,
     *     error?: string
     * }
     */
    public function downloadMedia(WXRMedia $media): array
    {
        if (!$media->isDownloadable()) {
            return [
                'success' => false,
                'error' => 'メディアファイルがダウンロード可能ではありません'
            ];
        }

        // 実MIMEはダウンロード／コピー後にコアのMimeTypeValidatorで検証する。
        // WXR内のmime-type宣言値は攻撃者が制御できるため信頼しない。

        try {
            // ダウンロード先パスを生成
            $localPath = $this->generateLocalPath($media);

            // 既にダウンロード済みの場合はスキップ
            if (LocalStorage::exists($localPath)) {
                // メディアファイルは既に存在

                return [
                    'success' => true,
                    'local_path' => $localPath,
                    'file_name' => basename($localPath),
                    'file_size' => (int)filesize($localPath),
                ];
            }

            // wp:attachment_url をローカルパスに置換している場合はコピー、それ以外はHTTPダウンロード
            $sourcePath = $this->resolveLocalFilePath($media->originalUrl);
            if ($sourcePath !== null) {
                $downloadResult = $this->copyLocalFile($sourcePath, $localPath);
            } else {
                // レート制限を適用
                $this->applyRateLimit($media->originalUrl);
                $downloadResult = $this->downloadFile($media->originalUrl, $localPath);
            }


            if (!$downloadResult['success']) {
                return $downloadResult;
            }


            return $downloadResult;

        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】メディアダウンロードエラー', Common::exceptionArray($th, [
                'wp_post_id' => $media->wpPostId,
                'url' => $media->originalUrl
            ]));

            return [
                'success' => false,
                'error' => 'ダウンロード中にエラーが発生しました: ' . $th->getMessage()
            ];
        }
    }

    /**
     * wp:attachment_url がローカルパス（または file://）かどうかを判定し、
     * 許可ベースディレクトリ配下の実在ファイルである場合に限り絶対パスを返す。
     *
     * XML 内の wp:attachment_url をローカルファイルパスに置換した場合に使用。
     * 例: /Users/foo/Downloads/image.jpg や file:///path/to/file.jpg
     *
     * ベース配下チェックはコアの LocalStorage::validateDirectoryTraversalPath() に委譲する。
     * これにより realpath 正規化・接頭辞照合に加え、secret_file_name（config.server.php /
     * .env / .htaccess など）の blocklist も適用される。
     *
     * @param string $urlOrPath 元のURL、またはローカルパス／file:// URL
     * @return string|null 許可ベース配下にある実在ファイルの絶対パス。それ以外は null
     */
    private function resolveLocalFilePath(string $urlOrPath): ?string
    {
        $path = $urlOrPath;
        if (str_starts_with($path, 'file://')) {
            $path = substr($path, 7);
            // file:///C:/foo -> C:/foo（Windows）、file:///path -> /path（Unix）
            if (strlen($path) >= 3 && $path[0] === '/' && ctype_alpha($path[1]) && $path[2] === ':') {
                $path = substr($path, 1);
            } elseif (strlen($path) >= 1 && $path[0] !== '/') {
                $path = '/' . $path;
            }
        } elseif (preg_match('#^https?://#i', $path)) {
            return null;
        }
        $path = str_replace('\\', '/', $path);

        // 直接パス、または DOCUMENT_ROOT 相対の 2 系統を、いずれも
        // コアの validateDirectoryTraversalPath() で許可ベース配下かチェックする。
        $candidates = [$path];
        if (str_starts_with($path, '/') && defined('DOCUMENT_ROOT')) {
            $candidates[] = rtrim(DOCUMENT_ROOT, '/') . $path;
        }

        foreach ($candidates as $candidate) {
            if (LocalStorage::validateDirectoryTraversalPath($candidate, $this->localPathBase, true)) {
                $resolved = LocalStorage::safeRealpath($candidate);
                if ($resolved !== false && $resolved !== '') {
                    return $resolved;
                }
            }
        }

        Logger::warning('【WXRImport plugin】許可外のローカルパスを拒否', [
            'requested' => $urlOrPath,
            'base' => $this->localPathBase,
        ]);
        return null;
    }

    /**
     * ローカルファイルをダウンロード用ディレクトリにコピー
     *
     * @param string $sourcePath コピー元の絶対パス
     * @param string $localPath コピー先（プラグインのダウンロードディレクトリ内）
     * @return array{
     *     success: bool,
     *     local_path?: string,
     *     file_name?: string,
     *     file_size?: int,
     *     error?: string
     * }
     */
    private function copyLocalFile(string $sourcePath, string $localPath): array
    {
        // セキュリティ前提:
        //   呼び出し元の resolveLocalFilePath() が LocalStorage::validateDirectoryTraversalPath()
        //   を通過させたうえで safeRealpath を得ているため、$sourcePath は許可ベース配下の
        //   実在ファイルであることが保証されている。本関数では実コピーだけをストリームで行い、
        //   保存後に validateStoredFile() で実 MIME を再検証することで多重防御を維持する。
        try {
            $dir = dirname($localPath);
            if (!LocalStorage::exists($dir)) {
                if (!LocalStorage::makeDirectory($dir)) {
                    return [
                        'success' => false,
                        'error' => 'ディレクトリの作成に失敗しました: ' . $dir,
                    ];
                }
            }

            $src = @fopen($sourcePath, 'rb');
            if ($src === false) {
                return [
                    'success' => false,
                    'error' => 'ローカルファイルを開けませんでした: ' . $sourcePath,
                ];
            }
            $dst = @fopen($localPath, 'wb');
            if ($dst === false) {
                fclose($src);
                return [
                    'success' => false,
                    'error' => 'コピー先を開けませんでした: ' . $localPath,
                ];
            }

            // maxFileSize+1 までコピー。超過していたら破棄してエラー。
            $copied = @stream_copy_to_stream($src, $dst, $this->maxFileSize + 1);
            fclose($src);
            fclose($dst);

            if ($copied === false) {
                LocalStorage::remove($localPath);
                return [
                    'success' => false,
                    'error' => 'ファイルのコピーに失敗しました: ' . $localPath,
                ];
            }
            if ($copied > $this->maxFileSize) {
                LocalStorage::remove($localPath);
                return [
                    'success' => false,
                    'error' => 'ファイルサイズが上限を超えています: ' . $this->formatFileSize($copied),
                ];
            }

            $mimeCheck = $this->validateStoredFile($localPath);
            if (!$mimeCheck['success']) {
                return $mimeCheck;
            }

            return [
                'success' => true,
                'local_path' => $localPath,
                'file_name' => basename($localPath),
                'file_size' => (int) $copied,
            ];
        } catch (\Throwable $th) {
            return [
                'success' => false,
                'error' => 'コピー中にエラーが発生しました: ' . $th->getMessage(),
            ];
        }
    }

    /**
     * ファイルをダウンロード
     *
     * @param string $url
     * @param string $localPath
     * @return array{
     *     success: bool,
     *     local_path?: string,
     *     file_name?: string,
     *     file_size?: int,
     *     error?: string
     * }
     */
    private function downloadFile(string $url, string $localPath): array
    {
        try {
            // ディレクトリを作成
            $dir = dirname($localPath);
            if (!LocalStorage::exists($dir)) {
                if (!LocalStorage::makeDirectory($dir)) {
                    return [
                        'success' => false,
                        'error' => 'ディレクトリの作成に失敗しました: ' . $dir
                    ];
                }
            }

            // HTTPダウンロード（ボディはディスクへ直接書き出される）
            try {
                $result = $this->download($url, $localPath);

                if (!$result['success']) {
                    return $result;
                }

                $httpCode = $result['http_code'];

                if ($httpCode !== 200) {
                    LocalStorage::remove($localPath);
                    return [
                        'success' => false,
                        'error' => sprintf('ダウンロード失敗 (HTTP %d)', $httpCode)
                    ];
                }
            } catch (\Throwable $th) {
                LocalStorage::remove($localPath);
                return [
                    'success' => false,
                    'error' => 'ダウンロードに失敗しました: ' . $th->getMessage()
                ];
            }

            // ファイルサイズチェック（書き出し済みファイルのサイズで判定）
            $fileSize = LocalStorage::exists($localPath) ? (int) LocalStorage::getFileSize($localPath) : 0;
            if ($fileSize === 0) {
                LocalStorage::remove($localPath);
                return [
                    'success' => false,
                    'error' => 'ダウンロードしたファイルが空です'
                ];
            }

            if ($fileSize > $this->maxFileSize) {
                LocalStorage::remove($localPath);
                return [
                    'success' => false,
                    'error' => 'ファイルサイズが上限を超えています: ' . $this->formatFileSize($fileSize)
                ];
            }

            $mimeCheck = $this->validateStoredFile($localPath);
            if (!$mimeCheck['success']) {
                return $mimeCheck;
            }

            return [
                'success' => true,
                'local_path' => $localPath,
                'file_name' => basename($localPath),
                'file_size' => $fileSize,
            ];

        } catch (\Throwable $th) {
            return [
                'success' => false,
                'error' => 'ダウンロード中にエラーが発生しました: ' . $th->getMessage()
            ];
        }
    }

    /**
     * ローカルパスを生成
     *
     * @param WXRMedia $media
     * @return string
     */
    private function generateLocalPath(WXRMedia $media): string
    {
        // 安全なファイル名を生成
        $fileName = $this->sanitizeFileName($media->fileName);

        // 年月ディレクトリを作成（WordPressのアップロード構造に合わせる）
        $datePath = '';
        if ($media->uploadDate !== null) {
            $datePath = $media->uploadDate->format('Y/m/');
        }

        return $this->downloadDir . $datePath . $fileName;
    }

    /**
     * ファイル名をサニタイズ
     *
     * a-blog cms は日本語ファイル名を受け付けるため、文字種でのフィルタリングは行わず、
     * パストラバーサル防止と制御文字除去のみに留める。最終的なリネームは
     * Media::storeImage() / Media::storeFile() 側に委譲する。
     *
     * @param string $fileName
     * @return string
     */
    private function sanitizeFileName(string $fileName): string
    {
        // ディレクトリ要素を除去（マルチバイト対応 basename）
        $fileName = LocalStorage::mbBasename($fileName);
        // 制御文字・NUL バイトを除去
        $fileName = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName);
        // 先頭ドットは隠しファイル化されるため抑止
        $fileName = ltrim($fileName, '.');

        if ($fileName === '') {
            $fileName = 'unnamed_' . uniqid();
        }

        return $fileName;
    }

    /**
     * 画像系の許可拡張子。
     *
     * a-blog cms コアの ImageEngine が扱える MIME マップ
     * (ablogcms/php/Services/Image/Contracts/ImageEngine.php) と整合させる:
     *   image/gif / image/png / image/vnd.wap.wbmp / image/xbm / image/jpeg / image/webp
     * これに、Media::storeFile() で別経路（sanitizeSvg）扱いされる svg を追加。
     * コアが処理できない画像形式（avif/heic/ico/tiff など）は除外する。
     *
     * @var array<int, string>
     */
    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'xbm', 'svg',
    ];

    /**
     * 取り込んだファイルの実MIMEを検証する許可拡張子リストを構築する。
     *
     * 設計方針:
     *   - 画像系（SVG 含む）はプラグイン側で固定セット（IMAGE_EXTENSIONS）。
     *     a-blog cms コアは MIME が image/* かどうかで画像扱いを決めており、
     *     画像用の拡張子コンフィグキーは存在しないため、Symfony Mime と
     *     intersect させるための具体的拡張子リストを自前で持つ必要がある。
     *   - 文書／アーカイブ／動画／音声は a-blog cms のコンフィグセット
     *     (file_extension_document など) に従う。これは Media::storeFile() の
     *     allowlist と同じ構成。
     *
     * @return array<int, string>
     */
    private function buildAllowedExtensions(): array
    {
        return array_values(array_unique(array_merge(
            self::IMAGE_EXTENSIONS,
            configArray('file_extension_document') ?: [],
            configArray('file_extension_archive') ?: [],
            configArray('file_extension_movie') ?: [],
            configArray('file_extension_audio') ?: []
        )));
    }

    /**
     * ローカルに保存済みのファイルの実MIMEをコアバリデータで検証する。
     * 違反時はファイルを削除してエラー戻り値を返す。
     *
     * @return array{success: bool, error?: string}
     */
    private function validateStoredFile(string $localPath): array
    {
        $validator = new MimeTypeValidator();
        $allowed = $this->buildAllowedExtensions();
        if (!$validator->validateAllowedByContent($localPath, $allowed)) {
            $sniffed = $validator->sniffMimeType($localPath);
            $sniffedExtensions = $sniffed !== null ? $validator->getExtensionsFromMimeType($sniffed) : [];
            Logger::warning('【WXRImport plugin】許可されないMIMEを検出し、ファイルを破棄', [
                'path' => $localPath,
                'sniffed_mime' => $sniffed,
                'sniffed_extensions' => $sniffedExtensions,
                'allowed_extensions' => $allowed,
            ]);
            if (LocalStorage::exists($localPath)) {
                LocalStorage::remove($localPath);
            }
            return [
                'success' => false,
                'error' => '許可されていないファイル形式です: ' . ($sniffed ?? 'unknown'),
            ];
        }
        return ['success' => true];
    }

    /**
     * ダウンロードディレクトリを確保
     */
    private function ensureDownloadDirectory(): void
    {
        if (!LocalStorage::exists($this->downloadDir)) {
            if (!LocalStorage::makeDirectory($this->downloadDir)) {
                throw new \RuntimeException('ダウンロードディレクトリの作成に失敗しました: ' . $this->downloadDir);
            }
        }
    }

    /**
     * ローカル取り込みの許可ベースディレクトリを確保
     *
     * 取り込み元として参照されるディレクトリ自体は事前に存在する必要があるため、
     * ない場合は空ディレクトリを生成しておく（ユーザーがファイルを配置する場所）。
     */
    private function ensureLocalPathBase(): void
    {
        if (!LocalStorage::exists($this->localPathBase)) {
            // ベースディレクトリが用意できなくても致命ではない（ローカル取り込みを使わない運用がある）
            LocalStorage::makeDirectory($this->localPathBase);
        }
    }

    /**
     * ファイルサイズをフォーマット
     *
     * @param int $bytes
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return number_format($bytes, $unitIndex > 0 ? 1 : 0) . ' ' . $units[$unitIndex];
    }

    /**
     * ドメイン別のレート制限を適用
     *
     * @param string $url
     */
    private function applyRateLimit(string $url): void
    {
        $domain = parse_url($url, PHP_URL_HOST);
        if (!$domain) {
            return;
        }

        $now = microtime(true);

        // 同一ドメインの最後のアクセス時刻をチェック
        if (isset(self::$lastAccessTimes[$domain])) {
            $timeDiff = $now - self::$lastAccessTimes[$domain];
            $requiredDelay = $this->downloadDelay / 1000000; // マイクロ秒を秒に変換

            if ($timeDiff < $requiredDelay) {
                $sleepTime = ($requiredDelay - $timeDiff) * 1000000; // 秒をマイクロ秒に変換
                usleep((int)$sleepTime);
                // レート制限適用
            }
        }

        // 最後のアクセス時刻を更新
        self::$lastAccessTimes[$domain] = microtime(true);
    }

    /**
     * HTTPダウンロード（リダイレクトを自前で最大 5 ホップ追跡し、
     * 各ホップでスキーム・到達 IP を検証することで SSRF を防止する）。
     *
     * レスポンスボディはメモリに乗せず、直接 $localPath に書き出す。
     * 3xx の場合は書き出した内容を破棄して次ホップへ進む。
     *
     * @return array{success: bool, http_code?: int, error?: string}
     */
    private function download(string $url, string $localPath): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL拡張が利用できません'
            ];
        }

        $currentUrl = $url;
        $maxHops = 5;

        for ($hop = 0; $hop <= $maxHops; $hop++) {
            $validation = $this->validateUrlForFetch($currentUrl);
            if (!$validation['ok']) {
                Logger::warning('【WXRImport plugin】SSRF対策によりURLを拒否', [
                    'url' => $currentUrl,
                    'reason' => $validation['reason'],
                ]);
                return [
                    'success' => false,
                    'error' => '到達不能または許可されないURLです: ' . $validation['reason'],
                ];
            }

            $hopResult = $this->fetchSingleHop($currentUrl, $localPath);
            if (!$hopResult['success']) {
                return $hopResult;
            }

            // 3xx かつ Location があれば次ホップへ。それ以外はここで完了。
            $location = $hopResult['location'] ?? '';
            if ($hopResult['http_code'] >= 300 && $hopResult['http_code'] < 400 && $location !== '') {
                // 3xx のレスポンスボディ（通常は短いHTMLや空）は破棄
                LocalStorage::remove($localPath);
                if ($hop === $maxHops) {
                    return [
                        'success' => false,
                        'error' => 'リダイレクト上限に達しました',
                    ];
                }
                $nextUrl = $this->resolveRedirectUrl($currentUrl, $location);
                if ($nextUrl === null) {
                    return [
                        'success' => false,
                        'error' => 'リダイレクト先URLを解決できませんでした',
                    ];
                }
                $currentUrl = $nextUrl;
                continue;
            }

            return [
                'success' => true,
                'http_code' => $hopResult['http_code'],
            ];
        }

        return [
            'success' => false,
            'error' => 'リダイレクト上限に達しました',
        ];
    }

    /**
     * 1 ホップ分の cURL 取得。レスポンスボディは $localPath に直接ストリーム書き込みする。
     * ヘッダは CURLOPT_HEADERFUNCTION でメモリ上に逐次収集し、本文と混在させない。
     *
     * @return array{success: bool, http_code?: int, location?: string, error?: string}
     */
    private function fetchSingleHop(string $url, string $localPath): array
    {
        $fp = @fopen($localPath, 'wb');
        if ($fp === false) {
            return [
                'success' => false,
                'error' => 'ダウンロード先ファイルを開けませんでした: ' . $localPath,
            ];
        }

        $location = '';
        // PHP 8.0+ では curl_init() は CurlHandle を返し、デストラクタで自動解放される。
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            // ボディは fp にストリーム書き出し。RETURNTRANSFER は使わない。
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => $fp,
            // ヘッダは関数コールバックで行単位に収集し、Location のみ取り出す
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                if (preg_match('/^Location:\s*(.+?)\s*$/i', $line, $m) === 1) {
                    $location = trim($m[1]);
                }
                return strlen($line);
            },
            // リダイレクトは自前で 1 ホップずつ URL 検証するため無効化
            CURLOPT_FOLLOWLOCATION => false,
            // 危険なスキーム（file://, gopher://, dict:// 等）の利用を遮断
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'WXRImport Plugin/1.0 (a-blog cms)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE => $this->maxFileSize,
        ]);

        $ok = curl_exec($curl);
        fclose($fp);

        if ($ok === false) {
            $err = curl_error($curl) ?: 'Unknown error';
            LocalStorage::remove($localPath);
            return [
                'success' => false,
                'error' => 'HTTPダウンロードエラー: ' . $err,
            ];
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return [
            'success' => true,
            'http_code' => $httpCode,
            'location' => $location,
        ];
    }

    /**
     * 相対 Location を絶対 URL に解決する
     */
    private function resolveRedirectUrl(string $base, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $baseParts = parse_url($base);
        if ($baseParts === false || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? (':' . $baseParts['port']) : '';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }
        // 相対パス: base のパス末尾ディレクトリに連結
        $basePath = $baseParts['path'] ?? '/';
        $baseDir = preg_replace('#/[^/]*$#', '/', $basePath) ?? '/';
        return $scheme . '://' . $host . $port . $baseDir . $location;
    }

    /**
     * URL がフェッチして安全か検証する。
     * - スキームが http / https である
     * - ホスト名の DNS 解決結果のすべての IP が public IP である
     * - ただし $allowedPrivateHosts に登録されたホスト名は private 解決でも許可
     *
     * @return array{ok: bool, reason: string}
     */
    private function validateUrlForFetch(string $url): array
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => '対応していないスキーム: ' . $scheme];
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return ['ok' => false, 'reason' => 'ホスト名が空です'];
        }

        $isExplicitlyAllowed = in_array(strtolower($host), $this->allowedPrivateHosts, true);

        // IP リテラルがそのまま入っているケースもカバーする
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host) && !$isExplicitlyAllowed) {
                return ['ok' => false, 'reason' => 'プライベート/予約済みIPは禁止です: ' . $host];
            }
            return ['ok' => true, 'reason' => ''];
        }

        $ips = $this->resolveHost($host);
        if ($ips === false || $ips === []) {
            return ['ok' => false, 'reason' => 'ホスト名解決に失敗しました: ' . $host];
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip) && !$isExplicitlyAllowed) {
                return ['ok' => false, 'reason' => 'プライベート/予約済みIPに解決されました: ' . $ip];
            }
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * ホスト名解決をプロセス内でキャッシュする。
     *
     * インポートは単発のバックグラウンド処理で、同一ホストから多数のメディアを取りに行く
     * のが典型。都度 gethostbynamel() を呼ぶと数千件規模で無視できない遅延になるため、
     * キャッシュする。SSRF 防御の観点では、リダイレクト各ホップで本関数を通すことで
     * 「DNS rebinding を完全には防げない」既存仕様（旧 security-hardening AC-3）と等価。
     *
     * @return list<string>|false
     */
    private function resolveHost(string $host): array|false
    {
        $key = strtolower($host);
        if (array_key_exists($key, self::$dnsCache)) {
            return self::$dnsCache[$key];
        }
        $ips = gethostbynamel($host);
        self::$dnsCache[$key] = $ips;
        return $ips;
    }

    /**
     * 与えられた IP がパブリックレンジか判定する。
     * プライベート（10.0.0.0/8 等）・予約済み（169.254.0.0/16 等）はすべて拒否。
     */
    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * テストや特殊運用での上書き用フック。
     *
     * 通常運用では .env 経由で値が入るため本メソッドの呼び出しは不要。
     * 単体テスト等で一時的に値を差し替えたい場合のためだけに残している。
     *
     * local_path_base / allowed_private_hosts は本メソッドからは差し替え不可とする
     * （セキュリティに直結する設定は .env 専用にすることで、フォーム等からの誤上書きを防ぐ）。
     *
     * 許可 MIME タイプは a-blog cms コアの configArray('file_extension_*') 由来で
     * 自動的に構築されるため、本メソッドからの差し替えは受け付けない。
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

    /**
     * カンマ／空白区切り文字列または配列を、lowercase に整形したホスト名リストに正規化する。
     *
     * @param string|array<int, string> $value
     * @return array<int, string>
     */
    private function normalizeHostList(string|array $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }
        return array_values(array_filter(array_map(
            static fn($v) => strtolower(trim((string)$v)),
            $value
        )));
    }
}
