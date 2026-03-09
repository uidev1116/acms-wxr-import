<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\WXR;

use DateTime;
use DateTimeZone;
use Exception;
use Acms\Services\Facades\Logger;

/**
 * WordPress WXRエントリー抽出クラス
 *
 * WXR Parserから取得したアイテムデータを処理して、
 * a-blog cms用のWXREntryオブジェクトに変換します。
 */
class EntryExtractor
{
    /**
     * WXRアイテムからエントリー情報を抽出
     *
     * @param array{
     *     post_id: int,
     *     title: string,
     *     content: string,
     *     excerpt: string,
     *     post_name: string,
     *     status: string,
     *     post_type: string,
     *     post_parent: int,
     *     menu_order: int,
     *     comment_status: string,
     *     ping_status: string,
     *     post_password: string,
     *     is_sticky: string,
     *     post_date: string,
     *     post_date_gmt: string,
     *     creator: string,
     *     link: string,
     *     guid: string,
     *     categories: array<int, array{
     *         term_id: int|null,
     *         slug: string,
     *         name: string,
     *         taxonomy: string
     *     }>,
     *     tags: array<int, array{
     *         term_id: int|null,
     *         slug: string,
     *         name: string,
     *         taxonomy: string
     *     }>,
     *     postmeta: array<string, string>,
     *     comments: array<int, array{
     *         comment_id: int,
     *         comment_author: string,
     *         comment_author_email: string,
     *         comment_author_url: string,
     *         comment_date: string,
     *         comment_date_gmt: string,
     *         comment_content: string,
     *         comment_approved: string,
     *         comment_type: string,
     *         comment_parent: int
     *     }>
     * } $wxrItem WXR Parserから取得したアイテムデータ
     * @return WXREntry|null 変換されたエントリー（エラー時はnull）
     */
    public function extractEntry(array $wxrItem): ?WXREntry
    {
        // 添付ファイル（メディア）は除外
        if ($wxrItem['post_type'] === 'attachment') {
            return null;
        }

        try {
            $entry = new WXREntry();

            // 基本情報
            $entry->wpPostId = $wxrItem['post_id'];
            $entry->title = $this->sanitizeText($wxrItem['title']);
            $entry->content = $this->sanitizeHtml($wxrItem['content']);
            $entry->excerpt = $this->sanitizeHtml($wxrItem['excerpt']);
            $entry->postName = $wxrItem['post_name']; // スラッグ
            $entry->status = $this->convertStatus($wxrItem['status']);
            $entry->type = $wxrItem['post_type'];
            $entry->parentId = $wxrItem['post_parent'] ?: null;
            $entry->menuOrder = $wxrItem['menu_order'];
            $entry->commentStatus = $wxrItem['comment_status'] === 'open';
            $entry->pingStatus = $wxrItem['ping_status'] === 'open';
            $entry->password = $wxrItem['post_password'];
            $entry->isSticky = $wxrItem['is_sticky'] === '1';

            // 日時情報
            $entry->postDate = $this->parseDate($wxrItem['post_date']);
            $entry->postDateGmt = $this->parseDate($wxrItem['post_date_gmt']);

            // 作成者情報
            $entry->author = $wxrItem['creator'];

            // URL情報
            $entry->originalUrl = $wxrItem['link'];
            $entry->guid = $wxrItem['guid'];

            // カテゴリー・タグ
            $entry->categories = $this->extractCategories($wxrItem['categories']);
            $entry->tags = $this->extractTags($wxrItem['tags']);

            // カスタムフィールド
            $entry->customFields = $this->processCustomFields($wxrItem['postmeta']);

            // アイキャッチ画像ID（後でメディア移行時に変換）
            $entry->featuredMediaId = isset($wxrItem['postmeta']['_thumbnail_id'])
                ? (int)$wxrItem['postmeta']['_thumbnail_id']
                : null;

            // コメント
            $entry->comments = $this->processComments($wxrItem['comments']);

            // SEO関連メタデータの抽出
            $entry->seoData = $this->extractSeoData($wxrItem['postmeta']);


            return $entry;

        } catch (Exception $e) {
            Logger::error('【WPImport plugin】エントリー抽出エラー', [
                'post_id' => $wxrItem['post_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * ステータスをa-blog cms形式に変換
     *
     * WordPressの投稿ステータスをa-blog cmsの形式に変換します。
     *
     * @param string $wpStatus WordPressの投稿ステータス
     * @return string a-blog cms形式のステータス（'open'|'draft'|'close'）
     */
    private function convertStatus(string $wpStatus): string
    {
        $statusMap = [
            'publish' => 'open',      // 公開
            'draft' => 'draft',       // 下書き
            'pending' => 'draft',     // 承認待ち → 下書きとして扱う
            'private' => 'close',     // 非公開
            'future' => 'open',       // 予約投稿 → 公開として扱う
            'auto-draft' => 'draft',  // 自動下書き
            'inherit' => 'open',      // 継承（リビジョンなど）
        ];

        return $statusMap[$wpStatus] ?? 'draft';
    }

    /**
     * 日時文字列をDateTimeオブジェクトに変換
     *
     * WordPressの日時形式をDateTimeオブジェクトに変換します。
     * 無効な日時の場合はnullを返します。
     *
     * @param string $dateString WordPress形式の日時文字列
     * @return DateTime|null 変換されたDateTimeオブジェクト（無効な場合はnull）
     */
    private function parseDate(string $dateString): ?DateTime
    {
        if (!$dateString || $dateString === '0000-00-00 00:00:00') {
            return null;
        }

        return new DateTime($dateString, new DateTimeZone('UTC'));
    }

    /**
    * カテゴリー情報の抽出
    *
    * WXR Parserから取得したカテゴリー配列をWXRCategoryオブジェクトの配列に変換します。
    *
    * @param array<int, array{
    *     term_id: int|null,
    *     slug: string,
    *     name: string,
    *     taxonomy: string
    * }> $categories カテゴリー情報の配列
    * @return array<int, WXRCategory> WXRCategoryオブジェクトの配列
    */
    private function extractCategories(array $categories): array
    {
        $result = [];
        foreach ($categories as $category) {
            $wxrCategory = new WXRCategory($category['term_id'], $category['name']);

            $wxrCategory->slug = $category['slug'];
            $wxrCategory->parentId = $category['parent'] ?: null;
            $wxrCategory->description = $category['description'] ?? '';

            $result[] = $wxrCategory;
        }
        return $result;
    }

    /**
     * タグ情報の抽出
     *
     * WXR Parserから取得したタグ配列をWXRTagオブジェクトの配列に変換します。
     *
     * @param array<int, array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     taxonomy: string
     * }> $tags タグ情報の配列
     * @return array<int, WXRTag> WXRTagオブジェクトの配列
     */
    private function extractTags(array $tags): array
    {
        $result = [];
        foreach ($tags as $tag) {
            $wxrTag = new WXRTag($tag['term_id'], $tag['name']);
            $wxrTag->slug = $tag['slug'];
            $wxrTag->description = $tag['description'] ?? '';

            $result[] = $wxrTag;
        }
        return $result;
    }

    /**
     * カスタムフィールドの処理
     *
     * WordPressのカスタムフィールドをa-blog cms用に変換します。
     * WordPressの内部フィールドは除外し、必要なフィールドのみ保持します。
     *
     * @param array<string, string> $postmeta カスタムフィールドの連想配列
     * @return array<string, string> 処理されたカスタムフィールド
     */
    private function processCustomFields(array $postmeta): array
    {
        $customFields = [];

        // WordPress内部フィールドを除外
        $excludeFields = [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_old_date',
            '_thumbnail_id',
            '_wp_attachment_metadata',
            '_wp_attached_file',
        ];

        foreach ($postmeta as $key => $value) {
            // アンダースコアで始まるフィールドの多くは内部用途
            if (strpos($key, '_') === 0 && !in_array($key, $excludeFields)) {
                // 一部の重要な_で始まるフィールドは保持
                if (in_array($key, ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_wp_page_template'])) {
                    $customFields[$key] = $value;
                }
                continue;
            }

            if (!in_array($key, $excludeFields)) {
                $customFields[$key] = $value;
            }
        }

        return $customFields;
    }

    /**
     * コメント情報の処理
     *
     * WordPressのコメントをa-blog cms用の形式に変換します。
     *
     * @param array<int, array{
     *     comment_id: int,
     *     comment_author: string,
     *     comment_author_email: string,
     *     comment_author_url: string,
     *     comment_date: string,
     *     comment_date_gmt: string,
     *     comment_content: string,
     *     comment_approved: string,
     *     comment_type: string,
     *     comment_parent: int
     * }> $comments コメント情報の配列
     * @return array<int, array{
     *     comment_id: int,
     *     author: string,
     *     author_email: string,
     *     author_url: string,
     *     date: DateTime|null,
     *     date_gmt: DateTime|null,
     *     content: string,
     *     approved: bool,
     *     type: string,
     *     parent: int
     * }> 処理されたコメント配列
     */
    private function processComments(array $comments): array
    {
        $processedComments = [];

        foreach ($comments as $comment) {
            $processedComments[] = [
                'comment_id' => $comment['comment_id'],
                'author' => $comment['comment_author'],
                'author_email' => $comment['comment_author_email'],
                'author_url' => $comment['comment_author_url'],
                'date' => $this->parseDate($comment['comment_date']),
                'date_gmt' => $this->parseDate($comment['comment_date_gmt']),
                'content' => $this->sanitizeHtml($comment['comment_content']),
                'approved' => $comment['comment_approved'] === '1',
                'type' => $comment['comment_type'] ?: 'comment',
                'parent' => $comment['comment_parent'],
            ];
        }

        return $processedComments;
    }

    /**
     * SEO関連データの抽出
     *
     * Yoast SEOやAll in One SEOプラグインのメタデータを抽出します。
     *
     * @param array<string, string> $postmeta カスタムフィールドの連想配列
     * @return array{
     *     yoast_title: string,
     *     yoast_description: string,
     *     yoast_keywords: string,
     *     aioseop_title: string,
     *     aioseop_description: string,
     *     aioseop_keywords: string,
     *     page_template: string
     * } SEO関連データ
     */
    private function extractSeoData(array $postmeta): array
    {
        return [
            // Yoast SEO
            'yoast_title' => $postmeta['_yoast_wpseo_title'] ?? '',
            'yoast_description' => $postmeta['_yoast_wpseo_metadesc'] ?? '',
            'yoast_keywords' => $postmeta['_yoast_wpseo_focuskw'] ?? '',

            // All in One SEO
            'aioseop_title' => $postmeta['_aioseop_title'] ?? '',
            'aioseop_description' => $postmeta['_aioseop_description'] ?? '',
            'aioseop_keywords' => $postmeta['_aioseop_keywords'] ?? '',

            // ページテンプレート
            'page_template' => $postmeta['_wp_page_template'] ?? '',
        ];
    }

    /**
     * テキストの安全な処理
     *
     * HTMLエンティティのデコードと制御文字の除去を行います。
     * タイトルなどのプレーンテキスト用の処理です。
     *
     * @param string $text 処理対象のテキスト
     * @return string サニタイズされたテキスト
     */
    private function sanitizeText(string $text): string
    {
        // HTMLエンティティをデコード
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 制御文字を除去
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);

        return trim($text);
    }

    /**
     * HTMLコンテンツの安全な処理
     *
     * HTMLコンテンツのエンティティデコードと制御文字の除去を行います。
     * 改行とタブは保持して、HTMLの構造を維持します。
     *
     * @param string $html 処理対象のHTMLコンテンツ
     * @return string サニタイズされたHTMLコンテンツ
     */
    private function sanitizeHtml(string $html): string
    {
        if (!$html) {
            return '';
        }

        // HTMLエンティティをデコード
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 制御文字を除去（改行・タブは保持）
        $html = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/', '', $html);

        return trim($html);
    }
}
