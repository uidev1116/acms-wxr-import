<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport;

use ACMS_App;
use Acms\Services\Facades\Application as Container;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Common\InjectTemplate;
use Acms\Services\Common\Lock as CommonLock;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '0.0.2';

    /**
     * @var string
     */
    public $name = 'WXRImport';

    /**
     * @var string
     */
    public $author = 'uidev1116';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var bool|string
     */
    public $menu = 'wxr_import_index';

    /**
     * @var string
     */
    public $desc = 'WordPress のデータを a-blog cms にインポートするためのプラグイン（拡張アプリ）です。';

    public function __construct()
    {
    }

    /**
     * サービスの初期処理
     */
    public function init()
    {
        /**
         * Inject Template
         */
        $inject = InjectTemplate::singleton();

        // 各画面のテンプレート注入
        if (ADMIN === 'app_wxr_import_index') {
            $inject->add('admin-topicpath', PLUGIN_DIR . $this->name . '/template/admin/topicpath.html');
            $inject->add('admin-main', PLUGIN_DIR . $this->name . '/template/admin/main.html');
        }

        // Services登録
        $this->registerServices();
    }

    /**
     * Services登録
     */
    private function registerServices(): void
    {
        $container = Container::getInstance();
        assert($container instanceof \Acms\Services\Container);

        // WXR関連サービス
        $container->singleton(\Acms\Plugins\WxrImport\Services\WXR\Parser::class, \Acms\Plugins\WxrImport\Services\WXR\Parser::class);

        $container->singleton(\Acms\Plugins\WxrImport\Services\WXR\EntryExtractor::class, \Acms\Plugins\WxrImport\Services\WXR\EntryExtractor::class);

        // Import関連サービス
        $container->singleton(\Acms\Plugins\WxrImport\Services\Import\EntryImporter::class, \Acms\Plugins\WxrImport\Services\Import\EntryImporter::class);

        // メディア関連サービス
        $container->singleton(\Acms\Plugins\WxrImport\Services\WXR\MediaExtractor::class, \Acms\Plugins\WxrImport\Services\WXR\MediaExtractor::class);

        $container->singleton(\Acms\Plugins\WxrImport\Services\Media\Downloader::class, \Acms\Plugins\WxrImport\Services\Media\Downloader::class);

        $container->singleton(\Acms\Plugins\WxrImport\Services\Import\MediaImporter::class, \Acms\Plugins\WxrImport\Services\Import\MediaImporter::class);

        // バッチ処理サービス
        $container->singleton(\Acms\Plugins\WxrImport\Services\Import\BatchProcessor::class, \Acms\Plugins\WxrImport\Services\Import\BatchProcessor::class);

        // カテゴリー・タグ作成サービス
        $container->singleton(\Acms\Plugins\WxrImport\Services\Import\CategoryCreator::class, \Acms\Plugins\WxrImport\Services\Import\CategoryCreator::class);

        // コンテンツ処理サービス
        $container->singleton(\Acms\Plugins\WxrImport\Services\Content\ContentProcessor::class, \Acms\Plugins\WxrImport\Services\Content\ContentProcessor::class);

        $container->singleton('wxr-import.progress-lock', function () {
            return new CommonLock(CACHE_DIR . 'wxr-import-progress-lock');
        });
    }

    /**
     * インストールする前の環境チェック処理
     *
     * @return bool
     */
    public function checkRequirements()
    {
        // PHP要件チェック
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            return false;
        }

        // 必要な拡張機能チェック
        $requiredExtensions = ['xml', 'xmlreader', 'curl', 'json'];
        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                return false;
            }
        }

        // ディレクトリ権限チェック
        if (!LocalStorage::isWritable(CACHE_DIR)) {
            return false;
        }

        return true;
    }

    /**
     * インストールするときの処理
     *
     * 進捗 JSON とロックファイルは CACHE_DIR 直下に置かれ、Logger / Lock サービスが
     * 利用時に自動生成する。メディアの保存先（ARCHIVES_DIR 配下）も Downloader 側で
     * ensureDownloadDirectory() / ensureLocalPathBase() が必要時に作るため、
     * インストールフックで明示的に準備するものは無い。
     *
     * @return void
     */
    public function install()
    {
    }

    /**
     * アンインストールするときの処理
     * データベーステーブルの始末など
     *
     * @return void
     */
    public function uninstall()
    {
    }

    /**
     * アップデートするときの処理
     *
     * @return bool
     */
    public function update()
    {
        return true;
    }

    /**
     * 有効化するときの処理
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * 無効化するときの処理
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }
}
