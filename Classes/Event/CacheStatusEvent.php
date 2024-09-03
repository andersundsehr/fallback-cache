<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Event;

use Weakbit\FallbackCache\Enum\StatusEnum;

class CacheStatusEvent
{
    public function __construct(private readonly StatusEnum $status, private readonly string $identifier)
    {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getStatus(): StatusEnum
    {
        return $this->status;
    }
}
