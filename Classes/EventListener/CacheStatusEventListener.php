<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\EventListener;

use Weakbit\FallbackCache\Cache\CacheManager;
use Weakbit\FallbackCache\Event\CacheStatusEvent;

class CacheStatusEventListener
{
    public function __construct(private readonly CacheManager $cacheManager)
    {
    }

    public function __invoke(CacheStatusEvent $event): void
    {
        $this->cacheManager->addCacheStatus($event->getIdentifier(), $event->getStatus());
    }
}
