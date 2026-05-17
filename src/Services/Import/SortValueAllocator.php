<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Import;

use Acms\Services\Entry\EntryRepository;

/**
 * バッチ先頭で `entry_sort` / `entry_user_sort` / `entry_category_sort` の次番を
 * 1 回だけ取得し、バッチ処理中はメモリ上で払い出す。
 *
 * 同時実行のないバックグラウンド処理であることが前提（既存の lockService により担保）。
 */
final class SortValueAllocator
{
    private int $blogId;
    private EntryRepository $repo;
    private int $nextEntrySort;

    /** @var array<int, int> userId => nextSort */
    private array $userSort = [];

    /** @var array<int, int> categoryId（0 = ルート） => nextSort */
    private array $categorySort = [];

    public function __construct(int $blogId, EntryRepository $repo)
    {
        $this->blogId = $blogId;
        $this->repo = $repo;
        $this->nextEntrySort = (int) $repo->nextSort($blogId);
    }

    public function allocateEntrySort(): int
    {
        $value = $this->nextEntrySort;
        $this->nextEntrySort++;
        return $value;
    }

    public function allocateUserSort(int $userId): int
    {
        if (!isset($this->userSort[$userId])) {
            $this->userSort[$userId] = (int) $this->repo->nextUserSort($userId, $this->blogId);
        }
        $value = $this->userSort[$userId];
        $this->userSort[$userId]++;
        return $value;
    }

    public function allocateCategorySort(?int $categoryId): int
    {
        $key = $categoryId ?? 0;
        if (!isset($this->categorySort[$key])) {
            $this->categorySort[$key] = (int) $this->repo->nextCategorySort($categoryId, $this->blogId);
        }
        $value = $this->categorySort[$key];
        $this->categorySort[$key]++;
        return $value;
    }
}
