<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Siroko\Catalog\Domain\ProductId;

final class ProductIdType extends StringType
{
    public const NAME = 'product_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ProductId
    {
        if (null === $value || $value instanceof ProductId) {
            return $value;
        }

        assert(is_string($value));

        return new ProductId($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof ProductId) {
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
