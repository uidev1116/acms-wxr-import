<?php

namespace Acms\Plugins\WxrImport\POST\WxrImport;

use ACMS_POST;
use Acms\Services\Facades\Application;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Acms\Plugins\WxrImport\Services\WXR\Parser;
use Acms\Plugins\WxrImport\Services\WXR\EntryExtractor;
use Acms\Plugins\WxrImport\Services\WXR\WXRCategory;
use Acms\Plugins\WxrImport\Services\Import\BatchProcessor;

class Execute extends ACMS_POST
{
    private Parser $parser;
    private EntryExtractor $entryExtractor;
    private \Acms\Plugins\WxrImport\Services\WXR\MediaExtractor $mediaExtractor;
    private BatchProcessor $batchProcessor;

    public function __construct()
    {
        // サービスコンテナからサービスを取得
        $container = Application::getInstance();
        assert($container instanceof \Acms\Services\Container);
        $this->parser = $container->make(Parser::class);
        $this->entryExtractor = $container->make(EntryExtractor::class);
        $this->mediaExtractor = $container->make(\Acms\Plugins\WxrImport\Services\WXR\MediaExtractor::class);
        $this->batchProcessor = $container->make(BatchProcessor::class);
    }

    public function post()
    {
        if (!sessionWithAdministration()) {
            $this->addError('管理者権限が必要です。');
            return $this->Post;
        }

        $file = null;
        try {
            $file = \ACMS_Http::file('wordpress_import_file');
            $file->validateFormat(['xml']);
            $filePath = $file->getPath();
        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】ファイル検証エラー', Common::exceptionArray($th));
            $this->addError('ファイル検証エラー: ' . $th->getMessage());
            return $this->Post;
        }



        $logger = Application::make('common.logger');
        assert($logger instanceof \Acms\Services\Common\Logger);
        $lockService = Application::make('wxr-import.progress-lock');
        assert($lockService instanceof \Acms\Services\Common\Lock);

        if ($lockService->isLocked()) {
            $this->addError('移行処理を中止しました。すでに移行中の可能性があります。');
            return $this->Post;
        }

        try {

            // 実行設定の取得
            $settings = $this->getExecutionSettings();

            // バックグラウンド実行の開始
            set_time_limit(0);
            ini_set('memory_limit', '-1');
            ignore_user_abort(true);

            // レスポンス後にバックグラウンド処理を実行
            Common::backgroundRedirect(HTTP_REQUEST_URL);
            $this->executeImportProcess(
                filePath: $filePath,
                settings: $settings,
                logger: $logger,
                lockService: $lockService
            );
            die();

        } catch (\Exception $e) {
            Logger::error('【WXRImport plugin】移行実行エラー', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->addError('移行実行中にエラーが発生しました: ' . $e->getMessage());
        }

        return $this->Post;
    }

    /**
     * Pass 2: WXR を再ストリームし、attachment 以外の item を WXREntry に変換して yield する。
     *
     * Parser は内部でクリーニング済み一時ファイルを保持しないが、open() を 2 回呼ぶ間に
     * もう一度入力ファイルから一時ファイルを生成するため、本ジェネレータは Pass 1 完了後に
     * 開始される必要がある。BatchProcessor 側はバッファサイズ分だけ受け取って即解放する。
     *
     * @return \Generator<int, \Acms\Plugins\WxrImport\Services\WXR\WXREntry>
     */
    private function streamEntries(string $filePath): \Generator
    {
        foreach ($this->parser->parse($filePath) as $item) {
            if ($item['post_type'] === 'attachment') {
                continue;
            }
            $entry = $this->entryExtractor->extractEntry($item);
            if ($entry !== null) {
                yield $entry;
            }
        }
    }

    /**
     * 実行設定を取得
     *
     * @return array{
     *     batch_size: int,
     *     include_media: bool,
     *     create_categories: bool,
     *     create_tags: bool,
     *     target_blog_id: int,
     *     local_path_base: string,
     *     allowed_private_hosts: string
     * }
     */
    private function getExecutionSettings(): array
    {
        return [
            'batch_size' => (int)($this->Post->get('batch_size') ?: 50),
            'include_media' => $this->Post->get('include_media') === 'on',
            'create_categories' => $this->Post->get('create_categories') === 'on',
            'create_tags' => $this->Post->get('create_tags') === 'on', // a-blog cmsはタグ機能をサポートするため
            'target_blog_id' => BID,
            // 管理画面で上書き可能な詳細設定。未入力なら Downloader 側のデフォルト（config 値）が使われる。
            'local_path_base' => trim((string)$this->Post->get('local_path_base')),
            'allowed_private_hosts' => trim((string)$this->Post->get('allowed_private_hosts')),
        ];
    }


    /**
     * 移行処理を実行（バックグラウンド）
     *
     * @param string $filePath
     * @param array $settings
     * @param \Acms\Services\Common\Logger $logger
     * @param \Acms\Services\Common\Lock $lockService
     * @return void
     */
    private function executeImportProcess(
        string $filePath,
        array $settings,
        \Acms\Services\Common\Logger $logger,
        \Acms\Services\Common\Lock $lockService
    ): void {
        $logger->setDestinationPath(CACHE_DIR . 'wxr-import-progress.json');
        $logger->init();

        // 初期メッセージ
        $logger->addMessage('移行処理を開始しています...', 0, 1, false);

        try {
            $lockService->tryLock();

            // WXR解析（2 パス・ストリーミング）
            //  - Pass 1: WXR 全件を走査し、カテゴリ／メディアを軽量オブジェクトとして収集する。
            //    エントリー本文は持ち回らず、件数だけカウントする。
            //  - Pass 2: BatchProcessor::processAll() に Generator を渡し、エントリー本文は
            //    バッチサイズ分だけメモリに乗せて即解放する。
            $logger->addMessage('WXRファイルを解析中...', 5, 1, false);

            /** @var array<\Acms\Plugins\WxrImport\Services\WXR\WXRMedia> $medias */
            $medias = [];
            /** @var array<\Acms\Plugins\WxrImport\Services\WXR\WXRCategory> $categories */
            $categories = [];
            $expectedEntries = 0;

            foreach ($this->parser->parse($filePath) as $item) {
                if ($item['post_type'] === 'attachment') {
                    $media = $this->mediaExtractor->extractMedia($item);
                    if ($media !== null) {
                        $medias[] = $media;
                    }
                } else {
                    // Pass 1 では件数だけ数え、WXREntry は構築しない（本文を持たない軽量パス）
                    $expectedEntries++;
                }

                // カテゴリーの収集
                foreach ($item['categories'] as $category) {
                    $categories[] = WXRCategory::fromArray($category);
                }
            }

            $logger->addMessage('解析完了: エントリー' . $expectedEntries . '件, メディア' . count($medias) . '件', 10, 1, true);

            // タグ機能の処理結果ログ
            $logger->addMessage('タグ処理: エントリーと一緒に処理されます', 0, 1, true);

            // BatchProcessorで統合処理を実行（カテゴリー、メディア、エントリーを一元処理）
            $logger->addMessage('統合処理を開始（カテゴリー、メディア、エントリー）...', 5, 1, false);

            $batchResults = $this->batchProcessor->processAll(
                $this->streamEntries($filePath),
                $medias,
                $categories,
                $settings,
                $logger,
                $expectedEntries
            );

            $logger->addMessage('WordPress移行が完了しました', 10, 1, true);
            $logger->success();


        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】移行処理中のエラー', Common::exceptionArray($th));

            $logger->error('移行処理中にエラーが発生しました: ' . $th->getMessage());
        } finally {
            // アップロードされたファイルは自動的に削除されるため、明示的な削除は不要
            $lockService->release();
            $logger->terminate();
        }
    }
}
