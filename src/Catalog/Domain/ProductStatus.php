<?php

declare(strict_types=1);

namespace Siroko\Catalog\Domain;

enum ProductStatus: string
{
    case ACTIVE   = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
