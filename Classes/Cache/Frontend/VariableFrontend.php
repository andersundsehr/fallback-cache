<?php

declare(strict_types=1);


namespace Weakbit\FallbackCache\Cache\Frontend;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\FallbackCache\Enum\StatusEnum;
use Weakbit\FallbackCache\Event\CacheStatusEvent;

class VariableFrontend extends \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend
{
    // TODO oder doch sagen man konfiguriert hier ein concrete, denn das koennte dann auch eine andere cache classe sein die halt variablefrontend erweitert.
    protected \TYPO3\CMS\Core\Cache\Frontend\AbstractFrontend $concrete;
    public function __construct($identifier, BackendInterface $backend)
    {
        $this->concrete = parent::__construct($identifier, $backend);
    }

    public function has($entryIdentifier): bool
    {
        try {
            return $this->concrete->has($entryIdentifier);
        } catch (\Throwable $exception) {
            $this->handle($exception);
            return false;
        }
    }

    public function remove($entryIdentifier): bool
    {
        try {
            return $this->concrete->remove($entryIdentifier);
        } catch (\Throwable $exception) {
            $this->handle($exception);
            return false;
        }
    }

    public function flush(): void
    {
        try {
            $this->concrete->flush();
        } catch (\Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function flushByTags(array $tags): void
    {
        try {
            $this->concrete->flushByTags($tags);
        } catch (\Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function flushByTag($tag): void
    {
        try {
            $this->concrete->flushByTag($tag);
        } catch (\Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function set($entryIdentifier, $variable, array $tags = [], $lifetime = null): void
    {
        try {
            $this->concrete->set($entryIdentifier, $variable, $tags, $lifetime);
        } catch (\Throwable $exception) {
            $this->handle($exception);
            return;
        }
    }

    public function get($entryIdentifier)
    {
        try {
            return $this->concrete->get($entryIdentifier);
        } catch (\Throwable $exception) {
            $this->handle($exception);
            return false;
        }
    }

    private function handle(\Throwable|\Exception $exception): void
    {
        // todo gleich rot ist schon hart, aber, man will ja nicht cachelos werden, ggfs ist sonst erst recht die seite down. ggfs doch rot hier?
        // todo man koennte das auc hkonfigurieren, ein fehler im cache waehrend der runtime, welcher stand? bzw ist yellow counter und ab ner schwelle rot, das ist das beste!
        // TODO doku erweitern,
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        assert($eventDispatcher instanceof EventDispatcherInterface);
        $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::YELLOW, $this->identifier));
    }
}
