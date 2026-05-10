<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Media;

use Acms\Services\Facades\Logger;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Common;
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


    /** @var int 最大ファイルサイズ（バイト） */
    private int $maxFileSize = 50 * 1024 * 1024; // 50MB

    /** @var array<string> 許可するMIMEタイプ */
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    /** @var int ダウンロード間隔（マイクロ秒） */
    private int $downloadDelay = 500000; // 0.5秒

    /** @var array<string, float> ドメイン別の最後のアクセス時刻 */
    private static array $lastAccessTimes = [];

    public function __construct()
    {
        $this->downloadDir = ARCHIVES_DIR . 'wxr-import/media/';

        // ローカル取り込みの許可ベース。デフォルトは取り込み専用ディレクトリ。
        // config('wxr_import_local_path_base') で運用上書き可能。
        $configured = config('wxr_import_local_path_base');
        $this->localPathBase = is_string($configured) && $configured !== ''
            ? $configured
            : ARCHIVES_DIR . 'wxr-import/source/';

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

        // MIMEタイプチェック
        if (!$this->isMimeTypeAllowed($media->mimeType)) {
            return [
                'success' => false,
                'error' => '許可されていないファイルタイプです: ' . $media->mimeType
            ];
        }

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

            // 検証＋読み込みをコアに委譲（multi-layer defense として
            // resolveLocalFilePath() に続く 2 段目の traversal チェックも兼ねる）
            try {
                $content = LocalStorage::get($sourcePath, $this->localPathBase);
            } catch (\Throwable $th) {
                return [
                    'success' => false,
                    'error' => 'ローカルファイルの読み込みに失敗しました: ' . $th->getMessage(),
                ];
            }
            if ($content === false) {
                return [
                    'success' => false,
                    'error' => 'ローカルファイルの読み込みに失敗しました: ' . $sourcePath,
                ];
            }

            $fileSize = strlen($content);
            if ($fileSize > $this->maxFileSize) {
                return [
                    'success' => false,
                    'error' => 'ファイルサイズが上限を超えています: ' . $this->formatFileSize($fileSize),
                ];
            }

            if (!LocalStorage::put($localPath, $content)) {
                return [
                    'success' => false,
                    'error' => 'ファイルのコピーに失敗しました: ' . $localPath,
                ];
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

            // HTTPダウンロード
            try {
                $result = $this->download($url);

                if (!$result['success']) {
                    return $result;
                }

                $body = $result['body'];
                $httpCode = $result['http_code'];

                if ($httpCode !== 200) {
                    return [
                        'success' => false,
                        'error' => sprintf('ダウンロード失敗 (HTTP %d)', $httpCode)
                    ];
                }
            } catch (\Throwable $th) {
                return [
                    'success' => false,
                    'error' => 'ダウンロードに失敗しました: ' . $th->getMessage()
                ];
            }

            // ファイルサイズチェック
            $fileSize = strlen($body);
            if ($fileSize === 0) {
                return [
                    'success' => false,
                    'error' => 'ダウンロードしたファイルが空です'
                ];
            }

            if ($fileSize > $this->maxFileSize) {
                return [
                    'success' => false,
                    'error' => 'ファイルサイズが上限を超えています: ' . $this->formatFileSize($fileSize)
                ];
            }

            // ファイルを保存
            if (!LocalStorage::put($localPath, $body)) {
                return [
                    'success' => false,
                    'error' => 'ファイルの保存に失敗しました: ' . $localPath
                ];
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
     * @param string $fileName
     * @return string
     */
    private function sanitizeFileName(string $fileName): string
    {
        // 拡張子を分離
        $pathInfo = pathinfo($fileName);
        $name = $pathInfo['filename'];
        $extension = $pathInfo['extension'] ?? '';

        // 不正な文字を除去
        $name = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');

        // ファイル名が空の場合はデフォルト名を使用
        if (!$name) {
            $name = 'unnamed_' . uniqid();
        }

        return $name . ($extension ? '.' . $extension : '');
    }

    /**
     * MIMEタイプが許可されているかチェック
     *
     * @param string $mimeType
     * @return bool
     */
    private function isMimeTypeAllowed(string $mimeType): bool
    {
        return in_array($mimeType, $this->allowedMimeTypes, true);
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
     * HTTPダウンロード
     *
     * @param string $url
     * @return array{
     *     success: bool,
     *     body?: string,
     *     http_code?: int,
     *     error?: string
     * }
     */
    private function download(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'error' => 'cURL拡張が利用できません'
            ];
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'WXRImport Plugin/1.0 (a-blog cms)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // レスポンスサイズ制限
            CURLOPT_MAXFILESIZE => $this->maxFileSize,
            // ヘッダーを含めない
            CURLOPT_HEADER => false,
        ]);

        $body = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($body === false) {
            return [
                'success' => false,
                'error' => 'HTTPダウンロードエラー: ' . ($error ?: 'Unknown error')
            ];
        }


        return [
            'success' => true,
            'body' => $body,
            'http_code' => (int)$httpCode
        ];
    }

    /**
     * 設定を変更
     *
     * @param array{
     *     max_file_size?: int,
     *     allowed_mime_types?: array<string>,
     *     download_delay?: int
     * } $config
     */
    public function configure(array $config): void
    {
        if (isset($config['max_file_size'])) {
            $this->maxFileSize = max(1024, (int)$config['max_file_size']);
        }

        if (isset($config['allowed_mime_types'])) {
            $this->allowedMimeTypes = $config['allowed_mime_types'];
        }

        if (isset($config['download_delay'])) {
            $this->downloadDelay = max(0, (int)$config['download_delay']);
        }
    }
}
