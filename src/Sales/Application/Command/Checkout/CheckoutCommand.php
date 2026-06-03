<?php

declare(strict_types=1);

namespace Siroko\Sales\Application\Command\Checkout;

use Siroko\Shared\Application\Command\Command;

final class CheckoutCommand implements Command
{
    public function __construct(
        public readonly string $cartId,
        public readonly ?string $customerId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $vatNumber,
        public readonly string $street,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zipCode,
        public readonly string $country,
    ) {
    }
}
