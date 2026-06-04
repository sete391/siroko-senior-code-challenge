<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Siroko\Sales\Domain\ValueObject\OrderId;

final class OrderIdType extends StringType
{
    public const NAME = 'order_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?OrderId
    {
        if (null === $value || $value instanceof OrderId) {
            return $value;
        }

        assert(is_string($value));

        return new OrderId($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof OrderId) {
            return $value->value();
        }

        assert(is_string($value));

        return $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
