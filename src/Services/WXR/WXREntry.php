<?php

declare(strict_types=1);

namespace Acms\Plugins\WxrImport\Services\WXR;

use DateTime;

/**
 * WXRエントリーデータを格納するクラス
 */
class WXREntry
{
    public int $wpPostId;
    public string $title = '';
    public string $content = '';
    public string $excerpt = '';
    public string $postName = '';
    public string $status = 'draft';
    public string $type = 'post';
    public ?int $parentId = null;
    public int $menuOrder = 0;
    public bool $commentStatus = true;
    public bool $pingStatus = true;
    public string $password = '';
    public bool $isSticky = false;
    public ?DateTime $postDate = null;
    public ?DateTime $postDateGmt = null;
    public string $author = '';
    public string $originalUrl = '';
    public string $guid = '';
    /** @var WXRCategory[] */
    public array $categories = [];
    /** @var WXRTag[] */
    public array $tags = [];
    public array $customFields = [];
    public ?int $featuredMediaId = null;
    public array $comments = [];
    public array $seoData = [];
}