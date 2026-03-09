<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Media;

use Acms\Services\Facades\Logger;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Common;
use Acms\Plugins\WPImport\Services\WXR\WXRMedia;

/**
 * WordPressメディアファイルのダウンロード機能
 */
class Downloader
{

    /** @var string ダウンロードディレクトリのベースパス */
    private string $downloadDir;


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
        $this->downloadDir = ARCHIVES_DIR . 'wp-import/media/';
        $this->ensureDownloadDirectory();
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
            Logger::error('【WPImport plugin】メディアダウンロードエラー', Common::exceptionArray($th, [
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
     * wp:attachment_url がローカルパス（または file://）かどうかを判定し、実在する絶対パスを返す
     *
     * XML 内の wp:attachment_url をローカルファイルパスに置換した場合に使用。
     * 例: /Users/foo/Downloads/image.jpg や file:///path/to/file.jpg
     *
     * @param string $urlOrPath 元のURL、またはローカルパス／file:// URL
     * @return string|null 実在する絶対パス。ローカルファイルでない、または存在しない場合は null
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

        // 絶対パスまたは file:// の場合はそのまま存在チェック
        if (@is_file($path)) {
            $resolved = @realpath($path);
            return $resolved !== false ? $resolved : null;
        }

        // ドキュメントルート相対（例: /wp-content/uploads/2026/01/sample.png）の場合は DOCUMENT_ROOT と結合して解決
        if (str_starts_with($path, '/') && defined('DOCUMENT_ROOT')) {
            $docRootPath = rtrim(DOCUMENT_ROOT, '/') . $path;
            if (@is_file($docRootPath)) {
                $resolved = @realpath($docRootPath);
                return $resolved !== false ? $resolved : null;
            }
        }

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

            $content = @file_get_contents($sourcePath);
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
            CURLOPT_USERAGENT => 'WPImport Plugin/1.0 (a-blog cms)',
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
