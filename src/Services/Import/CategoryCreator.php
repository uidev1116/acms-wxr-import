<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Import;

use ACMS_RAM;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use Acms\Plugins\WPImport\Services\WXR\WXRCategory;
use Acms\Plugins\WPImport\Services\Helpers\CodeGenerator;
use SQL;

/**
 * カテゴリーの自動作成機能
 */
class CategoryCreator
{
    /**
     * WordPressカテゴリーからa-blog cmsカテゴリーを作成
     *
     * @param array<Acms\Plugins\WPImport\Services\WXR\WXRCategory> $categories
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @return array<int, int> WordPress カテゴリーIDからa-blog cms カテゴリーIDへのマッピング
     */
    public function createCategories(array $categories, array $settings): array
    {
        $mapping = [];
        $blogId = $settings['target_blog_id'];

        // 階層構造を考慮した順序で処理
        $sortedCategories = $this->sortCategoriesByHierarchy($categories);

        foreach ($sortedCategories as $category) {
            try {
                // 既存のカテゴリーをチェック
                $existingId = $this->findExistingCategory($category->generateCode(), $blogId);
                if ($existingId) {
                    $mapping[$category->termId] = $existingId;
                    continue;
                }

                // 新規カテゴリーを作成
                $categoryId = $this->createCategory($category, $mapping, $blogId);
                if ($categoryId) {
                    $mapping[$category->termId] = $categoryId;
                }

            } catch (\Throwable $th) {
                Logger::error('【WPImport plugin】カテゴリー作成に失敗しました', Common::exceptionArray($th, [
                    'wp_term_id' => $category->termId,
                    'name' => $category->name
                ]));
            }
        }

        return $mapping;
    }


    /**
     * カテゴリーを階層順にソート
     *
     * @param WXRCategory[] $categories
     * @return WXRCategory[]
     */
    private function sortCategoriesByHierarchy(array $categories): array
    {
        $sorted = [];
        $processed = [];

        // 親カテゴリーから順に処理
        $maxIterations = count($categories) * 2; // 無限ループ防止
        $iteration = 0;

        while (count($processed) < count($categories) && $iteration < $maxIterations) {
            foreach ($categories as $category) {
                if (in_array($category->termId, $processed, true)) {
                    continue;
                }

                $parentId = $category->parentId ?? 0;

                // 親がいない、または親が既に処理済みの場合
                if ($parentId === 0 || in_array($parentId, $processed, true)) {
                    $sorted[] = $category;
                    $processed[] = $category->termId;
                }
            }
            $iteration++;
        }

        // 残ったカテゴリー（循環参照など）も追加
        foreach ($categories as $category) {
            if (!in_array($category->termId, $processed, true)) {
                $sorted[] = $category;
            }
        }

        return $sorted;
    }

    /**
     * 新規カテゴリーを作成
     *
     * @param WXRCategory $wxrCategory
     * @param array<int, int> $parentMapping
     * @param int $blogId
     * @return int|null
     */
    private function createCategory(WXRCategory $wxrCategory, array $parentMapping, int $blogId): ?int
    {
        try {

            // 親カテゴリーIDを解決
            $parentId = 0;
            if ($wxrCategory->parentId && isset($parentMapping[$wxrCategory->parentId])) {
                $parentId = $parentMapping[$wxrCategory->parentId];
            }

            // 親カテゴリーのステータスを確認
            $parentStatus = ACMS_RAM::categoryStatus($parentId);
            $status = 'open';
            if ($parentStatus !== null && $parentStatus !== '' && $parentStatus !== 'open') {
                $status = $parentStatus;
            }

            // カテゴリーID生成
            $categoryId = (int)Database::query(SQL::nextval('category_id', dsn()), 'seq');

            // カテゴリーコード生成（重複チェック付き）
            $code = $this->generateCategoryCode($wxrCategory->generateCode(), $blogId);

            // ソート順とleft/right値の取得（親カテゴリーを考慮）
            $sort = $this->getNextCategorySort($blogId, $parentId);
            [$left, $right] = $this->getNextLeftRight($blogId, $parentId);

            // categoryテーブルに挿入（最初から正しい親IDを設定）
            $sql = SQL::newInsert('category');
            $sql->addInsert('category_id', $categoryId);
            $sql->addInsert('category_parent', $parentId); // インポート処理では階層順序が保証済みなので一回で設定
            $sql->addInsert('category_sort', $sort);
            $sql->addInsert('category_left', $left);
            $sql->addInsert('category_right', $right);
            $sql->addInsert('category_blog_id', $blogId);
            $sql->addInsert('category_status', $status);
            $sql->addInsert('category_name', $wxrCategory->getDisplayName());
            $sql->addInsert('category_scope', 'local');
            $sql->addInsert('category_indexing', 'on');
            $sql->addInsert('category_code', $code);
            $sql->addInsert('category_config_set_id', null);
            $sql->addInsert('category_config_set_scope', 'local');
            $sql->addInsert('category_theme_set_id', null);
            $sql->addInsert('category_theme_set_scope', 'local');
            $sql->addInsert('category_editor_set_id', null);
            $sql->addInsert('category_editor_set_scope', 'local');
            Database::query($sql->get(dsn()), 'exec');

            // WordPress メタデータを保存
            $this->saveCategoryMetadata($categoryId, $wxrCategory);

            // フルテキスト検索用データを保存
            Common::saveFulltext('cid', $categoryId, Common::loadCategoryFulltext($categoryId));

            // カテゴリー作成成功

            return intval($categoryId);

        } catch (\Throwable $th) {
            Logger::error('【WPImport plugin】カテゴリーの作成に失敗しました（一段階登録）', Common::exceptionArray($th, [
                'wp_term_id' => $wxrCategory->termId,
                'name' => $wxrCategory->getDisplayName(),
                'parent_id' => $parentId,
                'optimization_failed' => 'single_step_creation'
            ]));
            return null;
        }
    }


