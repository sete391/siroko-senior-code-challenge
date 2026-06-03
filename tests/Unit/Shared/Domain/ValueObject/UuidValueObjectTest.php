<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Shared\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Shared\Domain\ValueObject\UuidValueObject;

final class UuidValueObjectTest extends TestCase
{
    private const VALID_UUID_V4 = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';

    // ------------------------------------------------------------------ construction

    #[Test]
    public function it_accepts_a_valid_uuid_v4(): void
    {
        $uuid = $this->make(self::VALID_UUID_V4);

        self::assertSame(self::VALID_UUID_V4, $uuid->value());
    }

    #[Test]
    #[DataProvider('invalidUuids')]
    public function it_rejects_invalid_uuid_v4(string $invalid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid UUID v4/i');

        $this->make($invalid);
    }

    /** @return array<string, array{string}> */
    public static function invalidUuids(): array
    {
        return [
            'empty string'      => [''],
            'random string'     => ['not-a-uuid'],
            'uuid v1'           => ['550e8400-e29b-11d4-a716-446655440000'],
            'uuid v3'           => ['6ba7b810-9dad-31d1-80b4-00c04fd430c8'],
            'missing hyphens'   => ['f47ac10b58cc4372a5670e02b2c3d479'],
            'wrong version bit' => ['f47ac10b-58cc-5372-a567-0e02b2c3d479'],
            'wrong variant bit' => ['f47ac10b-58cc-4372-0567-0e02b2c3d479'],
            'too short'         => ['f47ac10b-58cc-4372-a567-0e02b2c3d47'],
        ];
    }

    // ------------------------------------------------------------------ value

    #[Test]
    public function value_returns_the_original_string(): void
    {
        $uuid = $this->make(self::VALID_UUID_V4);

        self::assertSame(self::VALID_UUID_V4, $uuid->value());
    }

    // ------------------------------------------------------------------ equals

    #[Test]
    public function it_is_equal_to_another_instance_with_same_value(): void
    {
        $a = $this->make(self::VALID_UUID_V4);
        $b = $this->make(self::VALID_UUID_V4);

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function it_is_not_equal_to_a_different_uuid(): void
    {
        $a = $this->make(self::VALID_UUID_V4);
        $b = $this->make('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');

        self::assertFalse($a->equals($b));
    }

    // ------------------------------------------------------------------ helpers

    private function make(string $value): UuidValueObject
    {
        return new class($value) extends UuidValueObject {};
    }
}
