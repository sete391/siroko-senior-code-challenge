<?php

declare(strict_types=1);

namespace Siroko\Shared\Application\Transaction;

interface TransactionManager
{
    /**
     * Runs the given operation inside a single transaction.
     * Commits on success; rolls back and rethrows on any exception.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function wrapInTransaction(callable $operation): mixed;
}
