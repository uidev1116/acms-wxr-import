<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Content;

use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Common;
use Acms\Plugins\WxrImport\Services\Import\MediaInfoMap;

/**
 * WordPressコンテンツをa-blog cmsブロックエディター形式に変換する処理
 */
class ContentProcessor
{
    /** @var array<int, string> media_id → 変換後 URL のキャッシュ */
    private array $mediaUrlCache = [];

    private MediaInfoMap $mediaInfoMap;

    public function __construct()
    {
        $this->mediaInfoMap = MediaInfoMap::empty();
    }

    /**
     * BatchProcessor がバッチ開始前に呼び、media テーブルの一括取得結果を注入する。
     * 注入されない場合は空 Map のままで、URL 書換はフォールバック（WordPress パス流用）に
     * 落ちる。
     */
    public function setMediaInfoMap(MediaInfoMap $map): void
    {
        $this->mediaInfoMap = $map;
        $this->mediaUrlCache = [];
    }

    /**
     * WordPressコンテンツを処理してa-blog cmsブロックエディター形式に変換
     *
     * @param string $content
     * @param array{
     *     media_mapping?: array<int, int>
     * } $options
     * @return array{
     *     content: string,
     *     replaced_urls: array<string, string>,
     *     media_replaced: int
     * }
     */
    public function processContent(string $content, array $options = []): array
    {

        $replacedUrls = [];
        $mediaReplaced = 0;

        try {
            // WordPressブロックをa-blog cmsブロック形式に変換
            $result = $this->convertWordPressBlocks($content, $options['media_mapping'] ?? []);
            $content = $result['content'];
            $mediaReplaced = $result['replaced_count'];
            $replacedUrls = array_merge($replacedUrls, $result['replaced_urls']);
        } catch (\Throwable $e) {
            Logger::error('【WXRImport plugin】WordPressブロック変換エラー', Common::exceptionArray($e));
        }


        $result = [
            'content' => $content,
            'replaced_urls' => $replacedUrls,
            'media_replaced' => $mediaReplaced,
        ];


        return $result;
    }

    /**
     * WordPressブロックをa-blog cmsブロック形式に変換
     *
     * @param string $content
     * @param array<int, int> $mediaMapping WordPress投稿IDからa-blog cmsメディアIDへのマッピング
     * @return array{
     *     content: string,
     *     replaced_urls: array<string, string>,
     *     replaced_count: int
     * }
     */
    private function convertWordPressBlocks(string $content, array $mediaMapping): array
    {
        $replacedUrls = [];
        $replacedCount = 0;

        if (empty($content)) {
            return [
                'content' => $content,
                'replaced_urls' => $replacedUrls,
                'replaced_count' => $replacedCount,
            ];
        }

        // WordPressブロックを抽出
        $blocks = $this->extractWordPressBlocks($content);

        // ブロックを逆順で処理（文字列位置のずれを回避）
        $blocks = array_reverse($blocks);

        foreach ($blocks as $block) {
            $result = null;

            switch ($block['type']) {
                case 'image':
                    $result = $this->processImageBlock($block, $mediaMapping);
                    break;
                case 'file':
                    $result = $this->processFileBlock($block, $mediaMapping);
                    break;
                default:
                    continue 2; // 対象外のブロックはスキップ
            }

            if ($result && $result['html'] !== $block['html']) {
                // ブロック全体を置換（コメント含む）
                $content = substr_replace(
                    $content,
                    $result['html'],
                    $block['start_pos'],
                    $block['end_pos'] - $block['start_pos']
                );

                $replacedUrls = array_merge($replacedUrls, $result['replaced_urls']);
                $replacedCount += $result['replaced_count'];
            }
        }

        return [
            'content' => $content,
            'replaced_urls' => $replacedUrls,
            'replaced_count' => $replacedCount,
        ];
    }



