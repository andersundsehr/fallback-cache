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

    public function __construct(private readonly string $identifier, BackendInterface $backend)
    {
        // Need to get directly as the CacheManager is no fully compilant here (on cli)
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$this->identifier];
        $concreteClassName = $configuration['conrete_frontend'] ?? $configuration['frontend'] ?? \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class;
        $this->concrete = GeneralUtility::makeInstance($concreteClassName, $identifier, $backend);
    }

    public function has($entryIdentifier): bool
    {
        try {
            return $this->concrete->has($entryIdentifier);
        } catch (Throwable $exception) {
            $this->handle($exception);
            return false;
        }
    }

    public function remove($entryIdentifier): bool
    {
        try {
            return $this->concrete->remove($entryIdentifier);
        } catch (Throwable $exception) {
            $this->handle($exception);
            return false;
        }
    }

    public function flush(): void
    {
        try {
            $this->concrete->flush();
        } catch (Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function flushByTags(array $tags): void
    {
        try {
            $this->concrete->flushByTags($tags);
        } catch (Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function flushByTag($tag): void
    {
        try {
            $this->concrete->flushByTag($tag);
        } catch (Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        try {
            $this->concrete->set($entryIdentifier, $data, $tags, $lifetime);
        } catch (Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function get($entryIdentifier)
    {
        try {
            return $this->concrete->get($entryIdentifier);
        } catch (Throwable $exception) {
            $this->handle($exception);
            return false;
        }
    }

    private function handle(Throwable|Exception $exception): void
    {
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        assert($eventDispatcher instanceof EventDispatcherInterface);
        $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::YELLOW, $this->identifier));
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getBackend(): BackendInterface
    {
        return $this->concrete->getBackend();
    }

    public function collectGarbage(): void
    {
        $this->concrete->collectGarbage();
    }

    public function isValidEntryIdentifier($identifier): bool
    {
        return $this->concrete->isValidEntryIdentifier($identifier);
    }

    public function isValidTag($tag): bool
    {
        return $this->concrete->isValidTag($tag);
    }
}
