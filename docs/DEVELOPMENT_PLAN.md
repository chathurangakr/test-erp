# Development Plan — Coconut Factory ERP

> Sequencing only. A phase is not started until the previous one is confirmed complete
> **and** its open questions are answered.

## Phase 0 — Infrastructure ✅ COMPLETE

Ubuntu 26.04, PHP 8.5.4, Laravel 13.31, Vue 3 + Inertia + Tailwind, MariaDB 11.8.6
(`coconut_erp_dev`, dedicated non-root user), Nginx dev HTTPS on port 8443.
Baseline committed. Ports 80/443 reserved for GitLab.

## Phase 1 — Documentation & Product Master ✅ COMPLETE

- [x] `CLAUDE.md` with mandatory development rules
- [x] `docs/` — requirements, business rules, inventory, accounting, data model, plan
- [x] `units_of_measure` + `products` tables
- [x] Seeders for UOMs and the 8 initial products
- [x] Product Master UI — list, search, create, edit, view, activate/deactivate
- [x] Validation and automated tests
- [x] Open design decisions resolved (UOM, warehouse, lot/batch, negative stock, waste)

**Explicitly out of scope for this phase:** inventory, suppliers, customers, purchasing,
production, sales, finance, role-based permissions.

## Phase 2 — Master data ✅ COMPLETE

- [x] **Supplier Master** — single table for all materials bought; `payment_terms_days`
      cannot be negative
- [x] **Customer Master** — single table for all products sold; `credit_limit`
      `decimal(15,2)` and `credit_days`, neither may be negative
- [x] **Warehouse Master** — multi-warehouse; exactly one active default, enforced
      transactionally by `SetDefaultWarehouse` (see `DATA_MODEL.md` D-2)
- [x] Only `MAIN` / "Main Warehouse" seeded. The example yards (Coconut Yard, Husk Yard,
      Copra Store, Shell Yard, Finished Goods Store) are **not** seeded
- [x] Responsive CRUD UIs matching the Product Master style; no destroy routes
- [x] Feature tests for all three masters

**Deliberately not implemented:** customer balances, receivables, supplier payables, price
lists, or any per-party product restriction.

## Phase 3 — Inventory engine ✅ COMPLETE

The foundation for everything downstream.

- [x] `inventory_lots`, `inventory_transactions`, `inventory_transaction_lines`
- [x] Stock derived from the ledger — no stock column, no cache
- [x] Weighted average cost at company/product level, BCMath throughout
- [x] Negative stock rejected atomically, under a `FOR UPDATE` product lock
- [x] Warehouse transfers — value-neutral, atomic, same-warehouse and inactive rejected
- [x] Reversals — new opposite transaction, single-use, never edits the original
- [x] Dual UOM (shell NOS + KG) with no fixed conversion
- [x] Posted transactions immutable at the model level
- [x] Read-only Stock on Hand / Stock Ledger / Lots screens
- [x] 46 inventory tests

**Deliberately not implemented:** posting UI, transfer UI, manual lot creation UI,
financial journals, joint-product cost allocation.

## Phase 4 — Purchasing & Goods Receipt ✅ COMPLETE

- [x] `purchase_orders`, `purchase_order_lines`, `goods_receipts`, `goods_receipt_lines`
- [x] Purchase order lifecycle: DRAFT → APPROVED → PARTIALLY_RECEIVED → RECEIVED, plus
      CANCELLED; only a draft is freely editable
- [x] Goods receipts **against a purchase order** and **direct**, with no order
- [x] Landed cost — transport, loading and other, allocated by material value, with the
      landed unit cost posted to inventory
- [x] Lot creation per receipt line, supplier batch reference stored separately
- [x] Posting through `PostInventoryTransaction` as `PURCHASE_RECEIPT`, fully atomic
- [x] Reversal through `ReverseInventoryTransaction`, restoring purchase order quantities
- [x] Inactive-product incoming-stock rule enforced and tested
- [x] Purchase order and goods receipt screens; supplier purchase history
- [x] 43 purchasing tests

