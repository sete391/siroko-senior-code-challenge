<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Cart;

enum CartStatus: string
{
    case OPEN         = 'OPEN';
    case CHECKED_OUT  = 'CHECKED_OUT';
}
