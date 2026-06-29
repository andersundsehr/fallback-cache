<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Tests;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheGroupException;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ImmutableCacheTagTest extends FunctionalTestCase
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
            'caching' => [
                'cacheConfigurations' => [
                    'mutable_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'immutable_cache' => [
                        'backend' => TransientMemoryBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        // Testing framework needed env vars
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
            'mutable_cache' => [
                'frontend' => VariableFrontend::class,
                'backend' => TransientMemoryBackend::class,
                'groups' => ['system'],
                'tags' => [
                    ['name' => 'cache', 'identifier' => 'mutable_cache', 'immutable' => false]
                ]
            ],
            'immutable_cache' => [
                'frontend' => VariableFrontend::class,
                'backend' => TransientMemoryBackend::class,
                'groups' => ['system'],
                'tags' => [
                    ['name' => 'cache', 'identifier' => 'immutable_cache', 'immutable' => true]
                ]
            ],
        ]);
    }

    /**
     * @test
     * @throws NoSuchCacheException
     */
    #[Test]
    public function flushMethodDoesNotFlushImmutableCaches(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);

        // Store values in both mutable and immutable caches (with and without tags)
        $mutableCache = $cacheManager->getCache('mutable_cache');
        $immutableCache = $cacheManager->getCache('immutable_cache');

        $mutableCache->set('test_key', 'test_value');
        $mutableCache->set('tagged_key', 'tagged_value', ['flush_tag']);

        $immutableCache->set('test_key', 'test_value');
        $immutableCache->set('tagged_key', 'tagged_value', ['flush_tag']);

        // Verify values are set
        $this->assertEquals('test_value', $mutableCache->get('test_key'));
        $this->assertEquals('tagged_value', $mutableCache->get('tagged_key'));
        $this->assertEquals('test_value', $immutableCache->get('test_key'));
        $this->assertEquals('tagged_value', $immutableCache->get('tagged_key'));

        // First test tag-based flushing
        $cacheManager->flushCachesByTag('flush_tag');

        // Only mutable cache entry with the tag should be flushed
        $this->assertEquals('test_value', $mutableCache->get('test_key'));
        $this->assertFalse($mutableCache->get('tagged_key'));
        $this->assertEquals('test_value', $immutableCache->get('test_key'));
        $this->assertEquals('tagged_value', $immutableCache->get('tagged_key'));

        // Reset tagged entry
        $mutableCache->set('tagged_key', 'tagged_value', ['flush_tag']);

        // Now flush all caches
        $cacheManager->flushCaches();

        // Mutable cache should be empty, immutable cache should still have values
        $this->assertFalse($mutableCache->get('test_key'));
        $this->assertFalse($mutableCache->get('tagged_key'));
        $this->assertEquals('test_value', $immutableCache->get('test_key'));
        $this->assertEquals('tagged_value', $immutableCache->get('tagged_key'));
    }

    /**
     * @test
     * @throws NoSuchCacheException|NoSuchCacheGroupException
     */
    #[Test]
    public function flushCachesInGroupDoesNotFlushImmutableCaches(): void
    {
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        assert($cacheManager instanceof \Weakbit\FallbackCache\Cache\CacheManager);

        // Store values in both mutable and immutable caches (with and without tags)
        $mutableCache = $cacheManager->getCache('mutable_cache');
        $immutableCache = $cacheManager->getCache('immutable_cache');

        $mutableCache->set('test_key', 'test_value');
        $mutableCache->set('tagged_key', 'tagged_value', ['group_tag']);

        $immutableCache->set('test_key', 'test_value');
        $immutableCache->set('tagged_key', 'tagged_value', ['group_tag']);

        // First test tag-based flushing
        $cacheManager->flushCachesByTag('group_tag');

        // Only mutable cache entry with the tag should be flushed
        $this->assertEquals('test_value', $mutableCache->get('test_key'));
        $this->assertFalse($mutableCache->get('tagged_key'));
        $this->assertEquals('test_value', $immutableCache->get('test_key'));
        $this->assertEquals('tagged_value', $immutableCache->get('tagged_key'));

        // Reset tagged entry
        $mutableCache->set('tagged_key', 'tagged_value', ['group_tag']);

        // Flush caches in 'system' group
        $cacheManager->flushCachesInGroup('system');

        // Mutable cache should be empty, immutable cache should still have values
        $this->assertFalse($mutableCache->get('test_key'));
        $this->assertFalse($mutableCache->get('tagged_key'));
        $this->assertEquals('test_value', $immutableCache->get('test_key'));
        $this->assertEquals('tagged_value', $immutableCache->get('tagged_key'));
    }
}
