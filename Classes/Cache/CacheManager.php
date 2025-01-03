<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Cache;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Cache\Exception\DuplicateIdentifierException;
use TYPO3\CMS\Core\Cache\Exception\InvalidBackendException;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;
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

    protected ?VariableFrontend $cache = null;

    /**
     * @var array<string, StatusEnum>
     */
    protected static array $status = [];

    /**
     * @var array<string>
     */
    protected array $seen = [];

    /**
     * @var array<string>
     */
    protected array $breadcrumb = [];

    /**
     * @param array<string, array<mixed>> $cacheConfigurations
     */
    public function setCacheConfigurations(array $cacheConfigurations): void
    {
        parent::setCacheConfigurations($cacheConfigurations);
        try {
            $cache = $this->getCache('weakbit__fallback_cache');
            if (!$cache instanceof VariableFrontend) {
                throw new InvalidCacheException('Cache must be an instance of VariableFrontend');
            }

            $this->cache = $cache;
            $status = $cache->get('status');
            if (is_array($status)) {
                static::$status = $status;
            }
        } catch (Throwable $throwable) {
            $this->logger?->error($throwable->getMessage());
        }
    }

    public function getCache($identifier): FrontendInterface
    {
        // could set to red during runtie of this(!) process
        if (!isset(static::$status[$identifier]) || static::$status[$identifier] !== StatusEnum::RED) {
            // can be null e.g. if the class was not found.
            /** @var FrontendInterface|null $cache */
            $cache = parent::getCache($identifier);
            if ($cache) {
                return $cache;
            }
        }

        try {
            $fallback = $this->getFallbackCacheOf($identifier);
            if (null === $fallback) {
                throw new NoFallbackFoundException('No fallback found for ' . $identifier);
            }

            $cache = $this->getCache($this->fallbacks[$identifier]);
        } catch (RecursiveFallbackCacheException | NoFallbackFoundException $exception) {
            $this->logger?->error($exception->getMessage());
            $chain = $this->getBreadcrumb($identifier);
            throw new RuntimeException('Could not instanciate cache using the chain ' . $chain, $exception->getCode(), $exception);
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
        // do not overwrite the highest state
        if (isset(static::$status[$identifier]) && static::$status[$identifier] === StatusEnum::RED) {
            return;
        }

        static::$status[$identifier] = $status;
        $this->cache?->set('status', static::$status);
    }

    protected function createCache($identifier): void
    {
        if ($this->isStatusRed($identifier)) {
            $this->createCacheWithFallback($identifier);
            return;
        }

        try {
            parent::createCache($identifier);
        } catch (InvalidCacheException | InvalidBackendException | RecursiveFallbackCacheException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
            assert($eventDispatcher instanceof EventDispatcherInterface);
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

        if (!$this->hasCache($fallback)) {
            $this->createCache($fallback);
        }
    }

    private function getFallbackCacheOf(string $identifier): ?string
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
     * registers all fallback caches in the chain to prevent endess loops
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
}
