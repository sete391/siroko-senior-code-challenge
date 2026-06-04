<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Identity;

use Siroko\Shared\Application\Identity\IdGenerator;
use Symfony\Component\Uid\Uuid;

final class SymfonyUidGenerator implements IdGenerator
{
    public function generate(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