    /**
     * メディアURLを置換
     *
     * 解決済みの $mediaId が渡された場合は、a-blog cms のメディアテーブルから
     * 実パス（archives/YYYY-MM/xxx.png 等）を引き、URL を組み立てる。
     * $mediaId が無い場合は WordPress 側のパス構造を流用する後方互換のフォールバックを行う。
     *
     * @param string $url
     * @param int|null $mediaId 解決済みの a-blog cms メディアID（処理元のブロックで判明している場合）
     * @return string
     */
    private function replaceMediaUrl(string $url, ?int $mediaId = null): string
    {
        if ($mediaId !== null && $mediaId > 0) {
            if (isset($this->mediaUrlCache[$mediaId])) {
                return $this->mediaUrlCache[$mediaId];
            }
            $mediaData = $this->mediaInfoMap->get($mediaId);
            if ($mediaData) {
                $baseDir = $this->getMediaBaseDirectory($mediaData['type']);
                $newUrl = '/' . DIR_OFFSET . $baseDir . $mediaData['path'];
                $this->mediaUrlCache[$mediaId] = $newUrl;
                return $newUrl;
            }
        }

        // フォールバック: WordPressのwp-content/uploads構造を流用
        if (preg_match('/\/wp-content\/uploads\/(.+)$/', $url, $matches)) {
            $filePath = $matches[1];
            $mediaType = $this->estimateMediaTypeFromUrl($url);
            $baseDir = $this->getMediaBaseDirectory($mediaType);
            return '/' . DIR_OFFSET . $baseDir . $filePath;
        }

        return $url;
    }

    /**
     * メディア情報を取得（事前にロードされた MediaInfoMap を参照）
     *
     * @return array{path: string, type: string, filesize: int|string}|null
     */
    private function getMediaInfo(int $mediaId): ?array
    {
        return $this->mediaInfoMap->get($mediaId);
    }

    /**
     * メディアタイプに応じたベースディレクトリを取得
     *
     * @param string $mediaType
     * @return string
     */
    private function getMediaBaseDirectory(string $mediaType): string
    {
        switch ($mediaType) {
            case 'image':
            case 'svg':
                return MEDIA_LIBRARY_DIR;
            case 'file':
            default:
                return MEDIA_STORAGE_DIR;
        }
    }

    /**
     * URLから拡張子を元にメディアタイプを推定
     *
     * @param string $url
     * @return string
     */
    private function estimateMediaTypeFromUrl(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }

        if ($extension === 'svg') {
            return 'svg';
        }

