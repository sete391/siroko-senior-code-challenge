<?php

declare(strict_types=1);

namespace Siroko\Shared\Domain\ValueObject;

abstract class UuidValueObject
{
    private const UUID_V4_PATTERN =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(private readonly string $value)
    {
        if (1 !== preg_match(self::UUID_V4_PATTERN, $value)) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid UUID v4.', $value),
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
