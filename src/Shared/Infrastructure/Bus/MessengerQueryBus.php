<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Bus;

use Siroko\Shared\Application\Query\Query;
use Siroko\Shared\Application\Query\QueryBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class MessengerQueryBus implements QueryBus
{
    public function __construct(private readonly MessageBusInterface $queryBus)
    {
    }

    public function dispatch(Query $query): mixed
    {
        $envelope = $this->queryBus->dispatch($query);
        $stamp    = $envelope->last(HandledStamp::class);

        return $stamp?->getResult();
    }
}
