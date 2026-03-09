<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Import;

use Acms\Services\Facades\Database;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Application;
use SQL;
use Field;
use Acms\Services\Facades\Logger;
use Acms\Plugins\WPImport\Services\WXR\WXREntry;
use Acms\Plugins\WPImport\Services\Helpers\CodeGenerator;
use Acms\Plugins\WPImport\Services\Unit\ContentUnitCreator;
use Acms\Services\Unit\Repository as UnitRepository;
use ACMS_RAM;

class EntryImporter
{
    /**
     * エントリーをa-blog cmsに移行
     *
     * @param WXREntry $entry
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param array<int, int> $categoryMap WordPress カテゴリーIDからa-blog cms カテゴリーIDへのマッピング
     * @return array{success: bool, entry_id?: int, error?: string}
     */
    public function importEntry(WXREntry $entry, array $settings, array $categoryMap = []): array
    {
        try {
            // 新規エントリーの作成
            return $this->createNewEntry($entry, $settings, $categoryMap);
        } catch (\Throwable $th) {
            Logger::error('【WPImport plugin】エントリーインポートエラー', Common::exceptionArray($th, [
                'wp_post_id' => $entry->wpPostId,
                'title' => $entry->title
            ]));

            return [
                'success' => false,
                'error' => $th->getMessage()
            ];
        }
    }

    /**
     * 新規エントリーを作成
     *
     * @param WXREntry $entry
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param array<int, int> $categoryMap WordPress カテゴリーIDからa-blog cms カテゴリーIDへのマッピング
     * @return array{success: bool, entry_id: int, wp_post_id: int}
     */
    private function createNewEntry(WXREntry $entry, array $settings, array $categoryMap): array
    {
        Database::connection()->beginTransaction();

        try {
            // エントリーデータの作成（カテゴリー含む）
            $eid = $this->insertEntryData($entry, $settings, $categoryMap);
            if (!$eid) {
                throw new \Exception('エントリーデータの挿入に失敗しました');
            }

            // エントリーフィールドの作成
            $this->insertEntryFields($eid, $entry, $settings);

            // サブカテゴリーの関連付け（メインカテゴリー以外）
            if (count($categoryMap) > 0) {
                $mainCategoryId = $this->determineMainCategory($entry, $categoryMap);
                $this->associateSubCategories($eid, $entry, $categoryMap, $mainCategoryId);
            }

            // タグの関連付け
            if ($settings['create_tags']) {
                $this->associateTags($eid, $entry, $settings);
            }

            // WordPress本文をBlockEditorユニットとして保存
            $this->createContentUnit($eid, $entry, $settings);

            Database::connection()->commit();


            return [
                'success' => true,
                'entry_id' => $eid,
                'wp_post_id' => $entry->wpPostId
            ];
        } catch (\Throwable $th) {
            Database::connection()->rollBack();
            throw $th;
        }
    }

    /**
     * エントリーデータをentriesテーブルに挿入
     *
     * @param WXREntry $entry
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param array<int, int> $categoryMap WordPress カテゴリーIDからa-blog cms カテゴリーIDへのマッピング
     * @return int|false
     */
    private function insertEntryData(WXREntry $entry, array $settings, array $categoryMap)
    {
        $sql = SQL::newInsert('entry');

        // 基本情報
        $entryId = (int)Database::query(SQL::nextval('entry_id', dsn()), 'seq');
        $blogId = $settings['target_blog_id'];

        // メインカテゴリーを決定
        $mainCategoryId = $this->determineMainCategory($entry, $categoryMap);

        $sql->addInsert('entry_id', $entryId);
        $sql->addInsert('entry_title', $entry->title);
        $sql->addInsert('entry_status', $entry->status);
        $sql->addInsert('entry_code', $this->generateEntryCode($entry, $blogId, $mainCategoryId));
        $sql->addInsert('entry_blog_id', $blogId);
        $sql->addInsert('entry_category_id', $mainCategoryId);
        $sql->addInsert('entry_user_id', SUID);
        $sql->addInsert('entry_last_update_user_id', SUID);
        $sql->addInsert('entry_hash', md5(SYSTEM_GENERATED_DATETIME . date('Y-m-d H:i:s', REQUEST_TIME)));

        // 日時情報
        if ($entry->postDate) {
            $sql->addInsert('entry_datetime', $entry->postDate->format('Y-m-d H:i:s'));
            $sql->addInsert('entry_posted_datetime', $entry->postDate->format('Y-m-d H:i:s'));
        } else {
            $now = date('Y-m-d H:i:s');
            $sql->addInsert('entry_datetime', $now);
            $sql->addInsert('entry_posted_datetime', $now);
        }
        $sql->addInsert('entry_updated_datetime', date('Y-m-d H:i:s'));

        // その他の属性
        $sql->addInsert('entry_sort', $this->nextEntrySort($blogId));
        $sql->addInsert('entry_user_sort', $this->nextEntryUserSort(SUID, $blogId));
        $sql->addInsert('entry_category_sort', $this->nextEntryCategorySort($mainCategoryId, $blogId));

        Database::query($sql->get(dsn()), 'exec');

        return $entryId;
    }

