<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\EventListener;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use Weakbit\FallbackCache\Event\CacheStatusEvent;

class CacheStatusEventListener
{
    public function __invoke(CacheStatusEvent $event): void
    {
        // Dependency injection is far too early and ends up in an empty cache manager
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);

        // While clearing the cache it's a TYPO3 cache manager
        if ($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager) {
            $cacheManager->addCacheStatus($event->getIdentifier(), $event->getStatus());
        }
    }
}
