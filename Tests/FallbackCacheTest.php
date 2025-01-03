<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Tests;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Weakbit\FallbackCache\Enum\StatusEnum;
use Weakbit\FallbackCache\Event\CacheStatusEvent;
use Weakbit\FallbackCache\Tests\Classes\BrokenCacheBackend;

class FallbackCacheTest extends FunctionalTestCase
{
    protected \Weakbit\FallbackCache\Cache\CacheManager $cacheManager;

    protected bool $resetSingletonInstances = true;

    protected array $testExtensionsToLoad = [
        'weakbit/fallback-cache',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'Objects' => [
                CacheManager::class => [
                    'className' => \Weakbit\FallbackCache\Cache\CacheManager::class,
                ],
            ],
        ],
    ];


    public function setUp(): void
    {
        // Testing framework needed the env vars also it is not used
        putenv('typo3DatabaseUsername=');
        putenv('typo3DatabasePassword=');
        putenv('typo3DatabaseHost=');
        putenv('typo3DatabaseName=');
        putenv('typo3DatabaseDriver=pdo_sqlite');

        parent::setUp();

        $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $this->cacheManager->setCacheConfigurations([
            'weakbit__fallback_cache' => [
                'frontend' => VariableFrontend::class,
                'backend' => TransientMemoryBackend::class,
                'groups' => [
                    'system',
                ],
            ],

            'broken_cache' => [
                'backend' => BrokenCacheBackend::class,
                'fallback' => 'fallback_cache',
            ],
            'fallback_cache' => [
                'backend' => BrokenCacheBackend::class,
                'fallback' => 'fallback_fallback_cache',
            ],
            'fallback_fallback_cache' => [
                'backend' => NullBackend::class,
            ],
            'yet_working_cache' => [
                'backend' => TransientMemoryBackend::class,
                'fallback' => 'fallback_fallback_cache',
            ],
        ]);
    }

    /**
     * @test
     * @throws NoSuchCacheException
     */
    public function testGetBrokenCacheReturnsFallbackCache(): void
    {
        $cache = $this->cacheManager->getCache('broken_cache');
        $this->assertTrue($cache->getBackend() instanceof NullBackend);
    }

    /**
     * @throws NoSuchCacheException
     */
    public function testGetStatusRedCacheReturnsFallbackCache(): void
    {
        // here we use a functional one, check it works. then dispatch the status red event, and get the cache again, it should now respond with the fallback cache
        $cache = $this->cacheManager->getCache('yet_working_cache');
        $this->assertTrue($cache->getBackend() instanceof TransientMemoryBackend);

        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        assert($eventDispatcher instanceof EventDispatcherInterface);
        $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::RED, 'yet_working_cache'));

        $cache = $this->cacheManager->getCache('yet_working_cache');
        $this->assertTrue($cache->getBackend() instanceof NullBackend);
    }
}
