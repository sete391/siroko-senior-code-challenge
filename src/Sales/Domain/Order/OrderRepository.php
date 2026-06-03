<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Order;

use Siroko\Sales\Domain\ValueObject\OrderId;

interface OrderRepository
{
    public function findById(OrderId $id): ?Order;

    public function save(Order $order): void;
}
