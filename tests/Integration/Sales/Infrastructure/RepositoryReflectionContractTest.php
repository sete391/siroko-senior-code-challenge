<?php

declare(strict_types=1);

namespace Siroko\Tests\Integration\Sales\Infrastructure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Sales\Domain\Cart\Cart;
use Siroko\Sales\Domain\Order\Order;

/**
 * Guards the ReflectionProperty contract used by Doctrine repositories.
 *
 * DoctrineCartRepository and DoctrineOrderRepository set the $items property
 * via ReflectionProperty::setValue() after hydrating items from DBAL queries.
 * If $items is ever renamed in the aggregate, these tests fail immediately with
 * an actionable message instead of silently loading aggregates with empty lists.
 *
 * These tests do NOT need the Symfony kernel or a database connection.
 */
final class RepositoryReflectionContractTest extends TestCase
{
    #[Test]
    public function cart_aggregate_has_an_items_property_for_the_reflection_contract(): void
    {
        self::assertTrue(
            (new \ReflectionClass(Cart::class))->hasProperty('items'),
            'Cart::$items was renamed. Update DoctrineCartRepository::hydrateItems() '
            . 'to match the new property name, then update this test.',
        );
    }

    #[Test]
    public function order_aggregate_has_an_items_property_for_the_reflection_contract(): void
    {
        self::assertTrue(
            (new \ReflectionClass(Order::class))->hasProperty('items'),
            'Order::$items was renamed. Update DoctrineOrderRepository::hydrateItems() '
            . 'to match the new property name, then update this test.',
        );
    }
}
