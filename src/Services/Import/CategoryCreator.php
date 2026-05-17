<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Import;

use ACMS_RAM;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use Acms\Plugins\WxrImport\Services\WXR\WXRCategory;
use Acms\Plugins\WxrImport\Services\Helpers\CodeGenerator;
use SQL;

/**
 * カテゴリーの自動作成機能
 */
class CategoryCreator
{
    /**
     * WordPressカテゴリーからa-blog cmsカテゴリーを作成
     *
     * @param array<Acms\Plugins\WxrImport\Services\WXR\WXRCategory> $categories
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
        $blogId = (int) $settings['target_blog_id'];

        // 階層構造を考慮した順序で処理
        $sortedCategories = $this->sortCategoriesByHierarchy($categories);

        // 既存と新規を切り分ける
        $toCreate = [];
        foreach ($sortedCategories as $category) {
            try {
                $existingId = $this->findExistingCategory($category->generateCode(), $blogId);
                if ($existingId) {
                    $mapping[$category->termId] = $existingId;
                    continue;
                }
                $toCreate[] = $category;
            } catch (\Throwable $th) {
                Logger::error('【WXRImport plugin】既存カテゴリー判定に失敗しました', Common::exceptionArray($th, [
                    'wp_term_id' => $category->termId,
                    'name' => $category->name,
                ]));
            }
        }

        if ($toCreate === []) {
            return $mapping;
        }

        // 全ての親が「新規カテゴリ集合内」または「ルート (parent=0)」なら、
        // 既存ツリーへの干渉なしで一括 INSERT できる（最もよくあるケース＝新規ブログへの初回取り込み）。
        // 既存カテゴリを親とする新規がある場合は、Nested Set の途中挿入が必要なため
        // 安全側に倒して旧フロー（1件ずつ作成）にフォールバックする。
        $newTermIds = [];
        foreach ($toCreate as $c) {
            if ($c->termId !== null) {
                $newTermIds[$c->termId] = true;
            }
        }
        $fastPathOk = true;
        foreach ($toCreate as $c) {
            $parentId = $c->parentId ?? 0;
            if ($parentId !== 0 && !isset($newTermIds[$parentId])) {
                $fastPathOk = false;
                break;
            }
        }

        if ($fastPathOk) {
            try {
                $this->createCategoriesBulk($toCreate, $mapping, $blogId);
                return $mapping;
            } catch (\Throwable $th) {
                Logger::error('【WXRImport plugin】カテゴリー一括作成に失敗、1件ずつ作成にフォールバックします', Common::exceptionArray($th, [
                    'count' => count($toCreate),
                ]));
                // フォールバックへ
            }
        }

        // フォールバック: 1件ずつ既存ロジックで作成（既存ツリー途中挿入の場合もこちら）
        foreach ($toCreate as $category) {
            try {
                $categoryId = $this->createCategory($category, $mapping, $blogId);
                if ($categoryId) {
                    $mapping[$category->termId] = $categoryId;
                }
            } catch (\Throwable $th) {
                Logger::error('【WXRImport plugin】カテゴリー作成に失敗しました', Common::exceptionArray($th, [
                    'wp_term_id' => $category->termId,
                    'name' => $category->name,
                ]));
            }
        }

        return $mapping;
    }

    /**
     * 新規カテゴリ群を一括 INSERT する高速経路。
     *
     * 前提:
     *   - 全カテゴリの親が「新規カテゴリ集合内」または「parent=0（ルート）」であること。
     *     既存カテゴリを親とするノードが含まれる場合、本メソッドは呼ばれない（呼び出し元でフォールバック）。
     *   - $toCreate はトポロジカル順（親 → 子の順）でソート済み。
     *
     * 既存ツリーへの干渉:
     *   - 既存ツリーの最大 right 値を 1 回 SELECT で取得し、それより後ろに新規ツリーを丸ごと追加する。
     *   - 既存行に対する UPDATE は発生しない。
     *
     * @param list<WXRCategory> $toCreate
     * @param array<int, int>   $mapping  termId → newCategoryId
     */
    private function createCategoriesBulk(array $toCreate, array &$mapping, int $blogId): void
    {
        // 子のインデックスを termId で引けるよう構築（0 = ルート）
        /** @var array<int, list<WXRCategory>> $children */
        $children = [];
        $roots = [];
        foreach ($toCreate as $c) {
            $pid = $c->parentId ?? 0;
            if ($pid === 0 || !isset($children[$pid])) {
                // initialize list
            }
            if ($pid === 0) {
                $roots[] = $c;
            } else {
                $children[$pid][] = $c;
            }
        }

        // 既存ツリーの右端を取得
        $existingMaxRight = $this->getMaxCategoryRight($blogId);
        $counter = $existingMaxRight;

        /** @var list<array{id:int, parent:int, left:int, right:int, sort:int, category:WXRCategory}> $records */
        $records = [];

        // 各カテゴリの新 ID を先に確保し、mapping に積んでおく（子の処理時に親 ID を参照するため）
        $idByTermId = [];
        foreach ($toCreate as $c) {
            $newId = (int) Database::query(SQL::nextval('category_id', dsn()), 'seq');
            $idByTermId[$c->termId] = $newId;
            $mapping[$c->termId] = $newId;
        }

        // DFS で left/right を計算し $records に追記する
        $rootSort = 1;
        foreach ($roots as $root) {
            $this->assignNestedSet($root, 0, $rootSort, $children, $idByTermId, $counter, $records);
            $rootSort++;
        }

        // 全カテゴリを一括 INSERT
        $bulk = SQL::newBulkInsert('category');
        foreach ($records as $r) {
            $cat = $r['category'];
            $code = $this->generateCategoryCode($cat->generateCode(), $blogId);
            $bulk->addInsert([
                'category_id'              => $r['id'],
                'category_parent'          => $r['parent'],
                'category_sort'            => $r['sort'],
                'category_left'            => $r['left'],
                'category_right'           => $r['right'],
                'category_blog_id'         => $blogId,
                'category_status'          => 'open',
                'category_name'            => $cat->getDisplayName(),
                'category_scope'           => 'local',
                'category_indexing'        => 'on',
                'category_code'            => $code,
                'category_config_set_id'   => null,
                'category_config_set_scope' => 'local',
                'category_theme_set_id'    => null,
                'category_theme_set_scope' => 'local',
                'category_editor_set_id'   => null,
                'category_editor_set_scope' => 'local',
            ]);
        }
        if ($bulk->hasData()) {
            Database::query($bulk->get(dsn()), 'exec');
        }

        // メタデータ・フルテキストはコア API に従い1件ずつ書き込む
        foreach ($records as $r) {
            $this->saveCategoryMetadata($r['id'], $r['category']);
            Common::saveFulltext('cid', $r['id'], Common::loadCategoryFulltext($r['id']));
        }
    }

