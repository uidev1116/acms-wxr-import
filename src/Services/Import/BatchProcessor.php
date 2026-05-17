<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Import;

use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Application as Container;
use Acms\Plugins\WxrImport\Services\WXR\WXREntry;
use Acms\Plugins\WxrImport\Services\WXR\WXRMedia;
use Acms\Plugins\WxrImport\Services\WXR\WXRCategory;
use Acms\Plugins\WxrImport\Services\Media\Downloader;
use Acms\Plugins\WxrImport\Services\Content\ContentProcessor;
use Acms\Plugins\WxrImport\Services\Helpers\MemoryLimit;
use Acms\Plugins\WxrImport\Services\Import\MediaInfoMap;
use Acms\Plugins\WxrImport\Services\Import\SortValueAllocator;
use Acms\Services\Entry\EntryRepository;

/**
 * 最適化されたバッチ処理システム
 */
class BatchProcessor
{
    /** @var EntryImporter */
    private EntryImporter $entryImporter;

    /** @var MediaImporter */
    private MediaImporter $mediaImporter;

    /** @var CategoryCreator */
    private CategoryCreator $categoryCreator;

    /** @var Downloader */
    private Downloader $downloader;

    /** @var ContentProcessor */
    private ContentProcessor $contentProcessor;

    /** @var int メモリ使用量の上限（バイト） */
    private int $memoryLimit;


    /** @var int バッチサイズの最小値 */
    private const MIN_BATCH_SIZE = 5;

    /** @var int バッチサイズの最大値 */
    private const MAX_BATCH_SIZE = 100;

    public function __construct()
    {
        $this->entryImporter = Container::make(EntryImporter::class);
        $this->mediaImporter = Container::make(MediaImporter::class);
        $this->categoryCreator = Container::make(CategoryCreator::class);
        $this->downloader = Container::make(Downloader::class);
        $this->contentProcessor = Container::make(ContentProcessor::class);

        // PHP の memory_limit をバイト換算した実上限
        $this->memoryLimit = MemoryLimit::inBytes();
    }

    /**
     * 全データの統合処理（カテゴリー、メディア、エントリー）
     *
     * @param array<WXREntry> $entries
     * @param array<WXRMedia> $medias
     * @param array<WXRCategory> $categories
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param \Acms\Services\Common\Logger $progressLogger
     * @return array{
     *     entry_success: int,
     *     entry_error: int,
     *     media_success: int,
     *     media_error: int,
     *     category_success: int,
     *     total_time: float,
     *     memory_peak: int
     * }
     */
    public function processAll(
        array $entries,
        array $medias,
        array $categories,
        array $settings,
        \Acms\Services\Common\Logger $progressLogger
    ): array {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $results = [
            'entry_success' => 0,
            'entry_error' => 0,
            'media_success' => 0,
            'media_error' => 0,
            'category_success' => 0,
            'total_time' => 0,
            'memory_peak' => 0,
        ];

        try {
            // 0. Downloader に管理画面の詳細設定を適用
            $this->downloader->configure([
                'local_path_base' => $settings['local_path_base'] ?? '',
                'allowed_private_hosts' => $settings['allowed_private_hosts'] ?? '',
            ]);

            // 1. カテゴリー作成（最初に実行）
            $categoryMap = [];
            if ($settings['create_categories'] && count($categories) > 0) {
                $progressLogger->addMessage('カテゴリーを作成中...', 5, 1, false);
                $categoryMap = $this->processCategoryCreation($categories, $settings);
                $results['category_success'] = count($categoryMap);
                $progressLogger->addMessage('カテゴリー作成完了: ' . count($categoryMap) . '件', 5, 1, true);
            }

            // 2. 既存のprocessCompleteメソッドを呼び出し
            $processResults = $this->processComplete($entries, $medias, $settings, $categoryMap, $progressLogger);

            // 結果をマージ
            $results['entry_success'] = $processResults['entry_success'];
            $results['entry_error'] = $processResults['entry_error'];
            $results['media_success'] = $processResults['media_success'];
            $results['media_error'] = $processResults['media_error'];
            $results['total_time'] = microtime(true) - $startTime;
            $results['memory_peak'] = memory_get_peak_usage(true) - $startMemory;

            return $results;

        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】統合処理エラー', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);
            throw $th;
        }
    }

