<?php

declare(strict_types=1);

namespace Acms\Plugins\WPImport;

use ACMS_App;
use Acms\Services\Facades\Application as Container;
use Acms\Services\Common\InjectTemplate;
use Acms\Services\Common\Lock as CommonLock;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '0.0.0';

    /**
     * @var string
     */
    public $name = 'WPImport';

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
    public $menu = 'wp_import_index';

    /**
     * @var string
     */
    public $desc = 'WordPress のデータを a-blog cms にインポートするための拡張アプリです。';

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
        if (ADMIN === 'app_wp_import_index') {
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
        $container->singleton(\Acms\Plugins\WPImport\Services\WXR\Parser::class, \Acms\Plugins\WPImport\Services\WXR\Parser::class);

        $container->singleton(\Acms\Plugins\WPImport\Services\WXR\EntryExtractor::class, \Acms\Plugins\WPImport\Services\WXR\EntryExtractor::class);

        // Import関連サービス
        $container->singleton(\Acms\Plugins\WPImport\Services\Import\EntryImporter::class, \Acms\Plugins\WPImport\Services\Import\EntryImporter::class);

        // メディア関連サービス
        $container->singleton(\Acms\Plugins\WPImport\Services\WXR\MediaExtractor::class, \Acms\Plugins\WPImport\Services\WXR\MediaExtractor::class);

        $container->singleton(\Acms\Plugins\WPImport\Services\Media\Downloader::class, \Acms\Plugins\WPImport\Services\Media\Downloader::class);

        $container->singleton(\Acms\Plugins\WPImport\Services\Import\MediaImporter::class, \Acms\Plugins\WPImport\Services\Import\MediaImporter::class);

        // バッチ処理サービス
        $container->singleton(\Acms\Plugins\WPImport\Services\Import\BatchProcessor::class, \Acms\Plugins\WPImport\Services\Import\BatchProcessor::class);

        // カテゴリー・タグ作成サービス
        $container->singleton(\Acms\Plugins\WPImport\Services\Import\CategoryCreator::class, \Acms\Plugins\WPImport\Services\Import\CategoryCreator::class);

        // コンテンツ処理サービス
        $container->singleton(\Acms\Plugins\WPImport\Services\Content\ContentProcessor::class, \Acms\Plugins\WPImport\Services\Content\ContentProcessor::class);

        $container->singleton('wp-import.progress-lock', function () {
            return new CommonLock(CACHE_DIR . 'wp-import-progress-lock');
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
        if (!is_writable(CACHE_DIR)) {
            return false;
        }

        return true;
    }

    /**
     * インストールするときの処理
     * データベーステーブルの初期化など
     *
     * @return void
     */
    public function install()
    {
        // アップロード用ディレクトリの作成
        $uploadDir = CACHE_DIR . 'wp-import/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // 進捗ファイル用ディレクトリの作成
        $progressDir = CACHE_DIR . 'wp-import/progress/';
        if (!is_dir($progressDir)) {
            mkdir($progressDir, 0755, true);
        }

        // .htaccessファイルでディレクトリへの直接アクセスを防止
        $htaccessContent = "Order deny,allow\nDeny from all\n";
        file_put_contents($uploadDir . '.htaccess', $htaccessContent);
        file_put_contents($progressDir . '.htaccess', $htaccessContent);
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
