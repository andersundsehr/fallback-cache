<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Cache\Frontend;

use Exception;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\FallbackCache\Enum\StatusEnum;
use Weakbit\FallbackCache\Event\CacheStatusEvent;

class VariableFrontend implements FrontendInterface
{
    protected FrontendInterface $concrete;

    /**
     * @throws Exception
     */
    public function __construct(private readonly string $identifier, BackendInterface $backend)
    {
        // Need to get directly as the CacheManager is no fully compilant here (on cli)
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$this->identifier];
        $concreteClassName = $configuration['conrete_frontend'] ?? $configuration['frontend'] ?? \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class;
        if (!is_string($concreteClassName)) {
            throw new Exception('Invalid concrete frontend class name');
        }

        /** @var class-string $concreteClassName */
        $concrete = GeneralUtility::makeInstance($concreteClassName, $identifier, $backend);
        assert($concrete instanceof FrontendInterface);
        $this->concrete = $concrete;
    }

    /**
     * @inheritdoc
     */
    public function has($entryIdentifier): bool
    {
        try {
            return $this->concrete->has($entryIdentifier);
        } catch (Throwable $throwable) {
            $this->handle($throwable);
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function remove($entryIdentifier): bool
    {
        try {
            return $this->concrete->remove($entryIdentifier);
        } catch (Throwable $throwable) {
            $this->handle($throwable);
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function flush(): void
    {
        try {
            $this->concrete->flush();
        } catch (Throwable $throwable) {
            $this->handle($throwable);
        }
    }

    /**
     * @inheritdoc
     */
    public function flushByTags(array $tags): void
    {
        try {
            $this->concrete->flushByTags($tags);
        } catch (Throwable $throwable) {
            $this->handle($throwable);
        }
    }

    /**
     * @inheritdoc
     */
    public function flushByTag($tag): void
    {
        try {
            $this->concrete->flushByTag($tag);
        } catch (Throwable $throwable) {
            $this->handle($throwable);
        }
    }

    /**
     * @inheritdoc
     * @param string $entryIdentifier Something which identifies the data - depends on concrete cache
     * @param mixed $data The data to cache - also depends on the concrete cache implementation
     * @param array<mixed> $tags Tags to associate with this cache entry
     * @param int $lifetime Lifetime of this cache entry in seconds. If NULL is specified, the default lifetime is used. "0" means unlimited lifetime.
 */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        try {
            $this->concrete->set($entryIdentifier, $data, $tags, $lifetime);
        } catch (Throwable $throwable) {
            $this->handle($throwable);
        }
    }

    /**
     * @inheritdoc
     */
    public function get($entryIdentifier)
    {
        try {
            return $this->concrete->get($entryIdentifier);
        } catch (Throwable $throwable) {
            $this->handle($throwable);
            return false;
        }
    }

    private function handle(Throwable|Exception $exception): void
    {
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        assert($eventDispatcher instanceof EventDispatcherInterface);
        $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::YELLOW, $this->identifier, $exception));
    }

    /**
     * @inheritdoc
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @inheritdoc
     */
    public function getBackend(): BackendInterface
    {
        return $this->concrete->getBackend();
    }

    /**
     * @inheritdoc
     */
    public function collectGarbage(): void
    {
        $this->concrete->collectGarbage();
    }

    /**
     * @inheritdoc
     */
    public function isValidEntryIdentifier($identifier): bool
    {
        return $this->concrete->isValidEntryIdentifier($identifier);
    }

    /**
     * @inheritdoc
     */
    public function isValidTag($tag): bool
    {
        return $this->concrete->isValidTag($tag);
    }
}
