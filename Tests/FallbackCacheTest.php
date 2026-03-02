<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Tests;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\Core\Cache\SimpleFileCache;
use Weakbit\FallbackCache\Enum\StatusEnum;
use Weakbit\FallbackCache\Event\CacheStatusEvent;
use Weakbit\FallbackCache\Tests\Classes\BrokenCacheBackend;

class FallbackCacheTest extends FunctionalTestCase
{
    protected bool $resetSingletonInstances = true;

    // Required to load Services.yaml
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
            // can not set cache configuration here, as the db setup uses "new". Configuring dummies to avoid access on non-set array key
            'caching' => [
                'cacheConfigurations' => [
                    'weakbit__fallback_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'broken_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'fallback_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'fallback_fallback_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'yet_working_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'good_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'bad_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        // Testing framework needed the env vars also it is not used
        putenv('typo3DatabaseUsername=');
        putenv('typo3DatabasePassword=');
        putenv('typo3DatabaseHost=');
        putenv('typo3DatabaseName=');
        putenv('typo3DatabaseDriver=pdo_sqlite');

        parent::setUp();

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);
        $cacheManager->setCacheConfigurations([
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
            'good_cache' => [
                'backend' => FileBackend::class,
                'fallback' => 'fallback_fallback_cache',
            ],
            'bad_cache' => [
                'backend' => BrokenCacheBackend::class,
            ],
        ]);
    }

    /**
     * @test
     * @throws NoSuchCacheException
     */
    #[Test]
    public function testGetGoodCacheReturnsCache(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);
        $cache = $cacheManager->getCache('good_cache');
        $this->assertTrue($cache->getBackend() instanceof FileBackend);
    }

    /**
     * @test
     * @throws NoSuchCacheException
     */
    #[Test]
    public function testGetBadCacheThrowsException(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);
        self::expectExceptionMessage('Could not create cache using the chain bad_cache');
        @$cacheManager->getCache('bad_cache');
    }

    /**
     * @test
     * @throws NoSuchCacheException
     */
    #[Test]
    public function testGetBrokenCacheReturnsFallbackCache(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);
        $cache = $cacheManager->getCache('broken_cache');
        $this->assertTrue($cache->getBackend() instanceof NullBackend);
    }

    /**
     * @test
     * @throws NoSuchCacheException
     */
    #[Test]
    public function testGetStatusRedCacheReturnsFallbackCache(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);

        // here we use a functional one, check it works. then dispatch the status red event, and get the cache again, it should now respond with the fallback cache
        $cache = $cacheManager->getCache('yet_working_cache');
        $this->assertTrue($cache->getBackend() instanceof TransientMemoryBackend);

        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::RED, 'yet_working_cache'));

        $cache = $cacheManager->getCache('yet_working_cache');
        $this->assertTrue($cache->getBackend() instanceof NullBackend);
    }

    /**
     * @test
     * @throws NoSuchCacheException
     */
    #[Test]
    public function testCacheBackendStateVerificationAfterFallback(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);

        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);

        // recover from previous test
        $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::GREEN, 'yet_working_cache'));

        // Store a value in the original backend
        $cache = $cacheManager->getCache('yet_working_cache');
        $cache->set('foo', 'bar');
        $this->assertSame('bar', $cache->get('foo'));

        // Simulate RED status (fallback)
        $eventDispatcher->dispatch(new CacheStatusEvent(StatusEnum::RED, 'yet_working_cache'));
        $cache = $cacheManager->getCache('yet_working_cache');

        // NullBackend should not return the value
        $this->assertFalse($cache->get('foo'));
    }
}
