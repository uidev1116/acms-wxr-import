<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport\Services\Unit;

use Acms\Services\Unit\Repository;
use Acms\Services\Unit\Models\BlockEditor;
use Acms\Services\Unit\UnitCollection;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Common;
use Acms\Plugins\WPImport\Services\WXR\WXREntry;

class ContentUnitCreator
{
    private Repository $unitRepository;

    public function __construct(Repository $unitRepository)
    {
        $this->unitRepository = $unitRepository;
    }

    /**
     * WordPressコンテンツからBlockEditorユニットを作成
     *
     * @param WXREntry $entry
     * @param int $eid エントリーID
     * @param int $bid ブログID
     * @return bool 作成成功時はtrue
     */
    public function createContentUnit(WXREntry $entry, int $eid, int $bid): bool
    {
        try {
            // 本文が空の場合は何もしない
            if (trim($entry->content) === '') {
                // 本文が空のためユニット作成をスキップ
                return true;
            }

            // BlockEditorユニットを作成
            $blockEditorUnit = $this->unitRepository->makeModel('block-editor');
            if (!$blockEditorUnit instanceof BlockEditor) {
                throw new \Exception('BlockEditorユニットの作成に失敗しました');
            }

            // ユニットIDを生成
            $unitId = $blockEditorUnit->generateNewIdTrait();
            $blockEditorUnit->setId($unitId);

            // 本文HTMLを前処理して設定
            $processedContent = $this->processContent($entry->content, $entry);
            $blockEditorUnit->setField1($processedContent);

            // ユニットの基本設定
            $blockEditorUnit->setEntryId($eid);
            $blockEditorUnit->setBlogId($bid);
            $blockEditorUnit->setSort(1); // 最初のユニットとして配置
            $blockEditorUnit->setStatus(\Acms\Services\Unit\Constants\UnitStatus::OPEN);

            // ユニットコレクションを作成
            $collection = new UnitCollection([$blockEditorUnit]);

            // ユニットを保存
            $this->unitRepository->saveAllUnits($collection, $eid, $bid);


            return true;
        } catch (\Throwable $th) {
            // BlockEditorユニット作成エラーを無視

            return false;
        }
    }

    /**
     * WordPressコンテンツの前処理
     *
     * @param string $content WordPress本文HTML
     * @param WXREntry $entry エントリー情報（将来的なURL書き換えなどに使用）
     * @return string 前処理されたHTML
     */
    private function processContent(string $content, WXREntry $entry): string
    {
        // HTMLの基本的なサニタイズ（既にEntryExtractorで実施済みだが念のため）
        $processedContent = trim($content);

        // 将来的な拡張ポイント：
        // - WordPress特有のショートコードの処理
        // - メディアURLの書き換え
        // - 内部リンクの調整
        // - ブロック構造の最適化

        return $processedContent;
    }
}
