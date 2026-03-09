<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\WXR;

use Acms\Services\Facades\LocalStorage;

/**
 * WordPressメディア（添付ファイル）の抽出処理
 */
class MediaExtractor
{
    /**
     * WXRアイテムからメディア情報を抽出
     *
     * @param array{
     *     post_type: string,
     *     post_id: int,
     *     title: string,
     *     link: string,
     *     post_date: string,
     *     post_date_gmt: string,
     *     creator: string,
     *     description: string,
     *     encoded_excerpt: string,
     *     post_parent: int,
     *     attachment_url: string,
     *     wp_postmeta: array
     * } $item WXRアイテムデータ
     * @return WXRMedia|null メディア情報、無効な場合はnull
     */
    public function extractMedia(array $item): ?WXRMedia
    {
        if ($item['post_type'] !== 'attachment') {
            return null;
        }

        // 必須フィールドの確認
        if (!($item['attachment_url'] ?? '')) {
            return null;
        }

        $media = new WXRMedia();
        $media->wpPostId = $item['post_id'];
        $media->wpParentId = $item['post_parent'];
        $media->title = $this->sanitizeText($item['title']);
        $media->originalUrl = $item['attachment_url'];
        $media->description = $this->sanitizeText($item['description']);
        $media->uploadDate = $this->parseDateTime($item['post_date_gmt']);
        $media->creator = $item['creator'];

        // ファイル情報を抽出
        $this->extractFileInfo($media, $item['postmeta']);

        return $media;
    }

    /**
     * ファイル情報を抽出してメディアオブジェクトに設定
     *
     * @param WXRMedia $media
     * @param array<string, string> $postMeta
     */
    private function extractFileInfo(WXRMedia $media, array $postMeta): void
    {
        // _wp_attached_file フィールドの処理
        if (isset($postMeta['_wp_attached_file'])) {
            $media->filePath = $postMeta['_wp_attached_file'];
        }

        // _wp_attachment_metadata フィールドの処理
        if (isset($postMeta['_wp_attachment_metadata'])) {
            $metadataValue = $postMeta['_wp_attachment_metadata'];
            if (is_string($metadataValue)) {
                $metadata = unserialize($metadataValue);
                if (is_array($metadata)) {
                    $media->width = (int)($metadata['width'] ?? 0);
                    $media->height = (int)($metadata['height'] ?? 0);
                    $media->fileSize = (int)($metadata['filesize'] ?? 0);
                    $media->mimeType = $metadata['mime-type'] ?? '';
                    $media->sizes = $metadata['sizes'] ?? [];
                }
            }
        }

        // _wp_attachment_image_alt フィールドの処理
        if (isset($postMeta['_wp_attachment_image_alt'])) {
            $media->altText = $this->sanitizeText($postMeta['_wp_attachment_image_alt']);
        }

        // ファイル名とMIMEタイプの推定（URL・ローカルパス両対応）
        if (!$media->fileName && $media->originalUrl !== null && $media->originalUrl !== '') {
            $path = parse_url($media->originalUrl, PHP_URL_PATH);
            if ($path === null || $path === '') {
                $path = $media->originalUrl;
            }
            $media->fileName = LocalStorage::mbBasename($path);
        }

        if (!$media->mimeType && $media->fileName !== null && $media->fileName !== '') {
            $media->mimeType = $this->guessMimeType($media->fileName);
        }
    }

    /**
     * ファイル拡張子からMIMEタイプを推定
     *
     * @param string $fileName
     * @return string
     */
    private function guessMimeType(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * テキストをサニタイズ
     *
     * @param string $text
     * @return string
     */
    private function sanitizeText(string $text): string
    {
        // HTMLエンティティをデコード
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 不要な空白を除去
        $text = trim($text);

        return $text;
    }

    /**
     * 日時文字列を解析してDateTimeオブジェクトに変換
     *
     * @param string $dateString
     * @return ?\DateTime
     */
    private function parseDateTime(string $dateString): ?\DateTime
    {
        if (!$dateString || $dateString === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new \DateTime($dateString, new \DateTimeZone('GMT'));
        } catch (\Throwable $th) {
            return null;
        }
    }
}
