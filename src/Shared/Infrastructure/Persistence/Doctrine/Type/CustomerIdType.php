<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\StringType;
use Siroko\Sales\Domain\ValueObject\CustomerId;

final class CustomerIdType extends StringType
{
    public const NAME = 'customer_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CustomerId
    {
        if (null === $value || $value instanceof CustomerId) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, CustomerId::class, ['null', CustomerId::class, 'string']);
        }

        return new CustomerId($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof CustomerId) {
            return $value->value();
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, 'string', ['null', CustomerId::class, 'string']);
        }

        return $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