        return 'file';
    }

    /**
     * WordPressブロックコメントを解析してブロック情報を抽出
     *
     * @param string $content
     * @return array<array{
     *     type: string,
     *     id: int|null,
     *     attributes: array,
     *     html: string,
     *     full_block: string,
     *     start_pos: int,
     *     end_pos: int
     * }>
     */
    private function extractWordPressBlocks(string $content): array
    {
        $blocks = [];

        // 変換対象は image / file ブロックのみ。
        // wp:columns / wp:column / wp:group などのコンテナにネストされていても拾えるよう、
        // 対象ブロック名で直接マッチする。属性 JSON はネストオブジェクトに耐えるよう非貪欲マッチ。
        $pattern = '/<!-- wp:(image|file)(?:\s+(\{.*?\}))?\s*-->(.*?)<!-- \/wp:\1\s*-->/s';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $blockType = $match[1][0];
                $attributesJson = $match[2][0] ?? '{}';
                $html = $match[3][0];
                $fullBlock = $match[0][0];
                $startPos = $match[0][1];
                $endPos = $startPos + strlen($fullBlock);

                // 属性JSONをデコード
                $attributes = [];
                try {
                    $attributes = json_decode($attributesJson, true) ?? [];
                } catch (\Throwable $e) {
                    Logger::warning('【WXRImport plugin】ブロック属性JSONデコードエラー', [
                        'block_type' => $blockType,
                        'json' => $attributesJson,
                        'error' => $e->getMessage()
                    ]);
                }

                $blocks[] = [
                    'type' => $blockType,
                    'id' => $attributes['id'] ?? null,
                    'attributes' => $attributes,
                    'html' => trim($html),
                    'full_block' => $fullBlock,
                    'start_pos' => $startPos,
                    'end_pos' => $endPos,
                ];
            }
        }

        return $blocks;
    }

    /**
     * a-blog cms 画像ブロックHTMLを生成
     *
     * @param array $blockInfo WordPress ブロック情報
     * @param int $mediaId a-blog cms メディアID
     * @param string $newUrl 変換後の画像URL
     * @return string
     */
    private function buildImageBlockHtml(array $blockInfo, int $mediaId, string $newUrl): string
    {
        $attributes = $blockInfo['attributes'];
        $align = $attributes['align'] ?? 'center';
        $width = $attributes['width'] ?? 'auto';
        $caption = $attributes['caption'] ?? '';

        // 元のHTMLから画像のalt属性を抽出
        $alt = $this->extractImageAlt($blockInfo['html']);

        $html = '<div class="media-image-block align-' . htmlspecialchars($align) . '" ' .
                'data-type="imageBlock" ' .
                'data-align="' . htmlspecialchars($align) . '" ' .
                'data-width="' . htmlspecialchars($width) . '" ' .
                'data-mid="' . $mediaId . '" ' .
                'data-no-lightbox="false">';

        $style = $width !== 'auto' ? ' style="max-width: ' . htmlspecialchars($width) . ';"' : '';
        $html .= '<figure' . $style . '>';
        $html .= '<a href="' . htmlspecialchars($newUrl) . '">';
        $html .= '<img src="' . htmlspecialchars($newUrl) . '" alt="' . htmlspecialchars($alt) . '" loading="lazy" data-mid="' . $mediaId . '" />';
        $html .= '</a>';

        if (!empty($caption)) {
            $html .= '<figcaption class="caption">' . htmlspecialchars($caption) . '</figcaption>';
        }

        $html .= '</figure></div>';

        return $html;
    }

    /**
     * a-blog cms ファイルブロックHTMLを生成
     *
     * @param array $blockInfo WordPress ブロック情報
     * @param int $mediaId a-blog cms メディアID
     * @param string $newUrl 変換後のファイルURL
     * @return string
     */
    private function buildFileBlockHtml(array $blockInfo, int $mediaId, string $newUrl): string
    {
        $attributes = $blockInfo['attributes'];
        $align = $attributes['align'] ?? 'left';

        // ファイル名とファイル情報を抽出
        $fileName = $this->extractFileName($blockInfo['html']);
        $extension = pathinfo($newUrl, PATHINFO_EXTENSION);

        // メディア情報からファイルサイズを取得
        $mediaInfo = $this->getMediaInfo($mediaId);
        $fileSize = $mediaInfo['file_size'] ?? '';

        $html = '<div class="media-file-block align-' . htmlspecialchars($align) . '" ' .
                'data-type="fileBlock" ' .
                'data-display-type="icon" ' .
                'data-extension="' . htmlspecialchars($extension) . '"';

        if (!empty($fileSize)) {
            $html .= ' data-file-size="' . htmlspecialchars($fileSize) . '"';
        }

        $html .= '>';
        $html .= '<a href="' . htmlspecialchars($newUrl) . '">';
        $html .= '<p class="caption">' . htmlspecialchars($fileName) . '</p>';
        $html .= '</a></div>';

        return $html;
    }

    /**
     * HTMLから画像のalt属性を抽出
     *
     * @param string $html
     * @return string
     */
    private function extractImageAlt(string $html): string
    {
        if (preg_match('/<img[^>]+alt=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * HTMLからファイル名を抽出
     *
     * @param string $html
     * @return string
     */
    private function extractFileName(string $html): string
    {
        // aタグのテキスト内容を取得
        if (preg_match('/<a[^>]*>([^<]+)<\/a>/i', $html, $matches)) {
            return trim($matches[1]);
        }
        return 'file';
    }

    /**
     * WordPress 画像ブロックを処理
     *
     * @param array $blockInfo WordPress ブロック情報
     * @param array<int, int> $mediaMapping
     * @return array{
     *     html: string,
     *     replaced_urls: array<string, string>,
     *     replaced_count: int
     * }
     */
    private function processImageBlock(array $blockInfo, array $mediaMapping): array
    {
        $replacedUrls = [];
        $replacedCount = 0;

        // ブロックIDからメディアマッピングを確認
        $wpPostId = $blockInfo['id'];
        if (!$wpPostId || !isset($mediaMapping[$wpPostId])) {
            // IDがない、またはマッピングがない場合は元のHTMLを返す
            return [
                'html' => $blockInfo['html'],
                'replaced_urls' => $replacedUrls,
                'replaced_count' => $replacedCount,
            ];
        }

        $mediaId = $mediaMapping[$wpPostId];

        // 元のHTMLから画像URLを抽出
        $originalUrl = $this->extractImageUrl($blockInfo['html']);
        if (!$originalUrl) {
            return [
                'html' => $blockInfo['html'],
                'replaced_urls' => $replacedUrls,
                'replaced_count' => $replacedCount,
            ];
        }

        // URLを変換（解決済みの mediaId を渡して a-blog cms 側の実パスを使う）
        $newUrl = $this->replaceMediaUrl($originalUrl, $mediaId);
        if ($newUrl !== $originalUrl) {
            $replacedUrls[$originalUrl] = $newUrl;
            $replacedCount = 1;
        }

        // a-blog cms 画像ブロックHTMLを生成
        $newHtml = $this->buildImageBlockHtml($blockInfo, $mediaId, $newUrl);

        return [
            'html' => $newHtml,
            'replaced_urls' => $replacedUrls,
            'replaced_count' => $replacedCount,
        ];
    }

    /**
     * WordPress ファイルブロックを処理
     *
     * @param array $blockInfo WordPress ブロック情報
     * @param array<int, int> $mediaMapping
     * @return array{
     *     html: string,
     *     replaced_urls: array<string, string>,
     *     replaced_count: int
     * }
     */
    private function processFileBlock(array $blockInfo, array $mediaMapping): array
    {
        $replacedUrls = [];
        $replacedCount = 0;

        // ブロックIDからメディアマッピングを確認
        $wpPostId = $blockInfo['id'];
        if (!$wpPostId || !isset($mediaMapping[$wpPostId])) {
            return [
                'html' => $blockInfo['html'],
                'replaced_urls' => $replacedUrls,
                'replaced_count' => $replacedCount,
            ];
        }

        $mediaId = $mediaMapping[$wpPostId];

        // 元のHTMLからファイルURLを抽出
        $originalUrl = $this->extractFileUrl($blockInfo['html']);
        if (!$originalUrl) {
            return [
                'html' => $blockInfo['html'],
                'replaced_urls' => $replacedUrls,
                'replaced_count' => $replacedCount,
            ];
        }

        // URLを変換（解決済みの mediaId を渡して a-blog cms 側の実パスを使う）
        $newUrl = $this->replaceMediaUrl($originalUrl, $mediaId);
        if ($newUrl !== $originalUrl) {
            $replacedUrls[$originalUrl] = $newUrl;
            $replacedCount = 1;
        }

        // a-blog cms ファイルブロックHTMLを生成
        $newHtml = $this->buildFileBlockHtml($blockInfo, $mediaId, $newUrl);

        return [
            'html' => $newHtml,
            'replaced_urls' => $replacedUrls,
            'replaced_count' => $replacedCount,
        ];
    }

    /**
     * HTMLから画像URLを抽出
     *
     * @param string $html
     * @return string|null
     */
    private function extractImageUrl(string $html): ?string
    {
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * HTMLからファイルURLを抽出
     *
     * @param string $html
     * @return string|null
     */
    private function extractFileUrl(string $html): ?string
    {
        if (preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
