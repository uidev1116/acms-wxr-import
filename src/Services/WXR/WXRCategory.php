<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\WXR;

/**
 * WXRカテゴリーデータを格納するクラス
 */
class WXRCategory
{
    public int $termId;
    public string $slug = '';
    public string $name = '';
    public string $description = '';
    public ?int $parentId = null;
    public string $taxonomy = 'category';

    public function __construct(int $termId, string $name)
    {
        $this->termId = $termId;
        $this->name = $name;
    }

    /**
     * a-blog cmsのカテゴリーコードを生成
     *
     * @return string
     */
    public function generateCode(): string
    {
        if ($this->slug !== '' && $this->slug !== null) {
            return $this->slug;
        }

        // 名前からコードを生成
        $code = preg_replace('/[^a-zA-Z0-9\-_]/', '', str_replace(' ', '-', $this->name));
        return $code ?: 'category_' . $this->termId;
    }

    /**
     * a-blog cms用のカテゴリー名を取得
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->name ?: $this->slug ?: 'Category ' . $this->termId;
    }

    /**
     * 階層の深さを判定するためのヘルパー
     *
     * @return bool
     */
    public function hasParent(): bool
    {
        return $this->parentId !== null && $this->parentId > 0;
    }

    /**
     * WordPress カテゴリー配列から WXRCategory インスタンスを生成
     *
     * @param array{
     *     term_id: int,
     *     slug: string,
     *     name: string,
     *     description?: string,
     *     parent?: int,
     *     taxonomy: string,
     *     count?: int
     * } $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $instance = new self($data['term_id'], $data['name']);
        $instance->slug = $data['slug'] ?? '';
        $instance->description = $data['description'] ?? '';
        $instance->parentId = ($data['parent'] ?? 0) > 0 ? $data['parent'] : null;

        return $instance;
    }

    /**
     * a-blog cms用の配列に変換
     *
     * @return array{
     *     category_code: string,
     *     category_name: string,
     *     category_parent: int|null,
     *     category_status: string,
     *     category_scope: string
     * }
     */
    public function toAcmsCategoryArray(): array
    {
        return [
            'category_code' => $this->generateCode(),
            'category_name' => $this->getDisplayName(),
            'category_parent' => $this->parentId,
            'category_status' => 'open', // a-blog cms標準のステータス
            'category_scope' => 'global'
        ];
    }
}
