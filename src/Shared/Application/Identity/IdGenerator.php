<?php

declare(strict_types=1);

namespace Siroko\Shared\Application\Identity;

interface IdGenerator
{
    public function generate(): string;
}
