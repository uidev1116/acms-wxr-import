<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Content;

use DOMDocument;
use DOMXPath;
use DOMElement;
use SQL;
use Acms\Services\Facades\Application as Container;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Common;
use Acms\Plugins\WPImport\Services\Import\MediaImporter;

/**
 * WordPressコンテンツをa-blog cmsブロックエディター形式に変換する処理
 */
class ContentProcessor
{


    /** @var array<string, string> メディアURLのマッピングキャッシュ */
    private array $mediaUrlCache = [];

    private MediaImporter $mediaImporter;

    public function __construct()
    {
        $this->mediaImporter = Container::make(MediaImporter::class);
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
            Logger::error('【WPImport plugin】WordPressブロック変換エラー', Common::exceptionArray($e));
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
     * @param string $url
     * @param array<int, int> $mediaMapping
     * @return string
     */
    private function replaceMediaUrl(string $url, array $mediaMapping): string
    {
        // キャッシュから確認
        if (isset($this->mediaUrlCache[$url])) {
            return $this->mediaUrlCache[$url];
        }

        // WordPress のメディアURL パターンを解析
        $wpPostId = $this->extractWpPostIdFromUrl($url);
        if ($wpPostId && isset($mediaMapping[$wpPostId])) {
            $mediaId = $mediaMapping[$wpPostId];
            $mediaData = $this->getMediaInfo($mediaId);

            if ($mediaData) {
                // メディアタイプに応じてディレクトリを選択
                $baseDir = $this->getMediaBaseDirectory($mediaData['type']);
                $newUrl = '/' . DIR_OFFSET . $baseDir . $mediaData['path'];
                $this->mediaUrlCache[$url] = $newUrl;
                return $newUrl;
            }
        }

        // WordPressのwp-content/uploads構造を検出
        if (preg_match('/\/wp-content\/uploads\/(.+)$/', $url, $matches)) {
            $filePath = $matches[1];
            // 拡張子からメディアタイプを推定
            $mediaType = $this->estimateMediaTypeFromUrl($url);
            $baseDir = $this->getMediaBaseDirectory($mediaType);
            $newUrl = '/' . DIR_OFFSET . $baseDir . $filePath;
            $this->mediaUrlCache[$url] = $newUrl;
            return $newUrl;
        }

        return $url;
    }

    /**
     * メディア情報を取得
     *
     * @param int $mediaId
     * @return array{path: string, type: string}|null
     */
    private function getMediaInfo(int $mediaId): ?array
    {
        try {
            $SQL = SQL::newSelect('media');
            $SQL->addSelect('media_path', 'media_type');
            $SQL->addWhereOpr('media_id', $mediaId);
            $SQL->setLimit(1);

            $result = Database::query($SQL->get(dsn()), 'row');
            if ($result) {
                return [
                    'path' => $result['media_path'],
                    'type' => $result['media_type']
                ];
            }
        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】メディア情報取得エラー', Common::exceptionArray($e, [
                'media_id' => $mediaId
            ]));
        }
        return null;
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
     * URLからWordPress投稿IDを抽出
     *
     * @param string $url
     * @return int|null
     */
    private function extractWpPostIdFromUrl(string $url): ?int
    {
        // WordPress attachment URLのパターンを検索
        // 例: https://example.com/?attachment_id=123
        if (preg_match('/[?&]attachment_id=(\d+)/', $url, $matches)) {
            return intval($matches[1]);
        }

        // その他のパターンは今後追加
        return null;
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

        // WordPressブロックコメントのパターン
        $pattern = '/<!-- wp:(\w+)(?:\s+(\{[^}]*\}))?\s*-->(.*?)<!-- \/wp:\1\s*-->/s';

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
                    Logger::warning('【WPImport plugin】ブロック属性JSONデコードエラー', [
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

        // URLを変換
        $newUrl = $this->replaceMediaUrl($originalUrl, $mediaMapping);
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

        // URLを変換
        $newUrl = $this->replaceMediaUrl($originalUrl, $mediaMapping);
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



    /**
     * メディアファイルかどうかを判定
     *
     * @param string $url
     * @return bool
     */
    private function isMediaFile(string $url): bool
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        $mediaExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'zip', 'mp3', 'mp4', 'avi', 'mov'
        ];

        return in_array($extension, $mediaExtensions);
    }


    /**
     * メディアURLキャッシュをクリア
     */
    public function clearMediaUrlCache(): void
    {
        $this->mediaUrlCache = [];
    }

    /**
     * ショートコードを除去または変換
     *
     * @param string $content
     * @return string
     */
    public function removeShortcodes(string $content): string
    {
        try {
            // ネストしたショートコードを処理するため、複数回実行
            $maxIterations = 3;
            $iteration = 0;

            do {
                $originalContent = $content;

                // 特定のショートコードを個別に処理
                $content = $this->processSpecificShortcodes($content);

                // 一般的なショートコード（ネスト対応）
                $content = $this->processGeneralShortcodes($content);

                $iteration++;
            } while ($content !== $originalContent && $iteration < $maxIterations);

            return $content;

        } catch (\Throwable $e) {
            Logger::error('【WPImport plugin】ショートコード処理エラー', Common::exceptionArray($e));
            return $content; // エラー時は元のコンテンツを返す
        }
    }

    /**
     * 特定のショートコードを処理
     *
     * @param string $content
     * @return string
     */
    private function processSpecificShortcodes(string $content): string
    {
        $patterns = [
            // キャプション（属性付き対応）
            '/\[caption(?:\s+[^\]]*?)?\](.*?)\[\/caption\]/s' => '$1',

            // ギャラリー（IDや属性付き対応）
            '/\[gallery(?:\s+[^\]]*?)?\]/i' => '',

            // 埋め込み（URLやコンテンツ付き対応）
            '/\[embed(?:\s+[^\]]*?)?\](.*?)\[\/embed\]/s' => '$1',

            // YouTube、Vimeoなど
            '/\[youtube(?:\s+[^\]]*?)?\]([^\[]*)\[\/youtube\]/i' => '$1',
            '/\[vimeo(?:\s+[^\]]*?)?\]([^\[]*)\[\/vimeo\]/i' => '$1',

            // コンタクトフォーム
            '/\[contact-form-7(?:\s+[^\]]*?)?\]/i' => '',

            // WordPress標準ショートコード
            '/\[audio(?:\s+[^\]]*?)?\]/i' => '',
            '/\[video(?:\s+[^\]]*?)?\]/i' => '',
            '/\[playlist(?:\s+[^\]]*?)?\]/i' => '',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    /**
     * 一般的なショートコードを処理（ネスト対応）
     *
     * @param string $content
     * @return string
     */
    private function processGeneralShortcodes(string $content): string
    {
        // 自己完結型ショートコード [shortcode attr="value" /]
        $content = preg_replace('/\[([a-zA-Z0-9_-]+)(?:\s+[^\]]*?)?\s*\/\]/', '', $content);

        // 開始・終了タグ型ショートコード [shortcode]content[/shortcode]
        $content = preg_replace('/\[([a-zA-Z0-9_-]+)(?:\s+[^\]]*?)?\](.*?)\[\/\1\]/s', '$2', $content);

        // 単独ショートコード [shortcode] または [shortcode attr="value"]
        $content = preg_replace('/\[([a-zA-Z0-9_-]+)(?:\s+[^\]]*?)?\]/', '', $content);

        return $content;
    }


}
