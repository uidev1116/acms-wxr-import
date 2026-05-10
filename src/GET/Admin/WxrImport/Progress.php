<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\GET\Admin\WxrImport;

use Acms\Services\Facades\Application;
use Template;
use ACMS_Corrector;
use ACMS_GET;

/**
 * WordPress移行進捗取得API
 *
 * AJAX経由で呼び出され、進捗状況をJSON形式で返す
 */
class Progress extends ACMS_GET
{
    public function get()
    {
        $tpl = new Template($this->tpl, new ACMS_Corrector());
        $rootVars = [];

        /**
         * WordPress移行中チェック
         */
        $lockService = Application::make('wxr-import.progress-lock');
        if ($lockService->isLocked()) {
            $rootVars['processing'] = 1;
        } else {
            $rootVars['processing'] = 0;
        }
        $tpl->add(null, $rootVars);

        return $tpl->get();
    }
}
