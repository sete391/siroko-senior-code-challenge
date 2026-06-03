<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Order;

enum OrderStatus: string
{
    case PENDING           = 'PENDING';
    case PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';
    case PAYMENT_ERROR     = 'PAYMENT_ERROR';
}
