<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Cache;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;
use TYPO3\CMS\Core\Cache\Exception\DuplicateIdentifierException;
use TYPO3\CMS\Core\Cache\Exception\InvalidBackendException;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheGroupException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\FallbackCache\Enum\StatusEnum;
use Weakbit\FallbackCache\Event\CacheStatusEvent;
use Weakbit\FallbackCache\Exception\NoFallbackFoundException;
use Weakbit\FallbackCache\Exception\RecursiveFallbackCacheException;
use Weakbit\FallbackCache\RateLimiter\Storage\CacheFrontendStorage;

class CacheManager extends \TYPO3\CMS\Core\Cache\CacheManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var array<string, string>
     */
    protected array $fallbacks = [];

    /**
     * @var array<string, StatusEnum>
     */
    protected static array $status = [];

    /**
     * @var array<string>
     */
    protected array $seen = [];

    /**
     * @param array<string, array<mixed>> $cacheConfigurations
     */
    public function setCacheConfigurations(array $cacheConfigurations): void
    {
        parent::setCacheConfigurations($cacheConfigurations);
        try {
            $cache = $this->getCache('weakbit__fallback_cache');
            if (!$cache instanceof VariableFrontend) {
                throw new InvalidCacheException('Cache must be an instance of VariableFrontend', 1736962058);
            }

            $status = $cache->get('status');
            if (is_array($status)) {
                foreach ($status as $identifier => $state) {
                    if (is_string($identifier) && $state instanceof StatusEnum) {
                        static::$status[$identifier] = $state;
                    }
                }
            }
        } catch (Throwable $throwable) {
            $this->logger?->error($throwable->getMessage());
        }
    }

    /**
     * @param string $identifier
     * @throws NoSuchCacheException
     */
    #[Override]
    public function getCache($identifier): FrontendInterface
    {
        // could set to red during runtime of this(!) process
        if (!isset(static::$status[$identifier]) || static::$status[$identifier] !== StatusEnum::RED) {
            // can be null e.g. if the class was not found.
            try {
                // @phpstan-ignore-next-line TYPO3 v13 may return null when cache creation fails.
                return @parent::getCache($identifier);
            } catch (NoSuchCacheException $exception) {
                throw $exception;
            } catch (Throwable) {
                // Continue with the configured fallback chain.
            }
        }

        try {
            $fallback = $this->getFallbackCacheOf($identifier);
            if (null === $fallback) {
                throw new NoFallbackFoundException('No fallback found for ' . $identifier, 5859365252);
            }

            $cache = $this->getCache($this->fallbacks[$identifier]);
        } catch (RecursiveFallbackCacheException | NoFallbackFoundException $exception) {
            $this->logger?->error($exception->getMessage());
            $chain = $this->getBreadcrumb($identifier);
            throw new RuntimeException('Could not create cache using the chain ' . $chain, $exception->getCode(), $exception);
        }

        return $cache;
    }

    private function getBreadcrumb(string $identifier, string $breadcrumb = ''): string
    {
        if ($breadcrumb) {
            $breadcrumb .= '->';
        }

        $breadcrumb .= $identifier;
        foreach ($this->fallbacks as $origin => $fallback) {
            if ($origin === $identifier) {
                return $this->getBreadcrumb($fallback, $breadcrumb);
            }
        }

        return $breadcrumb;
    }

    public function addCacheStatus(string $identifier, StatusEnum $status): void
    {
        // Always set RED, never overwrite RED with YELLOW, but always allow GREEN
        if (isset(static::$status[$identifier]) && (static::$status[$identifier] === StatusEnum::RED && $status === StatusEnum::YELLOW)) {
            return;
        }

        if ($status === StatusEnum::YELLOW) {
            $status = $this->applyYellowToRedRate($identifier);
        } else {
            $this->resetYellowToRedRate($identifier);
        }

        static::$status[$identifier] = $status;
        try {
            $cache = $this->getCache('weakbit__fallback_cache');
            $cache->set('status', static::$status);
        } catch (Throwable) {
        }
    }

    public function getCacheStatus(string $identifier): StatusEnum|false
    {
        if (isset(static::$status[$identifier])) {
            return static::$status[$identifier];
        }

        try {
            $cache = $this->getCache('weakbit__fallback_cache');
            $status = $cache->get('status');
        } catch (Throwable) {
            return false;
        }

        if (!is_array($status)) {
            return false;
        }

        $cacheStatus = $status[$identifier] ?? false;
        if (!$cacheStatus instanceof StatusEnum) {
            return false;
        }

        static::$status[$identifier] = $cacheStatus;
        return $cacheStatus;
    }

    private function applyYellowToRedRate(string $identifier): StatusEnum
    {
        $rateConfig = $this->getYellowToRedRateConfig($identifier);
        if ($rateConfig === null) {
            return StatusEnum::YELLOW;
        }

        try {
            if (!$this->createYellowToRedLimiter($identifier, $rateConfig)->consume()->isAccepted()) {
                return StatusEnum::RED;
            }
        } catch (Throwable $throwable) {
            $this->logger?->warning('Could not apply yellow-to-red rate limit for ' . $identifier . ': ' . $throwable->getMessage());
        }

        return StatusEnum::YELLOW;
    }

    private function resetYellowToRedRate(string $identifier): void
    {
        $rateConfig = $this->getYellowToRedRateConfig($identifier);
        if ($rateConfig === null) {
            return;
        }

        try {
            $this->createYellowToRedLimiter($identifier, $rateConfig)->reset();
        } catch (Throwable) {
        }
    }

    /**
     * @param array{limit: int, interval: string} $rateConfig
     */
    private function createYellowToRedLimiter(string $identifier, array $rateConfig): LimiterInterface
    {
        $cache = $this->getCache('weakbit__fallback_cache');
        $limiterFactory = new RateLimiterFactory(
            [
                'id' => 'fallback-cache-yellow-to-red',
                'policy' => 'sliding_window',
                'limit' => $rateConfig['limit'],
                'interval' => $rateConfig['interval'],
            ],
            new CacheFrontendStorage($cache)
        );

        return $limiterFactory->create($identifier);
    }

    /**
     * @return array{limit: int, interval: string}|null
     */
    private function getYellowToRedRateConfig(string $identifier): ?array
    {
        $rate = $this->cacheConfigurations[$identifier]['yellow_to_red_rate'] ?? 0;

        if (!is_string($rate)) {
            return null;
        }

        $rate = trim($rate);
        if ($rate === '' || $rate === '0') {
            return null;
        }

        if (!preg_match('/^(?<limit>\d+)\s*(?:r|requests?|events?)?\s*(?:\/|per|in|within|every)\s*(?:(?<amount>\d+|a|an|one)\s*)?(?<unit>[a-z]+)$/i', $rate, $matches)) {
            return null;
        }

        $limit = (int)$matches['limit'];
        if ($limit <= 0) {
            return null;
        }

        $amount = match (strtolower($matches['amount'])) {
            'a', 'an', 'one', '' => 1,
            default => (int)$matches['amount'],
        };
        if ($amount <= 0) {
            return null;
        }

        $unit = $this->normalizeRateIntervalUnit($matches['unit']);
        if ($unit === null) {
            return null;
        }

        return [
            'limit' => $limit,
            'interval' => $amount . ' ' . $unit,
        ];
    }

    private function normalizeRateIntervalUnit(string $unit): ?string
    {
        return match (strtolower($unit)) {
            's', 'sec', 'secs', 'second', 'seconds' => 'second',
            'm', 'min', 'mins', 'minute', 'minutes' => 'minute',
            'h', 'hr', 'hrs', 'hour', 'hours' => 'hour',
            'd', 'day', 'days' => 'day',
            default => null,
        };
    }

    /**
     * @param string $identifier
     * @throws InvalidBackendException
     * @throws InvalidCacheException
     * @throws DuplicateIdentifierException
     */
    #[Override]
    protected function createCache($identifier): void
    {
        if ($this->isStatusRed($identifier)) {
            $this->createCacheWithFallback($identifier);
            return;
        }

        try {
            parent::createCache($identifier);
        } catch (InvalidCacheException | InvalidBackendException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
            $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::RED, $identifier, $throwable));
            $this->createCacheWithFallback($identifier);
        }
    }

    private function isStatusRed(string $identifier): bool
    {
        if (!isset(static::$status[$identifier])) {
            return false;
        }

        return match (static::$status[$identifier]) {
            StatusEnum::RED => true,
            default => false
        };
    }

    private function isImmutable(string $identifier): bool
    {
        return (bool)($this->cacheConfigurations[$identifier]['tags'][0]['immutable'] ?? false);
    }

    /**
     * @throws DuplicateIdentifierException
     * @throws InvalidBackendException
     * @throws InvalidCacheException
     */
    private function createCacheWithFallback(string $identifier): void
    {
        $fallback = $this->getFallbackCacheOf($identifier);
        if (!$fallback) {
            return;
        }

        if (!isset($this->caches[$fallback])) {
            $this->createCache($fallback);
        }

        $this->caches[$identifier] = $this->caches[$fallback];
    }

    public function getFallbackCacheOf(string $identifier): ?string
    {
        $fallback = $this->cacheConfigurations[$identifier]['fallback'] ?? null;

        // no endless loop
        if ($identifier === $fallback || $this->isSeen($fallback)) {
            throw new RecursiveFallbackCacheException();
        }

        if (null === $fallback) {
            return null;
        }

        $this->registerFallback($identifier, $fallback);
        return $fallback;
    }

    /**
     * registers all fallback caches in the chain to prevent endless loops
     */
    private function isSeen(?string $fallback): bool
    {
        return in_array($fallback, $this->seen, true);
    }

    private function registerFallback(string $identifier, string $fallback): void
    {
        $this->logger?->warning('Registering fallback cache ' . $fallback . ' for ' . $identifier);
        $this->fallbacks[$identifier] = $fallback;
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function flushCaches(): void
    {
        $this->createAllCaches();
        foreach ($this->caches as $cache) {
            if ($this->isImmutable($cache->getIdentifier())) {
                continue;
            }

            $cache->flush();
        }
    }

    /**
     * @param string $groupIdentifier
     * @inheritdoc
     */
    #[Override]
    public function flushCachesInGroup($groupIdentifier): void
    {
        $this->createAllCaches();
        if (!isset($this->cacheGroups[$groupIdentifier])) {
            throw new NoSuchCacheGroupException("No cache in the specified group '" . $groupIdentifier . "'", 1390334120);
        }

        foreach ($this->cacheGroups[$groupIdentifier] as $cacheIdentifier) {
            if (isset($this->caches[$cacheIdentifier])) {
                if ($this->isImmutable($cacheIdentifier)) {
                    continue;
                }

                $this->caches[$cacheIdentifier]->flush();
            }
        }
    }

    /**
     * @param string $groupIdentifier
     * @param string $tag Tag to search for
     * @inheritdoc
     */
    #[Override]
    public function flushCachesInGroupByTag($groupIdentifier, $tag): void
    {
        if (empty($tag)) {
            return;
        }

        $this->createAllCaches();
        if (!isset($this->cacheGroups[$groupIdentifier])) {
            throw new NoSuchCacheGroupException("No cache in the specified group '" . $groupIdentifier . "'", 1390337129);
        }

        foreach ($this->cacheGroups[$groupIdentifier] as $cacheIdentifier) {
            if (isset($this->caches[$cacheIdentifier])) {
                if ($this->isImmutable($cacheIdentifier)) {
                    continue;
                }

                $this->caches[$cacheIdentifier]->flushByTag($tag);
            }
        }
    }

    /**
     * @param string $groupIdentifier
     * @param array<string> $tags
     * @inheritdoc
     */
    #[Override]
    public function flushCachesInGroupByTags($groupIdentifier, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $this->createAllCaches();
        if (!isset($this->cacheGroups[$groupIdentifier])) {
            throw new NoSuchCacheGroupException("No cache in the specified group '" . $groupIdentifier . "'", 1390337130);
        }

        foreach ($this->cacheGroups[$groupIdentifier] as $cacheIdentifier) {
            if (isset($this->caches[$cacheIdentifier])) {
                if ($this->isImmutable($cacheIdentifier)) {
                    continue;
                }

                $this->caches[$cacheIdentifier]->flushByTags($tags);
            }
        }
    }

    /**
     * @param string $tag
     * @inheritdoc
     */
    #[Override]
    public function flushCachesByTag($tag): void
    {
        $this->createAllCaches();
        foreach ($this->caches as $cache) {
            if ($this->isImmutable($cache->getIdentifier())) {
                continue;
            }

            $cache->flushByTag($tag);
        }
    }

    /**
     * @param array<string> $tags
     * @inheritdoc
     */
    #[Override]
    public function flushCachesByTags(array $tags): void
    {
        $this->createAllCaches();
        foreach ($this->caches as $cache) {
            if ($this->isImmutable($cache->getIdentifier())) {
                continue;
            }

            $cache->flushByTags($tags);
        }
    }
}