    /**
     * エントリーフィールドを挿入
     *
     * @param int $eid
     * @param WXREntry $entry
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     */
    private function insertEntryFields(int $eid, WXREntry $entry, array $settings): void
    {
        $field = new Field();

        // SEO関連フィールド
        if (($entry->seoData['yoast_title'] ?? '') !== '') {
            $field->setField('entry_meta_title', $entry->seoData['yoast_title']);
        }
        if (($entry->seoData['yoast_description'] ?? '') !== '') {
            $field->setField('entry_meta_description', $entry->seoData['yoast_description']);
        }

        // カスタムフィールド
        foreach ($entry->customFields as $key => $value) {
            if ($value !== null && $value !== '') {
                $fieldName = $this->sanitizeFieldName($key);
                $field->setField($fieldName, $value);
            }
        }

        // WordPressメタデータ
        $field->setField('wp_post_id', $entry->wpPostId);
        $field->setField('wp_guid', $entry->guid);
        $field->setField('wp_post_type', $entry->type);
        if ($entry->featuredMediaId) {
            $field->setField('wp_featured_media_id', $entry->featuredMediaId);
        }

        // フィールドデータを保存
        Common::saveField('eid', $eid, $field);
    }

    /**
     * メインカテゴリーを決定
     *
     * @param WXREntry $entry
     * @param array<int, int> $categoryMap WordPress カテゴリーIDからa-blog cms カテゴリーIDへのマッピング
     * @return int|null メインカテゴリーID（見つからない場合はnull）
     */
    private function determineMainCategory(WXREntry $entry, array $categoryMap): ?int
    {
        if (count($entry->categories) === 0 || count($categoryMap) === 0) {
            return null;
        }

        // 最初のカテゴリーをメインカテゴリーとして使用
        foreach ($entry->categories as $category) {
            if (isset($categoryMap[$category->termId])) {
                return $categoryMap[$category->termId];
            }
        }

        return null;
    }

    /**
     * サブカテゴリーを関連付け（メインカテゴリー以外）
     *
     * @param int $eid
     * @param WXREntry $entry
     * @param array<int, int> $categoryMap WordPress カテゴリーIDからa-blog cms カテゴリーIDへのマッピング
     * @param int|null $mainCategoryId メインカテゴリーID
     */
    private function associateSubCategories(int $eid, WXREntry $entry, array $categoryMap, ?int $mainCategoryId): void
    {
        $categoryIds = [];

        // WordPressカテゴリーをa-blog cmsカテゴリーIDに変換
        foreach ($entry->categories as $category) {
            if (isset($categoryMap[$category->termId])) {
                $categoryIds[] = $categoryMap[$category->termId];
            }
        }

        // 重複を除去
        $categoryIds = array_unique($categoryIds);

        if (count($categoryIds) <= 1) {
            // メインカテゴリーのみまたはカテゴリーなしの場合は何もしない
            return;
        }

        $blogId = ACMS_RAM::entryBlog($eid);

        // メインカテゴリー以外をサブカテゴリーとして関連付け
        $subCategoryIds = array_filter($categoryIds, function ($id) use ($mainCategoryId) {
            return $id !== $mainCategoryId;
        });

        foreach ($subCategoryIds as $categoryId) {
            $sql = SQL::newInsert('entry_sub_category');
            $sql->addInsert('entry_sub_category_eid', $eid);
            $sql->addInsert('entry_sub_category_id', $categoryId);
            $sql->addInsert('entry_sub_category_blog_id', $blogId);
            Database::query($sql->get(dsn()), 'exec');
        }
    }


