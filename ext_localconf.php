<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\CacheManager;

defined('TYPO3') || die('Access denied.');

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['weakbit__fallback_cache'] = [
    'frontend' => VariableFrontend::class,
    'backend' => FileBackend::class,
    'groups' => [
        'system',
    ]
];

$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][CacheManager::class] = [
    'className' => \Weakbit\FallbackCache\Cache\CacheManager::class
];
