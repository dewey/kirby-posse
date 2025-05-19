<?php

namespace Notmyhostname\Posse\Models\Services;

use Kirby\Cms\Page;

/**
 * Interface for syndication services
 */
interface ServiceInterface
{
    /**
     * Syndicate content to the service
     * 
     * @param object $item The queue item
     * @param \Kirby\Cms\Page $page The page to syndicate
     * @param string $content The processed content
     * @return array Response with status and syndicated URL
     */
    public function syndicate($item, Page $page, string $content): array;
}