    /**
     * DFS で Nested Set の left/right と sort を割り当て、records に追記する。
     *
     * @param array<int, list<WXRCategory>> $children
     * @param array<int, int> $idByTermId
     * @param list<array{id:int, parent:int, left:int, right:int, sort:int, category:WXRCategory}> $records
     */
    private function assignNestedSet(
        WXRCategory $c,
        int $parentDbId,
        int $sort,
        array $children,
        array $idByTermId,
        int &$counter,
        array &$records
    ): void {
        $counter++;
        $left = $counter;
        $newId = $idByTermId[$c->termId];

        $childList = $children[$c->termId] ?? [];
        $childSort = 1;
        foreach ($childList as $child) {
            $this->assignNestedSet($child, $newId, $childSort, $children, $idByTermId, $counter, $records);
            $childSort++;
        }

        $counter++;
        $right = $counter;

        $records[] = [
            'id' => $newId,
            'parent' => $parentDbId,
            'left' => $left,
            'right' => $right,
            'sort' => $sort,
            'category' => $c,
        ];
    }

    /**
     * 既存カテゴリツリーの最大 right 値を取得
     */
    private function getMaxCategoryRight(int $blogId): int
    {
        $sql = SQL::newSelect('category');
        $sql->addSelect('category_right');
        $sql->addWhereOpr('category_blog_id', $blogId);
        $sql->setOrder('category_right', 'DESC');
        $sql->setLimit(1);
        $value = Database::query($sql->get(dsn()), 'one');
        return $value ? (int) $value : 0;
    }


    /**
     * カテゴリーを階層順にソート
     *
     * @param WXRCategory[] $categories
     * @return WXRCategory[]
     */
    private function sortCategoriesByHierarchy(array $categories): array
    {
        // termId -> WXRCategory の連想配列を作って O(1) ルックアップを可能にする
        $byId = [];
        foreach ($categories as $c) {
            if ($c->termId !== null) {
                $byId[$c->termId] = $c;
            }
        }

        $sorted = [];
        $visited = [];   // termId => true
        $visiting = [];  // termId => true（循環検出用）

        foreach ($categories as $c) {
            $this->topologicalVisit($c, $byId, $visited, $visiting, $sorted);
        }

        return $sorted;
    }

    /**
     * DFS で親 → 子の順に並べる。循環は visiting フラグで検出して末尾扱いにする。
     *
     * @param array<int, WXRCategory> $byId
     * @param array<int, true> $visited
     * @param array<int, true> $visiting
     * @param list<WXRCategory> $sorted
     */
    private function topologicalVisit(
        WXRCategory $c,
        array $byId,
        array &$visited,
        array &$visiting,
        array &$sorted
    ): void {
        $termId = $c->termId;
        if ($termId === null) {
            $sorted[] = $c;
            return;
        }
        if (isset($visited[$termId])) {
            return;
        }
        if (isset($visiting[$termId])) {
            // 循環参照 → 旧実装と同じく末尾追加扱いにする（積極的にエラーは出さない）
            return;
        }
        $visiting[$termId] = true;

        $parentId = $c->parentId ?? 0;
        if ($parentId !== 0 && isset($byId[$parentId])) {
            $this->topologicalVisit($byId[$parentId], $byId, $visited, $visiting, $sorted);
        }

        unset($visiting[$termId]);
        $visited[$termId] = true;
        $sorted[] = $c;
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
            Logger::error('【WXRImport plugin】カテゴリーの作成に失敗しました（一段階登録）', Common::exceptionArray($th, [
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
            'wxr_import_term_id' => $wxrCategory->termId,
            'wxr_import_slug' => $wxrCategory->slug,
            'wxr_import_taxonomy' => $wxrCategory->taxonomy,
        ];

        if ($wxrCategory->description !== '') {
            $metadata['wxr_import_description'] = $wxrCategory->description;
        }

        $field = new \Field($metadata);
        Common::saveField('cid', $categoryId, $field);
    }
}
