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
This defines a pages cache with the fallback cache: pages_fallback
```PHP
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'] = [
    'frontend' => VariableFrontend::class,
    'backend' => RedisBackend::class,
    'options' => [
        'defaultLifetime' => 604800,
        'compression' => 0,
    ],
    'fallback' => 'pages_fallback',
    'groups' => [
        'pages',
    ]
];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback'] = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback']['backend'] = SimpleFileBackend::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback']['options'] = [
    'defaultLifetime' => 604800,
];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback']['fallback'] = 'pages_fallback_fallback';

// possible, but do you need that??
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages_fallback_fallback'] = [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => [
        'compression' => 1,
    ],
    'groups' => [
        'pages',
    ]
];
```

Take care, the Fallback should implement the same frontend as the origin cache.

You can *chain* them and also define a fallback for the fallback cache.

You could end the chain with a cache with the NullBackend, if that also fails the hope for this TYPO3 request is lost. 

Inspired by https://packagist.org/packages/b13/graceful-cache
