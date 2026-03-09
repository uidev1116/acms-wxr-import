<?php

namespace Acms\Plugins\WPImport\Services\WXR;

use XMLReader;
use DOMDocument;
use Exception;
use Acms\Services\Facades\Logger;
use Acms\Plugins\WPImport\Services\Helpers\CodeGenerator;
use Acms\Services\Facades\LocalStorage;

/**
 * WordPress eXtended RSS (WXR) パーサー
 *
 * WordPressのエクスポートファイル（WXR）を解析して、
 * 投稿、ページ、メディア、カテゴリ、タグなどの情報を抽出します。
 */
class Parser
{
    private XMLReader $reader;

    /**
     * XML名前空間の定義
     * @var array<string, string>
     */
    private array $namespaces = [
        'wp' => 'http://wordpress.org/export/1.2/',
        'content' => 'http://purl.org/rss/1.0/modules/content/',
        'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
        'dc' => 'http://purl.org/dc/elements/1.1/'
    ];

    /**
     * カテゴリ定義のマップ (slug => 定義)
     * @var array<string, array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     parent: string|null,
     *     taxonomy: string
     * }>
     */
    private array $categoriesMap = [];

    /**
     * タグ定義のマップ (slug => 定義)
     * @var array<string, array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     taxonomy: string
     * }>
     */
    private array $tagsMap = [];

    public function __construct()
    {
        $this->reader = new XMLReader();
    }

