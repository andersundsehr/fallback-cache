<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Cache;

use Weakbit\FallbackCache\Enum\StatusEnum;

interface CacheStatusInterface
{
    function emitCacheStatusEvent(): void;
}
