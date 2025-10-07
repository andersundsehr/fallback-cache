<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Cache;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
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

    public function getCache($identifier): FrontendInterface
    {
        // could set to red during runtime of this(!) process
        if (!isset(static::$status[$identifier]) || static::$status[$identifier] !== StatusEnum::RED) {
            // can be null e.g. if the class was not found.
            /** @var FrontendInterface|null $cache */
            $cache = @parent::getCache($identifier);
            if ($cache) {
                return $cache;
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

        static::$status[$identifier] = $status;
        try {
            $cache = $this->getCache('weakbit__fallback_cache');
            $cache->set('status', static::$status);
        } catch (NoSuchCacheException) {
        }
    }

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