    /**
     * WXRファイルを解析してアイテム（投稿、ページ、メディア）を順次返す
     *
     * @param string $filePath WXRファイルのパス
     * @return \Generator<int, array{
     *     title: string,
     *     link: string,
     *     pubDate: string,
     *     creator: string,
     *     guid: string,
     *     description: string,
     *     content: string,
     *     excerpt: string,
     *     post_id: int,
     *     post_date: string,
     *     post_date_gmt: string,
     *     comment_status: string,
     *     ping_status: string,
     *     post_name: string,
     *     status: string,
     *     post_parent: int,
     *     menu_order: int,
     *     post_type: string,
     *     post_password: string,
     *     is_sticky: string,
     *     attachment_url?: string,
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
     * }>
     * @throws Exception WXRファイルが見つからない、読み込み失敗、XML検証エラーの場合
     */
    public function parse(string $filePath): \Generator
    {
        if (!LocalStorage::exists($filePath)) {
            throw new Exception("WXRファイルが見つかりません: {$filePath}");
        }

        } finally {
            $this->reader->close();
        }
    }

    /**
     * 個別のアイテム（投稿・ページ・メディア）を抽出
     *
     * @return array{
     *     title: string,
     *     link: string,
     *     pubDate: string,
     *     creator: string,
     *     guid: string,
     *     description: string,
     *     content: string,
     *     excerpt: string,
     *     post_id: int,
     *     post_date: string,
     *     post_date_gmt: string,
     *     comment_status: string,
     *     ping_status: string,
     *     post_name: string,
     *     status: string,
     *     post_parent: int,
     *     menu_order: int,
     *     post_type: string,
     *     post_password: string,
     *     is_sticky: string,
     *     attachment_url?: string,
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
     * }|null 抽出に失敗した場合はnull
     */
    private function extractItem(): ?array
    {
        $doc = new DOMDocument();
        $node = $this->reader->expand();

        if (!$node) {
            return null;
        }

        $doc->appendChild($doc->importNode($node, true));

        $xpath = new \DOMXPath($doc);
        foreach ($this->namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        // 基本情報の抽出
        $item = [
            'title' => $this->getXPathValue($xpath, '//title'),
            'link' => $this->getXPathValue($xpath, '//link'),
            'pubDate' => $this->getXPathValue($xpath, '//pubDate'),
            'creator' => $this->getXPathValue($xpath, '//dc:creator'),
            'guid' => $this->getXPathValue($xpath, '//guid'),
            'description' => $this->getXPathValue($xpath, '//description'),
            'content' => $this->getXPathValue($xpath, '//content:encoded'),
            'excerpt' => $this->getXPathValue($xpath, '//excerpt:encoded'),
            'post_id' => (int)$this->getXPathValue($xpath, '//wp:post_id'),
            'post_date' => $this->getXPathValue($xpath, '//wp:post_date'),
            'post_date_gmt' => $this->getXPathValue($xpath, '//wp:post_date_gmt'),
            'comment_status' => $this->getXPathValue($xpath, '//wp:comment_status'),
            'ping_status' => $this->getXPathValue($xpath, '//wp:ping_status'),
            'post_name' => $this->getXPathValue($xpath, '//wp:post_name'),
            'status' => $this->getXPathValue($xpath, '//wp:status'),
            'post_parent' => (int)$this->getXPathValue($xpath, '//wp:post_parent'),
            'menu_order' => (int)$this->getXPathValue($xpath, '//wp:menu_order'),
            'post_type' => $this->getXPathValue($xpath, '//wp:post_type'),
            'post_password' => $this->getXPathValue($xpath, '//wp:post_password'),
            'is_sticky' => $this->getXPathValue($xpath, '//wp:is_sticky'),
        ];

        // 添付ファイル情報（メディア）
        if ($item['post_type'] === 'attachment') {
            $item['attachment_url'] = $this->getXPathValue($xpath, '//wp:attachment_url');
        }

        // カテゴリー・タグの抽出
        $item['categories'] = $this->extractTerms($xpath, 'category');
        $item['tags'] = $this->extractTerms($xpath, 'post_tag');

        // term_idを補完
        $this->supplementTermIds($item['categories'], $this->categoriesMap);
        $this->supplementTermIds($item['tags'], $this->tagsMap);

        // カスタムフィールドの抽出
        $item['postmeta'] = $this->extractPostMeta($xpath);

        // コメントの抽出
        $item['comments'] = $this->extractComments($xpath);

        return $item;
    }

    /**
     * チャンネル情報（サイト基本情報）を抽出
     *
     * @return array{
     *     title: string,
     *     link: string,
     *     description: string,
     *     language: string,
     *     base_site_url: string,
     *     base_blog_url: string,
     *     generator: string
     * } サイトの基本情報
     */
    private function extractChannelInfo(): array
    {
        $doc = new DOMDocument();
        $node = $this->reader->expand();

        if (!$node) {
            return [];
        }

        $doc->appendChild($doc->importNode($node, true));

        $xpath = new \DOMXPath($doc);
        foreach ($this->namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return [
            'title' => $this->getXPathValue($xpath, '//title'),
            'link' => $this->getXPathValue($xpath, '//link'),
            'description' => $this->getXPathValue($xpath, '//description'),
            'language' => $this->getXPathValue($xpath, '//language'),
            'base_site_url' => $this->getXPathValue($xpath, '//wp:base_site_url'),
            'base_blog_url' => $this->getXPathValue($xpath, '//wp:base_blog_url'),
            'generator' => $this->getXPathValue($xpath, '//generator'),
        ];
    }

    /**
     * カテゴリー・タグの定義を抽出して辞書化
     *
     * WXRファイル内の <wp:category> と <wp:tag> を解析して、
     * slug をキーとした定義マップを作成します。
     *
     * @return void
     */
    private function extractTaxonomyDefinitions(): void
    {
        $this->categoriesMap = [];
        $this->tagsMap = [];

        while ($this->reader->read()) {
            if ($this->reader->nodeType === XMLReader::ELEMENT) {
                if ($this->reader->name === 'wp:category') {
                    $categoryDef = $this->extractCategoryDefinition();
                    if ($categoryDef && $categoryDef['slug']) {
                        $this->categoriesMap[$categoryDef['slug']] = $categoryDef;
                    }
                } elseif ($this->reader->name === 'wp:tag') {
                    $tagDef = $this->extractTagDefinition();
                    if ($tagDef && $tagDef['slug']) {
                        $this->tagsMap[$tagDef['slug']] = $tagDef;
                    }
                }
            }
        }

    }

    /**
     * カテゴリー定義を抽出
     *
     * <wp:category> 要素からカテゴリの定義情報を抽出します。
     *
     * @return array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     parent: string|null,
     *     taxonomy: string
     * }|null 抽出に失敗した場合はnull
     */
    private function extractCategoryDefinition(): ?array
    {
        $doc = new DOMDocument();
        $node = $this->reader->expand();

        if (!$node) {
            return null;
        }

        $doc->appendChild($doc->importNode($node, true));
        $xpath = new \DOMXPath($doc);
        foreach ($this->namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        $termId = $this->getXPathValue($xpath, './/wp:term_id | .//wp:cat_id');
        $slug = $this->getXPathValue($xpath, './/wp:category_nicename');
        $name = $this->getXPathValue($xpath, './/wp:cat_name');
        $parent = $this->getXPathValue($xpath, './/wp:category_parent');

        return [
            'term_id' => $termId ? (int)$termId : null,
            'slug' => $slug,
            'name' => $name,
            'parent' => $parent ?: null,
            'taxonomy' => 'category'
        ];
    }

    /**
     * タグ定義を抽出
     *
     * <wp:tag> 要素からタグの定義情報を抽出します。
     *
     * @return array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     taxonomy: string
     * }|null 抽出に失敗した場合はnull
     */
    private function extractTagDefinition(): ?array
    {
        $doc = new DOMDocument();
        $node = $this->reader->expand();

        if (!$node) {
            return null;
        }

        $doc->appendChild($doc->importNode($node, true));
        $xpath = new \DOMXPath($doc);
        foreach ($this->namespaces as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        $termId = $this->getXPathValue($xpath, './/wp:term_id | .//wp:tag_id');
        $slug = $this->getXPathValue($xpath, './/wp:tag_slug');
        $name = $this->getXPathValue($xpath, './/wp:tag_name');

        return [
            'term_id' => $termId ? (int)$termId : null,
            'slug' => $slug,
            'name' => $name,
            'taxonomy' => 'post_tag'
        ];
    }

    /**
     * カテゴリー・タグの抽出（item内の紐付け情報）
     *
     * 各投稿アイテム内の <category> 要素から、投稿に紐付けられた
     * カテゴリまたはタグの情報を抽出します。
     *
     * @param \DOMXPath $xpath 投稿アイテムのDOMXPath
     * @param string $taxonomy タクソノミー名（'category' または 'post_tag'）
     * @return array<int, array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     taxonomy: string
     * }> 重複を除いたターム配列
     */
    private function extractTerms(\DOMXPath $xpath, string $taxonomy): array
    {
        // taxonomyが想定外の場合は空配列を返す
        if (!in_array($taxonomy, ['category', 'post_tag'])) {
            return [];
        }

        $terms = [];
        $nodes = $xpath->query(".//category[@domain='{$taxonomy}']");

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $slug = $node->getAttribute('nicename');
            $name = trim($node->textContent);

            // slugが空の場合はnameから生成
            if (!$slug && $name) {
                $slug = CodeGenerator::generateSlug($name);
            }

            if ($slug) {
                $terms[] = [
                    'term_id' => null, // 後で補完
                    'slug' => $slug,
                    'name' => $name,
                    'taxonomy' => $taxonomy,
                ];
            }

        }

        // 重複slug排除
        $uniqueTerms = [];
        $seenSlugs = [];
        foreach ($terms as $term) {
            if (!in_array($term['slug'], $seenSlugs)) {
                $uniqueTerms[] = $term;
                $seenSlugs[] = $term['slug'];
            }
        }

        return $uniqueTerms;
    }

    /**
     * term_idを補完
     *
     * 投稿から抽出されたタームのslugを使用して、
     * 事前に作成した定義マップからterm_idを補完します。
     *
     * @param array<int, array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     taxonomy: string
     * }> &$terms 補完対象のターム配列（参照渡し）
     * @param array<string, array{
     *     term_id: int|null,
     *     slug: string,
     *     name: string,
     *     parent?: string|null,
     *     taxonomy: string
     * }> $definitionsMap 定義マップ（slug => 定義）
     * @return void
     */
    private function supplementTermIds(array &$terms, array $definitionsMap): void
    {
        foreach ($terms as &$term) {
            if (isset($definitionsMap[$term['slug']])) {
                $term['term_id'] = $definitionsMap[$term['slug']]['term_id'];
            }
        }
    }

    /**
     * カスタムフィールドの抽出
     *
     * 投稿の <wp:postmeta> 要素からカスタムフィールドを抽出します。
     *
     * @param \DOMXPath $xpath 投稿アイテムのDOMXPath
     * @return array<string, string> カスタムフィールドの連想配列（key => value）
     */
    private function extractPostMeta(\DOMXPath $xpath): array
    {
        $meta = [];
        $nodes = $xpath->query('//wp:postmeta');

        foreach ($nodes as $node) {
            $key = $this->getXPathValue($xpath, './/wp:meta_key', $node);
            $value = $this->getXPathValue($xpath, './/wp:meta_value', $node);

            if ($key) {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    /**
     * コメントの抽出
     *
     * 投稿の <wp:comment> 要素からコメント情報を抽出します。
     *
     * @param \DOMXPath $xpath 投稿アイテムのDOMXPath
     * @return array<int, array{
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
     * }> コメントの配列
     */
    private function extractComments(\DOMXPath $xpath): array
    {
        $comments = [];
        $nodes = $xpath->query('//wp:comment');

        foreach ($nodes as $node) {
            $comments[] = [
                'comment_id' => (int)$this->getXPathValue($xpath, './/wp:comment_id', $node),
                'comment_author' => $this->getXPathValue($xpath, './/wp:comment_author', $node),
                'comment_author_email' => $this->getXPathValue($xpath, './/wp:comment_author_email', $node),
                'comment_author_url' => $this->getXPathValue($xpath, './/wp:comment_author_url', $node),
                'comment_date' => $this->getXPathValue($xpath, './/wp:comment_date', $node),
                'comment_date_gmt' => $this->getXPathValue($xpath, './/wp:comment_date_gmt', $node),
                'comment_content' => $this->getXPathValue($xpath, './/wp:comment_content', $node),
                'comment_approved' => $this->getXPathValue($xpath, './/wp:comment_approved', $node),
                'comment_type' => $this->getXPathValue($xpath, './/wp:comment_type', $node),
                'comment_parent' => (int)$this->getXPathValue($xpath, './/wp:comment_parent', $node),
            ];
        }

        return $comments;
    }

    /**
     * XPathから値を取得（安全な方法）
     *
     * 指定されたXPathクエリから最初の要素のテキスト値を安全に取得します。
     * 要素が見つからない場合は空文字列を返します。
     *
     * @param \DOMXPath $xpath DOMXPathオブジェクト
     * @param string $query XPathクエリ文字列
     * @param \DOMNode|null $context 検索コンテキスト（nullの場合はドキュメント全体）
     * @return string 取得した値（見つからない場合は空文字列）
     */
    private function getXPathValue(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
    {
        $nodes = $xpath->query($query, $context);
        return $nodes && $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
    }


    /**
     * XMLファイルを検証
     *
     * XML文字列の形式が正しいかを検証します。
     * 不正な場合は例外を投げます。
     *
     * @param string $data 検証対象のXML文字列
     * @return void
     * @throws \RuntimeException XMLが不正な形式の場合
     */
    private function validateXml(string $data): void
    {
        $reader = new XMLReader();
        $reader->XML($data);
        $reader->setParserProperty(XMLReader::VALIDATE, true);
        if (!$reader->isValid()) {
            $reader->close();
            throw new \RuntimeException('XMLファイルが正しくありません。または正しいエクスポートファイルではありません。');
        }
        $reader->close();
    }
}
