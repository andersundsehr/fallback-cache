# What does it do?

In simple words - it makes your Website reachable still if your cache does not work. Think about a network issue on the provider side where your Redis Cluster should be reachable. Or someone updates your SQL Cluster, configured for the TYPO3 Instance and did not tell you!
Also, the fallback cache ensures components to be still cached, to reduce system load.

In addition, the Cache itself has a new Interface it can implement and tell this Extension it went bad:
- The cache could control its velocity
- It could check if it gets out of space
- It could be gracefully down for maintenance
- The cache backend was shut down in panic as a new security issue found
- Someone pulled the plug to do some vacuuming in the server room

After the fallback period the cache on the primary system is outdated and has to be cleared!

## Example
This defines a pages cache with the fallback cache: pages_fallback.

To catch exceptions a variable frontend is set that sents a event with status yellow on exception.

```PHP
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'] = [
    // This frontend surrounds the functions by a try, and sends an event on exception (Status yellow)
    'frontend' => \Weakbit\FallbackCache\Cache\Frontend\VariableFrontend::class,
    'backend' => RedisBackend::class,
    'options' => [
        'defaultLifetime' => 604800,
        'compression' => 0,
    ],
    // If the cache creation fails (Status red) this cache is used 
    'fallback' => 'pages_fallback',
    // The concrete frontend the 'frontend' is based on
    'conrete_frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'groups' => [
        'pages',
    ]
];

// Configure the fallback cache to use the database for example
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback'] = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback']['backend'] = Typo3DatabaseBackend::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback']['options'] = [
    'defaultLifetime' => 604800,
];
```

You can *chain* them and also define a fallback for the fallback cache.

You could end the chain with a cache with the NullBackend, if that also fails the hope for this TYPO3 request is lost. But using no cache may bring down your server, but that depends on the server and application.

# TODO
- [ ] Give a possibility to see the actual state of the caches
- [ ] Refactor addCacheStatus to comply with external calls


Inspired by https://packagist.org/packages/b13/graceful-cache
