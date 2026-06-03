<?php

declare(strict_types=1);

namespace Siroko\Shared\Application\Event;

use Siroko\Shared\Domain\Event\DomainEvent;

interface EventBus
{
    public function dispatch(DomainEvent ...$events): void;
}
