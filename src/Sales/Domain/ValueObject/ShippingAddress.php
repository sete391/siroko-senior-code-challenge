<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\ValueObject;

use Siroko\Sales\Domain\Exception\InvalidShippingAddress;

final class ShippingAddress
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $vatNumber,
        public readonly string $street,
        public readonly string $city,
        public readonly string $state,
        public readonly string $zipCode,
        public readonly string $country,
    ) {
        $fields = [
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'vatNumber' => $vatNumber,
            'street'    => $street,
            'city'      => $city,
            'state'     => $state,
            'zipCode'   => $zipCode,
            'country'   => $country,
        ];

        foreach ($fields as $name => $value) {
            if ('' === $value) {
                throw InvalidShippingAddress::emptyField($name);
            }
        }
    }
}
