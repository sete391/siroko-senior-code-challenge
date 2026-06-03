<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\ProcessPayment;

use Siroko\Shared\Application\Command\Command;

final class ProcessPaymentCommand implements Command
{
    public function __construct(
        public readonly string $orderId,
        public readonly bool $result,
    ) {
    }
}
