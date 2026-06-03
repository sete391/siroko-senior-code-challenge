<?php

declare(strict_types=1);

namespace Siroko\Sales\Domain\Exception;

use Siroko\Sales\Domain\Service\CoherenceIssue;
use Siroko\Shared\Domain\Exception\DomainException;

final class CheckoutCoherenceFailed extends DomainException
{
    /** @param list<CoherenceIssue> $issues */
    public function __construct(private readonly array $issues)
    {
        parent::__construct('Some cart items changed and were refreshed. Review your cart.');
    }

    /** @return list<CoherenceIssue> */
    public function issues(): array
    {
        return $this->issues;
    }
}
