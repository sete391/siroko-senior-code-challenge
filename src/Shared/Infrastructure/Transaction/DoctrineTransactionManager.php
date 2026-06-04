<?php

declare(strict_types=1);

namespace Siroko\Shared\Infrastructure\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Siroko\Shared\Application\Transaction\TransactionManager;

final class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function wrapInTransaction(callable $operation): mixed
    {
        return $this->em->wrapInTransaction($operation);
    }
}
