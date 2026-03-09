<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Import;

use SQL;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Media;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\PublicStorage;
use Acms\Services\Facades\PrivateStorage;
use Acms\Plugins\WPImport\Services\WXR\WXRMedia;

/**
 * メディアファイルのa-blog cmsへの移行処理
 */
class MediaImporter
{

    /**
     * メディアをa-blog cmsに移行
     *
     * @param WXRMedia $media
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param string $localPath ダウンロード済みのローカルファイルパス
     * @return array{success: bool, media_id?: int, path?: string, error?: string}
     */
    public function importMedia(WXRMedia $media, array $settings, string $localPath): array
    {
        try {
            // ファイルの存在確認
            if (!LocalStorage::exists($localPath)) {
                return [
                    'success' => false,
                    'error' => 'ローカルファイルが存在しません: ' . $localPath
                ];
            }

            // ローカルファイルをメディアストレージに保存
            $storedData = $this->storeMediaFromLocalPath($localPath, $media, $settings);
            if (!$storedData) {
                return [
                    'success' => false,
                    'error' => 'メディアファイルの保存に失敗しました'
                ];
            }

            // データベースに登録
            $mediaId = $this->registerInDatabase($media, $storedData, $settings);
            if (!$mediaId) {
                return [
                    'success' => false,
                    'error' => 'データベースへの登録に失敗しました'
                ];
            }


            return [
                'success' => true,
                'media_id' => $mediaId,
                'path' => $storedData['path'],
            ];

        } catch (\Throwable $th) {
            Logger::error('【WPImport plugin】メディアインポートエラー', Common::exceptionArray($th, [
                'wp_post_id' => $media->wpPostId,
                'local_path' => $localPath
            ]));

            return [
                'success' => false,
                'error' => 'インポート中にエラーが発生しました: ' . $th->getMessage()
            ];
        }
    }


    /**
     * ダウンロード済みローカルファイルをメディアストレージに保存
     *
     * @param string $localPath
     * @param WXRMedia $media
     * @param array $settings
     * @return array{path: string, type: string, name: string, size: string, filesize: int, extension: string}|null
     */
    private function storeMediaFromLocalPath(string $localPath, WXRMedia $media, array $settings): ?array
    {
        $fileInfo = $this->prepareFileInfo($localPath, $media);
        if (!$fileInfo) {
            return null;
        }

        $mimeType = $fileInfo['mime_type'];

        if (Media::isImageFile($mimeType)) {
            return $this->storeLocalImage($fileInfo);
        }
        if (Media::isSvgFile($mimeType)) {
            return $this->storeLocalSvg($fileInfo);
        }
        return $this->storeLocalFile($fileInfo);
    }

    /**
     * ファイル情報を準備
     *
     * @param string $localPath
     * @param WXRMedia $media
     * @return array{tmp_name: string, name: string, type: string, size: int, mime_type: string}|null
     */
    private function prepareFileInfo(string $localPath, WXRMedia $media): ?array
    {
        if (!LocalStorage::exists($localPath)) {
            Logger::error('【WPImport plugin】ローカルファイルが存在しません', [
                'path' => $localPath
            ]);
            return null;
        }

        $size = LocalStorage::getFileSize($localPath);
        $mimeType = LocalStorage::getMimeType($localPath);

        if (!$mimeType) {
            Logger::error('【WPImport plugin】MIMEタイプの取得に失敗', [
                'path' => $localPath
            ]);
            return null;
        }

        return [
            'tmp_name' => $localPath,
            'name' => $media->fileName,
            'type' => $mimeType,
            'size' => $size,
            'mime_type' => $mimeType
        ];
    }

