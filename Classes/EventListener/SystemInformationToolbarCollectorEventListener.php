<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\EventListener;

use TYPO3\CMS\Backend\Backend\Event\SystemInformationToolbarCollectorEvent;
use TYPO3\CMS\Backend\Toolbar\Enumeration\InformationStatus;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Weakbit\FallbackCache\Enum\StatusEnum;

/**
 * displays some information about caches in the system information toolbar
 */
class SystemInformationToolbarCollectorEventListener
{
    public function __invoke(SystemInformationToolbarCollectorEvent $event): void
    {
        $toolbar = $event->getToolbarItem();
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        try {
            $cache = $cacheManager->getCache('weakbit__fallback_cache');
        } catch (NoSuchCacheException) {
            return;
        }

        if (!$cache instanceof VariableFrontend) {
            $toolbar->addSystemInformation(
                'Fallback Cache Status',
                'Cache must be an instance of VariableFrontend',
                'actions-play',
                InformationStatus::STATUS_WARNING
            );
            return;
        }

        $status = $cache->get('status');
        if (!is_array($status)) {
            $toolbar->addSystemInformation(
                'Fallback Cache Status',
                'No status found',
                'actions-play',
                InformationStatus::STATUS_INFO
            );
            return;
        }

        foreach ($status as $identifier => $oneStatus) {
            assert(is_string($identifier));
            $fallBack = null;
            if ($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager) {
                $fallBack = $cacheManager->getFallbackCacheOf($identifier);
            }

            assert($oneStatus instanceof StatusEnum);
            $toolbar->addSystemInformation(
                // design issues with more characters in title.
                substr($identifier, 0, 15),
                $oneStatus->name . ($fallBack ? ' (fallback: ' . $fallBack . ')' : ''),
                'actions-play',
                $oneStatus === StatusEnum::RED ? InformationStatus::STATUS_ERROR : InformationStatus::STATUS_WARNING
            );
        }
    }
}