    /**
     * エントリーと関連メディアを統合処理（既存メソッド）
     *
     * @param array<WXREntry> $entries
     * @param array<WXRMedia> $medias
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param array<int, int> $categoryMap
     * @param \Acms\Services\Common\Logger $progressLogger
     * @return array{
     *     entry_success: int,
     *     entry_error: int,
     *     media_success: int,
     *     media_error: int,
     *     total_time: float,
     *     memory_peak: int
     * }
     */
    public function processComplete(
        array $entries,
        array $medias,
        array $settings,
        array $categoryMap,
        \Acms\Services\Common\Logger $progressLogger
    ): array {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $results = [
            'entry_success' => 0,
            'entry_error' => 0,
            'media_success' => 0,
            'media_error' => 0,
            'total_time' => 0,
            'memory_peak' => 0,
        ];

        try {
            // メディアファイルを優先して処理（エントリー処理でURL書き換えに必要）
            if ($settings['include_media'] && count($medias) > 0) {
                $mediaResults = $this->processMediaBatch($medias, $settings, $progressLogger);
                $results['media_success'] = $mediaResults['success_count'];
                $results['media_error'] = $mediaResults['error_count'];

                // メディアマッピングを構築
                $mediaMapping = $this->buildMediaMapping($mediaResults['results']);
            } else {
                $mediaMapping = [];
            }

            // ContentProcessor の N+1 を避けるため、エントリー処理に入る前に
            // mediaMapping に含まれる media_id をまとめて取得しておく。
            $this->contentProcessor->setMediaInfoMap(MediaInfoMap::load($mediaMapping));

            // エントリーを処理
            $entryResults = $this->processEntryBatch(
                $entries,
                $settings,
                $categoryMap,
                $mediaMapping,
                $progressLogger
            );
            $results['entry_success'] = $entryResults['success_count'];
            $results['entry_error'] = $entryResults['error_count'];

        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】バッチ処理中の致命的エラー', Common::exceptionArray($th));
            $progressLogger->error('バッチ処理中に致命的エラーが発生しました: ' . $th->getMessage());
        }

        $results['total_time'] = microtime(true) - $startTime;
        $results['memory_peak'] = memory_get_peak_usage(true) - $startMemory;

        return $results;
    }