    /**
     * 既存のカテゴリーを検索
     *
     * @param string $slug
     * @param int $blogId
     * @return int|null
     */
    private function findExistingCategory(string $slug, int $blogId): ?int
    {
        $sql = SQL::newSelect('category');
        $sql->addSelect('category_id');
        $sql->addWhereOpr('category_blog_id', $blogId);
        $sql->addWhereOpr('category_code', $slug);
        $sql->setLimit(1);

        $result = Database::query($sql->get(dsn()), 'one');
        return $result ? intval($result) : null;
    }


    /**
     * カテゴリーコードを生成
     *
     * @param string $slug
     * @param int $blogId
     * @return string
     */
    private function generateCategoryCode(string $slug, int $blogId): string
    {
        return CodeGenerator::generateUniqueCategoryCode(
            $slug,
            $blogId,
            $this->isCategoryCodeExists(...)
        );
    }

    /**
     * カテゴリーコードの重複チェック
     *
     * @param string $code
     * @param int $blogId
     * @return bool
     */
    private function isCategoryCodeExists(string $code, int $blogId): bool
    {
        $sql = SQL::newSelect('category');
        $sql->addSelect('*', null, null, 'COUNT');
        $sql->addWhereOpr('category_blog_id', $blogId);
        $sql->addWhereOpr('category_code', $code);

        return intval(Database::query($sql->get(dsn()), 'one')) > 0;
    }

    /**
     * 親カテゴリーを考慮した次のソート順を取得
     *
     * @param int $blogId
     * @param int $parentId
     * @return int
     */
    private function getNextCategorySort(int $blogId, int $parentId): int
    {
        $sql = SQL::newSelect('category');
        $sql->addSelect('category_sort');
        $sql->addWhereOpr('category_blog_id', $blogId);
        $sql->addWhereOpr('category_parent', $parentId);
        $sql->setOrder('category_sort', 'DESC');
        $sql->setLimit(1);
        $sort = Database::query($sql->get(dsn()), 'one');
        return $sort ? $sort + 1 : 1;
    }

    /**
     * 親カテゴリーを考慮した次のleft/right値を取得（Nested Setモデル）
     *
     * @param int $blogId
     * @param int $parentId 親カテゴリーID（0の場合はルート）
     * @return array{int, int} [left, right]
     */
    private function getNextLeftRight(int $blogId, int $parentId): array
    {
        if ($parentId === 0) {
            // ルートレベル: 最後のrightの次の位置
            $sql = SQL::newSelect('category');
            $sql->addSelect('category_right');
            $sql->addWhereOpr('category_blog_id', $blogId);
            $sql->addWhereOpr('category_parent', 0);
            $sql->setOrder('category_right', 'DESC');
            $sql->setLimit(1);

            if ($row = Database::query($sql->get(dsn()), 'row')) {
                $left = $row['category_right'] + 1;
                $right = $row['category_right'] + 2;
            } else {
                // 初回カテゴリー
                $left = 1;
                $right = 2;
            }
        } else {
            // 子カテゴリー: 親のright位置に挿入し、以降のleft/rightを更新
            $sql = SQL::newSelect('category');
            $sql->addSelect('category_right');
            $sql->addWhereOpr('category_id', $parentId);
            $sql->addWhereOpr('category_blog_id', $blogId);
            $parentRight = Database::query($sql->get(dsn()), 'one');

            if (!$parentRight) {
                throw new \Exception("親カテゴリー(ID: {$parentId})が見つかりません");
            }

            // 既存カテゴリーのleft/rightを更新（親のright以降を+2）
            $this->updateNestedSetForInsertion($blogId, $parentRight);

            // 新しいカテゴリーは親のright位置に配置
            $left = $parentRight;
            $right = $parentRight + 1;
        }

        return [$left, $right];
    }

    /**
     * Nested Set挿入のために既存のleft/right値を更新
     *
     * @param int $blogId
     * @param int $insertPosition
     */
    private function updateNestedSetForInsertion(int $blogId, int $insertPosition): void
    {
        // left値の更新
        $sql = SQL::newUpdate('category');
        $sql->addUpdate('category_left', 'category_left + 2');
        $sql->addWhereOpr('category_blog_id', $blogId);
        $sql->addWhere(SQL::newOpr('category_left', $insertPosition, '>='));
        Database::query($sql->get(dsn()), 'exec');

        // right値の更新
        $sql = SQL::newUpdate('category');
        $sql->addUpdate('category_right', 'category_right + 2');
        $sql->addWhereOpr('category_blog_id', $blogId);
        $sql->addWhere(SQL::newOpr('category_right', $insertPosition, '>='));
        Database::query($sql->get(dsn()), 'exec');
    }

    /**
     * カテゴリーのWordPressメタデータを保存
     *
     * @param int $categoryId
     * @param array{
     *     term_id: int,
     *     slug: string,
     *     name: string,
     *     description?: string,
     *     parent?: int,
     *     taxonomy: string
     * } $wpCategory
     */
    private function saveCategoryMetadata(int $categoryId, WXRCategory $wxrCategory): void
    {
        $metadata = [
            'wp_import_term_id' => $wxrCategory->termId,
            'wp_import_slug' => $wxrCategory->slug,
            'wp_import_taxonomy' => $wxrCategory->taxonomy,
        ];

        if ($wxrCategory->description !== '') {
            $metadata['wp_import_description'] = $wxrCategory->description;
        }

        $field = new \Field($metadata);
        Common::saveField('cid', $categoryId, $field);
    }
}
