<?php

declare(strict_types=1);

namespace Siroko\Shared\Domain\Event;

trait DomainEventRecorderTrait
{
    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    protected function record(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }
}
