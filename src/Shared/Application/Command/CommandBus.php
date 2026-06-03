<?php

declare(strict_types=1);

namespace Siroko\Shared\Application\Command;

interface CommandBus
{
    public function dispatch(Command $command): mixed;
}
