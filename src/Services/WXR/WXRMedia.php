<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\WXR;

/**
 * WordPressメディア情報を格納するデータクラス
 */
class WXRMedia
{
    /** @var int WordPressの投稿ID */
    public int $wpPostId = 0;

    /** @var int WordPressの親投稿ID（添付先エントリー） */
    public int $wpParentId = 0;

    /** @var string メディアタイトル */
    public string $title = '';

    /** @var string 説明文 */
    public string $description = '';

    /** @var string 代替テキスト（画像用） */
    public string $altText = '';

    /** @var string 元のURL */
    public string $originalUrl = '';

    /** @var string ファイルパス（相対） */
    public string $filePath = '';

    /** @var string ファイル名 */
    public string $fileName = '';

    /** @var string MIMEタイプ */
    public string $mimeType = '';

    /** @var int ファイルサイズ（バイト） */
    public int $fileSize = 0;

    /** @var int 幅（画像用） */
    public int $width = 0;

    /** @var int 高さ（画像用） */
    public int $height = 0;

    /** @var array<string, array{file: string, width: int, height: int, mime-type: string}> サイズ別画像情報 */
    public array $sizes = [];

    /** @var ?\DateTime アップロード日時 */
    public ?\DateTime $uploadDate = null;

    /** @var string 作成者 */
    public string $creator = '';

    /**
     * 画像ファイルかどうかを判定
     *
     * @return bool
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    /**
     * ダウンロード可能なファイルかどうかを判定
     *
     * @return bool
     */
    public function isDownloadable(): bool
    {
        return $this->originalUrl !== null && $this->originalUrl !== '' && $this->fileName !== null && $this->fileName !== '';
    }

    /**
     * ファイル拡張子を取得
     *
     * @return string
     */
    public function getExtension(): string
    {
        return pathinfo($this->fileName, PATHINFO_EXTENSION);
    }

    /**
     * ファイルサイズを人間が読める形式で取得
     *
     * @return string
     */
    public function getFormattedFileSize(): string
    {
        if ($this->fileSize === 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->fileSize;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, $unitIndex > 0 ? 1 : 0) . ' ' . $units[$unitIndex];
    }

    /**
     * 配列形式で出力
     *
     * @return array{
     *     wp_post_id: int,
     *     wp_parent_id: int,
     *     title: string,
     *     description: string,
     *     alt_text: string,
     *     original_url: string,
     *     file_path: string,
     *     file_name: string,
     *     mime_type: string,
     *     file_size: int,
     *     width: int,
     *     height: int,
     *     sizes: array,
     *     upload_date: ?string,
     *     creator: string,
     *     is_image: bool,
     *     extension: string,
     *     formatted_size: string
     * }
     */
    public function toArray(): array
    {
        return [
            'wp_post_id' => $this->wpPostId,
            'wp_parent_id' => $this->wpParentId,
            'title' => $this->title,
            'description' => $this->description,
            'alt_text' => $this->altText,
            'original_url' => $this->originalUrl,
            'file_path' => $this->filePath,
            'file_name' => $this->fileName,
            'mime_type' => $this->mimeType,
            'file_size' => $this->fileSize,
            'width' => $this->width,
            'height' => $this->height,
            'sizes' => $this->sizes,
            'upload_date' => $this->uploadDate?->format('Y-m-d H:i:s'),
            'creator' => $this->creator,
            'is_image' => $this->isImage(),
            'extension' => $this->getExtension(),
            'formatted_size' => $this->getFormattedFileSize(),
        ];
    }
}