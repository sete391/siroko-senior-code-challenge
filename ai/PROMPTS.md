# Generate PLAN.md

AI used to complete planning based on predefined guidelines

```
Read /ai/PLAN.md and rewrite it completely as a full implementation
guide, incorporating the existing content and expanding it with:

1. Architectural decisions: Hexagonal Architecture, DDD, CQRS,
   tech stack (Symfony 7, PHP 8.4, MySQL)
2. Domain modelling: aggregates, internal entities, value objects,
   domain events and business rules
3. Detailed use cases: input, output, happy path and sad paths
   for each flow
4. Project folder structure
5. API endpoints: HTTP method, route, request and response
6. Persistence schema: tables and Doctrine mapping considerations
7. Testing strategy: what is tested and how
8. Explicit constraints that the AI must NOT do during implementation
```

# Implement Shared/Domain layer

Implementation started in deliberate phases

```
Read ai/PLAN.md sections 3.3, 3.5 and 11 before writing any code.

Implement the Shared/Domain layer and their unit tests.
Cover all invariants including sad paths.
```

# Implement CheckoutHandler and ProcessPaymentHandler

Complex functionality broken into phases for controlled generation

```
Read ai/PLAN.md sections 4.7, 4.9 and 11 before writing any code.

Implement CheckoutHandler and ProcessPaymentHandler and their unit tests.

Key requirements:
- Checkout runs entirely inside a single Doctrine transaction (wrapInTransaction)
- Order of operations: assert cart ownership → assert not empty → coherence check → decrease stock → mark cart checked out → create order → commit → dispatch events
- On coherence failure: refresh snapshots, rollback, return 409 with affected items — no order created, no stock decremented
- ProcessPayment on failure: restore stock for each order item, reopen cart (missing cart is silently ignored), set order to PAYMENT_ERROR
- Both handlers dispatch domain events only after commit
```

# Infrastructure layer

Infrastructure kept separate from other layers

```
Read ai/PLAN.md before writing any code.

Implement the Infrastructure layer:
- Shared/Infrastructure: MessengerCommandBus, MessengerQueryBus, DoctrineTransactionManager, MessengerEventBus, SymfonyUidGenerator, ApiController, ExceptionListener
- Catalog/Infrastructure: DoctrineProductRepository, Product.orm.xml, ProductController
- Sales/Infrastructure: DoctrineCartRepository, DoctrineOrderRepository, Cart.orm.xml, Order.orm.xml, CartController, OrderController

Also implement the database migrations and a console command to seed products.
```

# Functional tests

Functional tests generated with explicit rules and sad paths defined upfront

```
Read ai/PLAN.md sections 6 and 8.3 before writing any code.

Implement functional tests for all HTTP endpoints using Symfony's KernelBrowser. Cover the happy path for each endpoint plus the key sad paths: add to non-existent cart creates it, add over stock returns 409, ownership mismatch returns 403, checkout of empty cart returns 409, coherence failure returns 409 with affectedItems, payment false restores stock and reopens cart, payment on non-PENDING order returns 409. Assert both status codes and response body shape.
```
