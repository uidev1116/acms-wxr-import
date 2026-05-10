<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\WXR;

use Acms\Services\Facades\Logger;

/**
 * WXRタグデータを格納するクラス
 */
class WXRTag
{
    public int $termId;
    public string $slug = '';
    public string $name = '';
    public string $description = '';
    public string $taxonomy = 'post_tag';

    public function __construct(int $termId, string $name)
    {
        $this->termId = $termId;
        $this->name = $name;
    }

    /**
     * a-blog cms用のタグ名を取得
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $name = $this->name ?: $this->slug ?: 'Tag ' . $this->termId;;

        // 特殊文字を除去し、a-blog cms適合形式に変換
        $name = trim($name);
        // Unicode制御文字のみを除去（マルチバイト文字は保持）
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name); // 複数スペースを単一スペースに
        $name = mb_substr($name, 0, 255); // 255文字制限
        return $name;
    }

    /**
     * a-blog cms用のタグ配列に変換
     *
     * @param int $entryId
     * @param int $blogId
     * @param int $sort
     * @return array{
     *     tag_name: string,
     *     tag_entry_id: int,
     *     tag_blog_id: int,
     *     tag_sort: int
     * }
     */
    public function toAcmsTagArray(int $entryId, int $blogId, int $sort): array
    {
        return [
            'tag_name' => $this->getDisplayName(),
            'tag_entry_id' => $entryId,
            'tag_blog_id' => $blogId,
            'tag_sort' => $sort
        ];
    }

    /**
     * タグが有効かどうかを判定
     *
     * @return bool
     */
    public function isValid(): bool
    {
        $displayName = $this->getDisplayName();
        return $displayName !== '' && strlen($displayName) > 0;
    }
}
