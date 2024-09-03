<?php

declare(strict_types=1);

namespace Weakbit\FallbackCache\Enum;

enum StatusEnum: string
{
    case RED = 'red';
    // TODO gleich rot? ggfs will man erstmal sagen, er konnte ja instanziiert werden, dass ein logging stattfindet
    case YELLOW = 'yellow';
}