**Deliberately not implemented:** supplier payables, invoice matching, purchase returns to
supplier, approval hierarchies, and any general-ledger posting.

## Phase 5 — Production (not started) ◀ NEXT

*(Numbered before Sales in the original plan; Sales was built first at the user's direction.)*

1. **Dehusking** — fixed 1:1:1.
2. **Husk chipping** — operator-entered output weights; no fixed conversion; waste support.
3. **Copra production** — operator-entered copra weight; shell as `NOS` **and** `KG`.

**Unblocked.** Chipping waste is recorded as **production waste and creates no inventory**.
No `DUST` product is to be created; a by-product may be promoted to a stocked product later
if the factory begins selling it.

**Still open:** partial/failed production output; whether waste carries allocated cost.

## Phase 6 — Sales & Dispatch ✅ COMPLETE

- [x] `sales_orders`, `sales_order_lines`, `deliveries`, `delivery_lines`
- [x] Sales order lifecycle: DRAFT → APPROVED → PARTIALLY_DELIVERED → DELIVERED, plus
      CANCELLED; only a draft is freely editable
- [x] Deliveries **against a sales order** and **direct**, with no order
- [x] Posting issues `SALES_ISSUE` through the inventory engine, fully atomic
- [x] Cost of goods sold read back from the ledger; gross profit and margin computed
- [x] Explicit lot selection wherever lotted stock exists
- [x] Dual-unit pricing via `pricing_basis`, driven by the product's units
- [x] Reversal restoring stock at original cost and sales order quantities
- [x] Sales order and delivery screens; customer sales history
- [x] 51 sales tests

**Deliberately not implemented:** accounts receivable, customer balances, payments, sales
returns as a distinct document, price lists, and any general-ledger posting.

## Phase 7 — Finance (not started)

Double-entry: chart of accounts, journals, balanced posting, derived balances.
Then Weighted Average Cost and configurable joint-product allocation.
**Blocked on:** chart of accounts, allocation method, currency, tax.

## Phase 8 — Permissions (not started)

Role-based permissions. Currently **any authenticated user** may access Product Master.

## Phase 9 — REST API for Flutter (not started)

Versioned REST API reusing the same action classes as the Inertia controllers.

---

## Cross-cutting standing rules

| Rule | Source |
| --- | --- |
| Stock derived from ledger, never a stock column | `INVENTORY_RULES.md` §1 |
| All inventory written via `PostInventoryTransaction` | `INVENTORY_RULES.md` §13 |
| Posted inventory is immutable; correct by reversal | `INVENTORY_RULES.md` §10, §12 |
| Decimal-safe arithmetic via `DecimalMath` (BCMath) | `INVENTORY_RULES.md` §8 |
| Inactive products: issue out yes, receive new stock no | `INVENTORY_RULES.md` §14 |
| Landed cost allocated by material value, never quantity | `BUSINESS_RULES.md` §13 |
| Calculated costs are never accepted from a request | `BUSINESS_RULES.md` §13 |
| Debits = Credits on every journal | `ACCOUNTING_RULES.md` §2 |
| `decimal` for money/quantities, never float | `CLAUDE.md` |
| Transactions for inventory/production/accounting | `CLAUDE.md` |
| Posted transactions reversed, never edited | `CLAUDE.md` |
| Audit history never deleted | `CLAUDE.md` |
| Business logic in action classes, reusable by API | `CLAUDE.md` |
| Forward migrations; no `migrate:fresh` without explanation | `CLAUDE.md` |

## Consolidated open questions

Resolved decisions are recorded in `BUSINESS_REQUIREMENTS.md` §6; remaining questions in
§7. **No question currently blocks Phase 2 or Phase 3** — both may proceed when instructed.

The remaining questions cluster around Finance (Phase 7): chart of accounts, currency, tax,
and joint-product cost allocation. These do not block inventory or production work.
