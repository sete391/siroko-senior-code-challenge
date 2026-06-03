<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Sales\Domain\Cart;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Sales\Domain\Exception\InvalidShippingAddress;
use Siroko\Sales\Domain\ValueObject\ShippingAddress;

final class ShippingAddressTest extends TestCase
{
    #[Test]
    public function it_creates_a_valid_shipping_address(): void
    {
        $address = $this->validAddress();

        self::assertSame('Ada', $address->firstName);
        self::assertSame('ES', $address->country);
    }

    #[Test]
    #[DataProvider('emptyFields')]
    public function it_rejects_any_empty_field(string $field): void
    {
        $this->expectException(InvalidShippingAddress::class);
        $this->expectExceptionMessageMatches('/cannot be empty/i');

        $data = $this->validData();
        $data[$field] = '';

        new ShippingAddress(...$data);
    }

    /** @return array<string, array{string}> */
    public static function emptyFields(): array
    {
        return [
            'firstName' => ['firstName'],
            'lastName'  => ['lastName'],
            'vatNumber' => ['vatNumber'],
            'street'    => ['street'],
            'city'      => ['city'],
            'state'     => ['state'],
            'zipCode'   => ['zipCode'],
            'country'   => ['country'],
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function validAddress(): ShippingAddress
    {
        return new ShippingAddress(...$this->validData());
    }

    /** @return array<string, string> */
    private function validData(): array
    {
        return [
            'firstName' => 'Ada',
            'lastName'  => 'Lovelace',
            'vatNumber' => 'ES12345678Z',
            'street'    => 'Calle Mayor 1',
            'city'      => 'Madrid',
            'state'     => 'Madrid',
            'zipCode'   => '28013',
            'country'   => 'ES',
        ];
    }
}
