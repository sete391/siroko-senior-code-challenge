<?php

declare(strict_types=1);

namespace Siroko\Shared\Application\Query;

interface QueryBus
{
    public function dispatch(Query $query): mixed;
}
