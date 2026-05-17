<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\Import;

use SQL;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Common;

/**
 * media_id → {path, type, filesize} の一括取得結果を保持する VO。
 *
 * ContentProcessor が画像ブロックごとに DB 問い合わせを発行していた N+1 を解消するため、
 * バッチ処理開始時に必要な media_id をまとめて SELECT し、本クラスに格納してから
 * ContentProcessor へ注入する。
 */
final class MediaInfoMap
{
    /**
     * @param array<int, array{path: string, type: string, filesize: int|string}> $map
     */
    public function __construct(private array $map)
    {
    }

    public function has(int $mediaId): bool
    {
        return isset($this->map[$mediaId]);
    }

    /**
     * @return array{path: string, type: string, filesize: int|string}|null
     */
    public function get(int $mediaId): ?array
    {
        return $this->map[$mediaId] ?? null;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * mediaMapping (wp_post_id => media_id) から必要な media_id を取り出し、
     * 1 本の `WHERE media_id IN (...)` で一括取得する。
     *
     * @param array<int, int> $mediaMapping
     */
    public static function load(array $mediaMapping): self
    {
        if ($mediaMapping === []) {
            return new self([]);
        }

        $mediaIds = array_values(array_unique(array_map('intval', array_values($mediaMapping))));
        if ($mediaIds === []) {
            return new self([]);
        }

        try {
            $sql = SQL::newSelect('media');
            $sql->addSelect('media_id');
            $sql->addSelect('media_path');
            $sql->addSelect('media_type');
            $sql->addSelect('media_filesize');
            $sql->addWhereIn('media_id', $mediaIds);
            $rows = Database::query($sql->get(dsn()), 'all');

            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row['media_id']] = [
                    'path' => (string) $row['media_path'],
                    'type' => (string) $row['media_type'],
                    'filesize' => $row['media_filesize'] ?? '',
                ];
            }
            return new self($map);
        } catch (\Throwable $th) {
            Logger::error('【WXRImport plugin】MediaInfoMap の構築に失敗', Common::exceptionArray($th, [
                'media_id_count' => count($mediaIds),
            ]));
            return new self([]);
        }
    }
}
