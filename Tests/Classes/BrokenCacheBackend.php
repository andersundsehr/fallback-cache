<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Tests\Classes;

use TYPO3\CMS\Core\Cache\Backend\FileBackend;

class BrokenCacheBackend extends FileBackend
{
    public function __construct($context, array $options = [])
    {
        throw new \Exception('Broken cache backend');
    }
}