    /**
     * ローカル画像ファイルをメディアライブラリに保存
     *
     * @param array{tmp_name: string, name: string, type: string, size: int, mime_type: string} $fileInfo
     * @return array{path: string, type: string, name: string, size: string, filesize: int, extension: string}|null
     */
    private function storeLocalImage(array $fileInfo): ?array
    {
        try {
            $data = Media::storeImage(
                $fileInfo['tmp_name'],
                $fileInfo['name'],
                null,
                true
            );
            $path = $data['path'];
            return [
                'path' => $path,
                'type' => 'image',
                'name' => $data['name'],
                'size' => $data['size'],
                'filesize' => PublicStorage::getFileSize(MEDIA_LIBRARY_DIR . $path),
                'extension' => strtolower($data['type']),
            ];
        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】画像の保存に失敗', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name'],
            ]);
            return null;
        }
    }

    /**
     * ローカルSVGファイルをメディアライブラリに保存
     *
     * @param array{tmp_name: string, name: string, type: string, size: int, mime_type: string} $fileInfo
     * @return array{path: string, type: string, name: string, size: string, filesize: int, extension: string}|null
     */
    private function storeLocalSvg(array $fileInfo): ?array
    {
        try {
            $data = Media::storeFile(
                MEDIA_LIBRARY_DIR,
                $fileInfo['tmp_name'],
                $fileInfo['name'],
                true
            );
            $path = $data['path'];
            return [
                'path' => $path,
                'type' => 'svg',
                'name' => $data['name'],
                'size' => '',
                'filesize' => PublicStorage::getFileSize(MEDIA_LIBRARY_DIR . $path),
                'extension' => 'svg',
            ];
        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】SVGの保存に失敗', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name'],
            ]);
            return null;
        }
    }

    /**
     * ローカルファイルをメディアストレージに保存（PDF等）
     *
     * @param array{tmp_name: string, name: string, type: string, size: int, mime_type: string} $fileInfo
     * @return array{path: string, type: string, name: string, size: string, filesize: int, extension: string}|null
     */
    private function storeLocalFile(array $fileInfo): ?array
    {
        try {
            $data = Media::storeFile(
                MEDIA_STORAGE_DIR,
                $fileInfo['tmp_name'],
                $fileInfo['name'],
                true
            );
            $path = $data['path'];
            return [
                'path' => $path,
                'type' => 'file',
                'name' => $data['name'],
                'size' => '',
                'filesize' => PrivateStorage::getFileSize(MEDIA_STORAGE_DIR . $path),
                'extension' => strtolower($data['type']),
            ];
        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】ファイルの保存に失敗', [
                'error' => $e->getMessage(),
                'file' => $fileInfo['name'],
            ]);
            return null;
        }
    }

    /**
     * データベースにメディア情報を登録
     *
     * @param WXRMedia $media
     * @param array $storedData Media::store*から返されたデータ（insertMedia用に正規化済み）
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @return int|null メディアID（失敗時はnull）
     */
    private function registerInDatabase(WXRMedia $media, array $storedData, array $settings): ?int
    {
        try {
            Database::connection()->beginTransaction();

            // メディアIDを取得
            $mediaId = (int)Database::query(SQL::nextval('media_id', dsn()), 'seq');

            $mediaData = $this->prepareMediaData($media, $storedData, $settings);
            Media::insertMedia($mediaId, $mediaData);

            Database::connection()->commit();

            return intval($mediaId);

        } catch (\Throwable $th) {
            Database::connection()->rollBack();
            Logger::error('【WPImport plugin】メディアデータベース登録エラー', Common::exceptionArray($th, [
                'wp_post_id' => $media->wpPostId
            ]));
            return null;
        }
    }

    /**
     * Media Facade用のデータを準備
     *
     * @param WXRMedia $media
     * @param array $storedData
     * @param array $settings
     * @return array
     */
    private function prepareMediaData(WXRMedia $media, array $storedData, array $settings): array
    {
        $data = [
            'type' => $storedData['type'],
            'extension' => $storedData['extension'],
            'path' => $storedData['path'],
            'name' => $storedData['name'],
            'filesize' => $storedData['filesize'],
            'size' => $storedData['size'] ?? '',
        ];

        // WordPressから引き継ぐメタデータをカスタムフィールドに設定
        if ($media->title) {
            $data['field_1'] = $media->title; // caption
        }
        if ($media->description) {
            $data['field_1'] = $media->description; // caption (titleより優先)
        }
        if ($media->altText) {
            $data['field_3'] = $media->altText; // alt
        }

        return $data;
    }

    /**
     * a-blog cmsのメディアパスを取得
     *
     * @param int $mediaId
     * @return string|null
     */
    public function getMediaPath(int $mediaId): ?string
    {
        $SQL = SQL::newSelect('media');
        $SQL->addSelect('media_path');
        $SQL->addWhereOpr('media_id', $mediaId);
        $SQL->setLimit(1);

        $result = Database::query($SQL->get(dsn()), 'one');
        return $result ?: null;
    }
}
