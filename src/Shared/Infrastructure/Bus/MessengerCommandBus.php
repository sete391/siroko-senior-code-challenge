<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Bus;

use Siroko\Shared\Application\Command\Command;
use Siroko\Shared\Application\Command\CommandBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class MessengerCommandBus implements CommandBus
{
    public function __construct(private readonly MessageBusInterface $commandBus)
    {
    }

    public function dispatch(Command $command): mixed
    {
        $envelope = $this->commandBus->dispatch($command);
        $stamp    = $envelope->last(HandledStamp::class);

        return $stamp?->getResult();
    }
}
