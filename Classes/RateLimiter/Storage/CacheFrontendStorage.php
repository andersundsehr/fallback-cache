<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\RateLimiter\Storage;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\TokenBucket;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class CacheFrontendStorage implements StorageInterface
{
    public function __construct(private readonly FrontendInterface $cache)
    {
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->cache->set(
            $this->getCacheKey($limiterState->getId()),
            serialize($limiterState),
            [],
            $limiterState->getExpirationTime()
        );
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $cacheItem = $this->cache->get($this->getCacheKey($limiterStateId));
        if (!is_string($cacheItem)) {
            return null;
        }

        $limiterState = unserialize($cacheItem, ['allowed_classes' => [Window::class, SlidingWindow::class, TokenBucket::class]]);
        if (!$limiterState instanceof LimiterStateInterface) {
            return null;
        }

        return $limiterState;
    }

    public function delete(string $limiterStateId): void
    {
        $this->cache->remove($this->getCacheKey($limiterStateId));
    }

    private function getCacheKey(string $limiterStateId): string
    {
        return 'rate_' . sha1($limiterStateId);
    }
}