    /**
     * エントリーのバッチ処理
     *
     * @param array<WXREntry> $entries
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @param array<int, int> $categoryMap
     * @param array<int, int> $mediaMapping
     * @param \Acms\Services\Common\Logger $progressLogger
     * @return array{
     *     success_count: int,
     *     error_count: int,
     *     results: array<array{success: bool, entry_id?: int, error?: string}>
     * }
     */
    private function processEntryBatch(
        array $entries,
        array $settings,
        array $categoryMap,
        array $mediaMapping,
        \Acms\Services\Common\Logger $progressLogger
    ): array {
        $totalEntries = count($entries);
        $batchSize = $this->optimizeBatchSize($settings['batch_size'], count($entries));
        $successCount = 0;
        $errorCount = 0;
        $results = [];

        $progressLogger->addMessage("エントリー処理開始: {$totalEntries}件", 0, 1, false);

        $entryRepository = Container::make('entry.repository');
        assert($entryRepository instanceof EntryRepository);

        foreach (array_chunk($entries, $batchSize) as $batchIndex => $batch) {
            $batchStartTime = microtime(true);

            $progressLogger->addMessage(
                "エントリーバッチ " . ($batchIndex + 1) . "/" . ceil($totalEntries / $batchSize) . " 処理中",
                0, 1, false
            );

            // バッチ先頭で sort 値の MAX を1回だけ取得し、以降はメモリ上で払い出す
            $allocator = new SortValueAllocator((int) $settings['target_blog_id'], $entryRepository);

            foreach ($batch as $entry) {
                try {
                    // コンテンツ処理を適用（メディアマッピングがなくても実行）
                    $this->applyContentProcessing($entry, $mediaMapping);

                    $result = $this->entryImporter->importEntry($entry, $settings, $categoryMap, $mediaMapping, $allocator);
                    $results[] = $result;

                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $errorCount++;
                        // エントリー処理失敗
                    }
                } catch (\Throwable $th) {
                    $errorCount++;
                    Logger::error('【WXRImport plugin】エントリー処理エラー', Common::exceptionArray($th, [
                        'wp_post_id' => $entry->wpPostId,
                        'title' => $entry->title,
                    ]));
                }
            }

            $batchTime = microtime(true) - $batchStartTime;
            $processedCount = ($batchIndex + 1) * $batchSize;
            $processedCount = min($processedCount, $totalEntries);

            $progressLogger->addMessage(
                "処理済み: {$processedCount}/{$totalEntries} (成功: {$successCount}, エラー: {$errorCount}) - 処理時間: " . number_format($batchTime, 2) . "秒",
                (50 / ceil($totalEntries / $batchSize)), 1, true
            );

            // バッチ間のポーズ（設定で延長可能、デフォルト 0）
            if ($batchIndex < ceil($totalEntries / $batchSize) - 1) {
                $pause = (int) (config('wxr_import_batch_pause_microseconds') ?: 0);
                if ($pause > 0) {
                    usleep($pause);
                }
            }
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'results' => $results,
        ];
    }

    /**
     * メディアのバッチ処理
     *
     * @param array<WXRMedia> $medias
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     target_blog_id: int
     * } $settings
     * @param \Acms\Services\Common\Logger $progressLogger
     * @return array{
     *     success_count: int,
     *     error_count: int,
     *     results: array<array{
     *         wp_post_id: int,
     *         success: bool,
     *         media_id?: int,
     *         path?: string,
     *         error?: string
     *     }>
     * }
     */
    private function processMediaBatch(array $medias, array $settings, \Acms\Services\Common\Logger $progressLogger): array
    {
        $totalMedia = count($medias);
        $batchSize = min($this->optimizeBatchSize($settings['batch_size'], count($medias)), 10); // メディアは小さめのバッチ
        $successCount = 0;
        $errorCount = 0;
        $results = [];

        // メディア処理開始
        $progressLogger->addMessage("メディア処理開始: {$totalMedia}件", 0, 1, false);

        foreach (array_chunk($medias, $batchSize) as $batchIndex => $batch) {
            $progressLogger->addMessage(
                "メディアバッチ " . ($batchIndex + 1) . "/" . ceil($totalMedia / $batchSize) . " 処理中",
                0, 1, false
            );

            foreach ($batch as $media) {
                try {
                    // ダウンロード
                    $downloadResult = $this->downloader->downloadMedia($media);

                    if (!$downloadResult['success']) {
                        $errorCount++;
                        $results[] = [
                            'wp_post_id' => $media->wpPostId,
                            'success' => false,
                            'error' => $downloadResult['error']
                        ];
                        continue;
                    }

                    // インポート
                    $importResult = $this->mediaImporter->importMedia(
                        $media,
                        $settings,
                        $downloadResult['local_path']
                    );

                    $results[] = [
                        'wp_post_id' => $media->wpPostId,
                        'success' => $importResult['success'],
                        'media_id' => $importResult['media_id'] ?? null,
                        'path' => $importResult['path'] ?? null,
                        'error' => $importResult['error'] ?? null,
                    ];

                    if ($importResult['success']) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }

                } catch (\Throwable $th) {
                    $errorCount++;
                    $results[] = [
                        'wp_post_id' => $media->wpPostId,
                        'success' => false,
                        'error' => $th->getMessage()
                    ];
                    Logger::error('【WXRImport plugin】メディア処理エラー', Common::exceptionArray($th, [
                        'wp_post_id' => $media->wpPostId,
                    ]));
                }

                // メディア処理間の遅延は Downloader::applyRateLimit() に委譲（HTTP 経路のみ）。
                // ローカルパス取り込みでは無条件の usleep を発生させない。
            }

            $processedCount = ($batchIndex + 1) * $batchSize;
            $processedCount = min($processedCount, $totalMedia);

            $progressLogger->addMessage(
                "メディア処理済み: {$processedCount}/{$totalMedia} (成功: {$successCount}, エラー: {$errorCount})",
                (25 / ceil($totalMedia / $batchSize)), 1, true
            );

            // バッチ間の遅延は Downloader::applyRateLimit() に委譲する。
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'results' => $results,
        ];
    }

    /**
     * エントリーにコンテンツ処理を適用
     *
     * @param WXREntry $entry
     * @param array<int, int> $mediaMapping
     */
    private function applyContentProcessing(WXREntry $entry, array $mediaMapping): void
    {
        try {

            $originalContent = $entry->content;
            $processResult = $this->contentProcessor->processContent($entry->content, [
                'media_mapping' => $mediaMapping,
                '_original_content' => $originalContent
            ]);

            $entry->content = $processResult['content'];


        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】コンテンツ処理エラー', Common::exceptionArray($th, [
                'wp_post_id' => $entry->wpPostId,
                'title' => $entry->title,
            ]));
        }
    }

    /**
     * メディア処理結果からマッピングを構築
     *
     * @param array<array{
     *     wp_post_id: int,
     *     success: bool,
     *     media_id?: int,
     *     path?: string,
     *     error?: string
     * }> $results
     * @return array<int, int>
     */
    private function buildMediaMapping(array $results): array
    {
        $mapping = [];
        foreach ($results as $result) {
            if ($result['success'] && isset($result['media_id'])) {
                $mapping[$result['wp_post_id']] = $result['media_id'];
            }
        }
        return $mapping;
    }

    /**
     * バッチサイズを最適化
     *
     * @param int $requestedSize
     * @param int $totalItems
     * @return int
     */
    private function optimizeBatchSize(int $requestedSize, int $totalItems): int
    {
        // 管理画面で指定されたサイズを尊重しつつ、メモリ逼迫時のみ縮小する。
        // memory_limit=-1 のときは PHP_INT_MAX が返るため、availableMemory は常に潤沢扱いになる。
        $memoryUsage = memory_get_usage(true);
        $availableMemory = $this->memoryLimit - $memoryUsage;

        if ($availableMemory < (int) ($this->memoryLimit * 0.20)) {
            // 残メモリ 20% 未満なら半減
            $adjustedSize = max(self::MIN_BATCH_SIZE, intval($requestedSize * 0.5));
        } else {
            $adjustedSize = $requestedSize;
        }

        // 最小値と「要求値そのもの」で挟む（増加方向には伸ばさない）
        $adjustedSize = max(self::MIN_BATCH_SIZE, min($requestedSize, $adjustedSize));

        // 総数がバッチサイズより小さい場合は調整
        return $totalItems > 0 ? min($adjustedSize, $totalItems) : $adjustedSize;
    }

    /**
     * カテゴリー作成処理
     *
     * @param array<WXRCategory> $categories
     * @param array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int
     * } $settings
     * @return array<int, int> WordPress カテゴリーIDからa-blog cms カテゴリーIDへのマッピング
     */
    private function processCategoryCreation(array $categories, array $settings): array
    {
        try {

            $categoryMap = $this->categoryCreator->createCategories($categories, $settings);


            return $categoryMap;

        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】カテゴリー作成エラー', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'categories' => array_map(function($cat) {
                    return ['id' => $cat->termId, 'name' => $cat->name];
                }, $categories)
            ]);

            // エラーが発生した場合は空のマッピングを返す
            return [];
        }
    }
}