    /**
     * タグを関連付け
     *
     * @param int $eid
     * @param WXREntry $entry
     * @param array{
     *     create_tags: bool,
     * } $settings
     */
    private function associateTags(int $eid, WXREntry $entry, array $settings): void
    {
        if (count($entry->tags) === 0) {
            return;
        }
        if (!$settings['create_tags']) {
            return;
        }

        $blogId = ACMS_RAM::entryBlog($eid);

        $sort = 0;
        foreach ($entry->tags as $tag) {
            if (!$tag->isValid()) {
                continue;
            }

            $data = $tag->toAcmsTagArray($eid, $blogId, $sort);

            $sql = SQL::newInsert('tag');
            $sql->addInsert('tag_name', $data['tag_name']);
            $sql->addInsert('tag_entry_id', $data['tag_entry_id']);
            $sql->addInsert('tag_blog_id', $data['tag_blog_id']);
            $sql->addInsert('tag_sort', $data['tag_sort']);

            Database::query($sql->get(dsn()), 'exec');
            $sort++;
        }
    }

    /**
     * エントリーコードを生成
     *
     * @param WXREntry $entry
     * @param int $blogId
     * @param int|null $categoryId
     * @return string
     */
    private function generateEntryCode(WXREntry $entry, int $blogId, ?int $categoryId): string
    {
        return CodeGenerator::generateUniqueEntryCode(
            $entry->postName,
            $entry->title,
            $entry->wpPostId,
            $blogId,
            $categoryId,
            $this->isEntryCodeExists(...)
        );
    }

    /**
     * エントリーコードの重複チェック
     *
     * @param string $code エントリーコード
     * @param int $blogId ブログID
     * @param int|null $categoryId カテゴリーID（nullの場合はブログ内でのみチェック）
     * @return bool 存在する場合true
     */
    private function isEntryCodeExists(string $code, int $blogId, ?int $categoryId): bool
    {
        $sql = SQL::newSelect('entry');
        $sql->addSelect('*', null, null, 'COUNT');
        $sql->addWhereOpr('entry_blog_id', $blogId);
        $sql->addWhereOpr('entry_code', $code);

        if ($categoryId !== null) {
            $sql->addWhereOpr('entry_category_id', $categoryId);
        }

        return intval(Database::query($sql->get(dsn()), 'one')) > 0;
    }

    /**
     * フィールド名をサニタイズ
     *
     * @param string $fieldName
     * @return string
     */
    private function sanitizeFieldName(string $fieldName): string
    {
        // WordPressプレフィックスを除去
        $fieldName = ltrim($fieldName, '_');

        // a-blog cms用に変換
        $fieldName = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName);

        return 'wp_' . $fieldName;
    }

    /**
     * 次のエントリー表示順を取得
     *
     * @param int $blogId
     *
     * @return int
     **/
    private function nextEntrySort(int $blogId): int
    {
        $entryRepository = Application::make('entry.repository');
        assert($entryRepository instanceof \Acms\Services\Entry\EntryRepository);
        return $entryRepository->nextSort($blogId);
    }

    /**
     * 次のエントリーのユーザー絞り込み時の表示順を取得
     *
     * @param int $userId
     * @param int $blogId
     * @return int
     **/
    private function nextEntryUserSort(int $userId, int $blogId): int
    {
        $entryRepository = Application::make('entry.repository');
        assert($entryRepository instanceof \Acms\Services\Entry\EntryRepository);
        return $entryRepository->nextUserSort($userId, $blogId);
    }

    /**
     * 次のエントリーのカテゴリー絞り込み時の表示順を取得
     *
     * @param int|null $categoryId
     * @param int $blogId
     *
     * @return int
     **/
    private function nextEntryCategorySort(?int $categoryId, int $blogId): int
    {
        $entryRepository = Application::make('entry.repository');
        assert($entryRepository instanceof \Acms\Services\Entry\EntryRepository);
        return $entryRepository->nextCategorySort($categoryId, $blogId);
    }

    /**
     * WordPress本文をBlockEditorユニットとして作成
     *
     * @param int $eid エントリーID
     * @param WXREntry $entry WordPressエントリー
     * @param array{target_blog_id: int} $settings インポート設定
     * @return void
     */
    private function createContentUnit(int $eid, WXREntry $entry, array $settings): void
    {
        try {
            // UnitRepositoryを取得
            $unitRepository = Application::make('unit-repository');
            assert($unitRepository instanceof UnitRepository);

            // ContentUnitCreatorを作成
            $contentUnitCreator = new ContentUnitCreator($unitRepository);

            // BlockEditorユニットを作成
            $success = $contentUnitCreator->createContentUnit($entry, $eid, $settings['target_blog_id']);

            if (!$success) {
            }
        } catch (\Throwable $th) {
            Logger::error('【WPImport plugin】ユニット作成でエラーが発生', Common::exceptionArray($th, [
                'eid' => $eid,
                'wp_post_id' => $entry->wpPostId
            ]));

            // ユニット作成エラーはエントリー作成を中断しない
            // （エントリー自体は作成されているため）
        }
    }
}
