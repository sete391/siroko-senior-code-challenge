<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Shared\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Shared\Domain\ValueObject\Quantity;

final class QuantityTest extends TestCase
{
    // ------------------------------------------------------------------ construction

    #[Test]
    public function it_creates_with_a_valid_positive_value(): void
    {
        $quantity = new Quantity(5);

        self::assertSame(5, $quantity->value);
    }

    #[Test]
    public function it_allows_value_of_one(): void
    {
        self::assertSame(1, (new Quantity(1))->value);
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function it_rejects_values_below_one(int $invalid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1/i');

        new Quantity($invalid);
    }

    /** @return array<string, array{int}> */
    public static function invalidValues(): array
    {
        return [
            'zero'          => [0],
            'negative'      => [-1],
            'large negative' => [-100],
        ];
    }

    // ------------------------------------------------------------------ add

    #[Test]
    public function it_adds_two_quantities(): void
    {
        $result = (new Quantity(3))->add(new Quantity(4));

        self::assertSame(7, $result->value);
    }

    #[Test]
    public function it_returns_a_new_instance_on_add(): void
    {
        $q = new Quantity(2);

        self::assertNotSame($q, $q->add(new Quantity(1)));
    }

    // ------------------------------------------------------------------ isGreaterThan

    #[Test]
    public function it_is_greater_than_a_smaller_stock(): void
    {
        self::assertTrue((new Quantity(5))->isGreaterThan(4));
    }

    #[Test]
    public function it_is_not_greater_than_equal_stock(): void
    {
        self::assertFalse((new Quantity(5))->isGreaterThan(5));
    }

    #[Test]
    public function it_is_not_greater_than_larger_stock(): void
    {
        self::assertFalse((new Quantity(3))->isGreaterThan(10));
    }

    // ------------------------------------------------------------------ equals

    #[Test]
    public function it_is_equal_when_values_match(): void
    {
        self::assertTrue((new Quantity(3))->equals(new Quantity(3)));
    }

    #[Test]
    public function it_is_not_equal_when_values_differ(): void
    {
        self::assertFalse((new Quantity(3))->equals(new Quantity(4)));
    }
}
