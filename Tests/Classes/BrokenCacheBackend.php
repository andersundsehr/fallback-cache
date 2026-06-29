<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Tests\Classes;

use Exception;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;

class BrokenCacheBackend extends FileBackend
{
    public function __construct()
    {
        throw new Exception('Broken cache backend', 4598365234);
    }
}
