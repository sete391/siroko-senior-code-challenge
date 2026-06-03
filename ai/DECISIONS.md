## DECISION-001: Optimistic locking
**Proposed by:** Claude Code (in generated PLAN.md)

**Proposal:** Add a `version` column to `products` table and use Doctrine
optimistic locking to handle concurrent stock decrements safely.

**Rejected because:**
Adds complexity without demonstrable value within the scope of this challenge.
The challenge does not require concurrency handling, and implementing it without
specific tests to prove its correctness would be speculative engineering.

**Decision:**
Shipped without optimistic locking.