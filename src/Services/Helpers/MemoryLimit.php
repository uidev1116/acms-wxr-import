<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Helpers;

final class MemoryLimit
{
    public static function inBytes(): int
    {
        $raw = ini_get('memory_limit');
        if ($raw === false || $raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $raw;
        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
