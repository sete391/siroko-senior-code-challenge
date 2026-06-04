<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Http;

use Siroko\Shared\Application\Command\CommandBus;
use Siroko\Shared\Application\Command\Command;
use Siroko\Shared\Application\Query\Query;
use Siroko\Shared\Application\Query\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

abstract class ApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {
    }

    protected function dispatch(Command $command): mixed
    {
        return $this->commandBus->dispatch($command);
    }

    protected function ask(Query $query): mixed
    {
        return $this->queryBus->dispatch($query);
    }
}
