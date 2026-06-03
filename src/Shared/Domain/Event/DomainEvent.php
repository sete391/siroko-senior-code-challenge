<?php

declare(strict_types=1);

namespace Siroko\Shared\Domain\Event;

interface DomainEvent
{
    public function occurredOn(): \DateTimeImmutable;
}
