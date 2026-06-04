<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Event;

use Siroko\Shared\Application\Event\EventBus;
use Siroko\Shared\Domain\Event\DomainEvent;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerEventBus implements EventBus
{
    public function __construct(private readonly MessageBusInterface $eventBus)
    {
    }

    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->eventBus->dispatch($event);
        }
    }
}
