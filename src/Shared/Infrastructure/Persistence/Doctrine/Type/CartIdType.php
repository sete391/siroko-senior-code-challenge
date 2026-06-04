<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\StringType;
use Siroko\Sales\Domain\ValueObject\CartId;

final class CartIdType extends StringType
{
    public const NAME = 'cart_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CartId
    {
        if (null === $value || $value instanceof CartId) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, CartId::class, ['null', CartId::class, 'string']);
        }

        return new CartId($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof CartId) {
            return $value->value();
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, 'string', ['null', CartId::class, 'string']);
        }

        return $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
