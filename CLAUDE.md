# CLAUDE.md — Siroko Cart & Checkout

Operational guide for Claude Code. Full specification is in `ai/PLAN.md` — read
it before implementing anything. The rules below are binding.

---

## 1. Project overview

Shopping cart and checkout API for Siroko's e-commerce platform (PHP 8.4 /
Symfony 7). Any user — authenticated or guest — can add products to a cart,
update quantities, and trigger a checkout that converts the cart into a
persistent order. A subsequent payment callback confirms or rolls back the
order. The domain is intentionally decoupled from the framework: Symfony is
wiring, not business logic.

---

## 2. Tech stack

| Concern | Choice |
|---------|--------|
| Language | PHP 8.4 (readonly, enums, named args) |
| Framework | Symfony 7.x |
| Persistence | MySQL 8 + Doctrine ORM 3 |
| CQRS buses | Symfony Messenger — `command.bus` / `query.bus` |
| IDs | UUID v4 via `symfony/uid` |
| Testing | PHPUnit 11 |
| Quality gates | PHPStan (max) + PHP-CS-Fixer |
| Runtime | Docker Compose (php-fpm, nginx, mysql) |

---

## 3. Folder structure rules

```
src/
├── Shared/Domain/          # Money, UuidValueObject, Quantity, DomainEvent, DomainException
├── Shared/Application/     # CommandBus, QueryBus interfaces + markers
├── Shared/Infrastructure/  # Messenger bus adapters, ApiController, ExceptionListener
│
├── Catalog/Domain/         # Product aggregate, ProductId, ProductStatus, ProductRepository (port)
├── Catalog/Application/    # ListProducts + GetProduct queries and handlers, ProductView DTO
├── Catalog/Infrastructure/ # DoctrineProductRepository, Product.orm.xml, ProductController
│
└── Sales/Domain/           # Cart+CartItem, Order+OrderItem, ShippingAddress, CustomerId,
│                           # CartRepository + OrderRepository (ports), CheckoutCoherenceChecker,
│                           # all domain exceptions
├── Sales/Application/      # AddItemToCart, UpdateCartItem, RemoveCartItem, Checkout,
│                           # ProcessPayment commands + handlers; GetCart, GetOrder queries +
│                           # handlers; CartView + OrderView DTOs
└── Sales/Infrastructure/   # DoctrineCartRepository, DoctrineOrderRepository,
                            # Cart.orm.xml, Order.orm.xml, CartController, OrderController

tests/
├── Unit/          # Domain layer only — no container, no DB
├── Integration/   # Doctrine repos against real MySQL
└── Functional/    # HTTP through the Symfony kernel
```

**File placement rules:**
- Each use case lives in its own subfolder: `Command/AddItemToCart/{Command,Handler}.php`.
- Doctrine XML mapping lives in `Infrastructure/Persistence/Doctrine/*.orm.xml`, never
  in the domain class.
- Domain exceptions live in `Domain/Exception/`, one file per exception.
- View DTOs (`*View.php`) live in `Application/DTO/` or alongside their handler.

---

## 4. Coding rules (from PLAN.md §11)

0. **Test first, always (TDD).** Write the test before the implementation.
   For every class, method, or invariant: red → green → refactor.
   Never commit implementation code that does not have a corresponding test
   written *before* it. This applies to domain services, aggregates, value
   objects, handlers, and controllers alike.

1. **No framework imports in Domain.** No Symfony, Doctrine, or Messenger
   `use` statements inside any `*/Domain` namespace.
2. **Dependencies point inward only.** Domain ← Application ← Infrastructure.
   Application must never import Infrastructure classes.
3. **Controllers only dispatch.** No repository calls, no handler calls, no
   business logic in controllers. They build a command/query and dispatch it.
4. **Queries return view DTOs, not aggregates.** Never expose an aggregate
   through a query or HTTP response.
5. **Use value objects at every boundary.** No raw UUID strings, no int prices,
   no plain address arrays crossing layer boundaries. Use `ProductId`, `CartId`,
   `OrderId`, `CustomerId`, `Money`, `ShippingAddress`, `Quantity`.
6. **Money is always integer cents.** Never use `float` for amounts.
7. **Checkout is a single transaction.** Stock decrement + cart status +
   order creation commit atomically. Dispatch domain events only after commit.
8. **Order prices come from CartItem snapshots.** Never read the live product
   price/tax at order-creation time. Validate coherence first, then copy.
9. **No FK from `orders.cart_id` to `carts`.** It is a plain column for
   traceability only.
10. **The one allowed silent swallow:** a missing cart when restoring stock
    on payment failure (§4.9). Everything else propagates.
11. **No new aggregates, tables, endpoints, or statuses** without updating
    `PLAN.md` first.
12. **Never weaken PHPStan or CS-Fixer** to make code pass. Fix the code.

---

## 5. Domain rules — invariants to respect

**Product**
- `unitPrice > 0`, `quantity >= 0`.
- Only `ACTIVE` products can be added to a cart or involved in checkout.

**Cart**
- Status: `OPEN` → `CHECKED_OUT` (by checkout) → `OPEN` (by payment failure only).
- A `CHECKED_OUT` cart is immutable — reject all mutations.
- No duplicate lines: adding an existing product merges quantities.
- Minimum line quantity: 1. Quantity 0 on add is rejected (`InvalidQuantity`).
- Line quantity cannot exceed product stock (`InsufficientStock`).
- `CartItem.unitPrice` and `taxAmount` are snapshots — captured once on add,
  never updated retroactively.

**Order**
- Cannot be created from an empty cart (`EmptyCartCannotCheckout`).
- Before creation, validate coherence: each product must exist, be active,
  have enough stock, and its current price/tax must match the CartItem snapshot.
  If not: refresh the snapshot, roll back, return a `409` warning.
- `totalOrder = totalProducts + totalTax`.
- `totalProducts = sum(unitPrice.amount × quantity)` per line.
- `totalTax = sum(taxAmount × quantity)` per line.
- `OrderItem` copies from `CartItem`, not from the live product.

**IDs**
- All IDs are UUID v4. Validate format on construction. Expose only via `value(): string`.

---

## 6. Out of scope — do not implement

- Customer registration, authentication, or account management.
- Product CRUD — products are seeded via a console command.
- Real payment gateway integration — the system only receives `bool result`.
- Tax calculation by country — `taxAmount` is a fixed per-product value.
- Shipping cost or carrier selection.
- Cart deletion or expiry logic.
- Event sourcing, separate read model/database, or microservices.
- Any endpoint, status, aggregate, or table not listed in `PLAN.md`.

---

## 7. Commit message conventions

```
<type>: <short imperative summary>   ← 72 chars max

[optional body: the why, not the what]
[one blank line before body]

Co-Authored-By: Claude Code <noreply@anthropic.com>
```

**Types:** `feat`, `fix`, `test`, `refactor`, `chore`, `docs`.

**Examples:**
```
feat: add CheckoutHandler with transactional stock decrement

fix: reject CHECKED_OUT cart on AddItemToCart command

test: cover CartItem snapshot isolation in unit tests

chore: add Doctrine XML mapping for Cart and CartItem
```

Rules:
- Imperative mood in the summary (`add`, `fix`, `reject` — not `added` / `fixes`).
- No period at the end of the summary line.
- Body explains *why*, not *what* — the diff already shows what changed.
- One commit per logical change; do not batch unrelated work.
