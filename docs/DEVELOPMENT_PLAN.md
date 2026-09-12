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

## Phase 3 — Inventory ledger (not started) ◀ NEXT

The foundation for everything downstream. Must implement `INVENTORY_RULES.md` in full:
movement table with all required fields, derived stock, negative-stock guard, dual UOM for
Raw Shell, transactional writes.

**Unblocked.** Confirmed constraints: warehouse reference **required** on every movement;
lot/batch reference **supported** (nullable); negative inventory **not allowed by default**,
failing atomically.

**Still open:** inter-warehouse transfers, per-warehouse vs global valuation, lot numbering
scheme.

## Phase 4 — Purchasing (not started)

Purchase of Whole Coconut and direct purchase of Raw Husk. Posts inventory movements.

## Phase 5 — Production (not started)

1. **Dehusking** — fixed 1:1:1.
2. **Husk chipping** — operator-entered output weights; no fixed conversion; waste support.
3. **Copra production** — operator-entered copra weight; shell as `NOS` **and** `KG`.

**Unblocked.** Chipping waste is recorded as **production waste and creates no inventory**.
No `DUST` product is to be created; a by-product may be promoted to a stocked product later
if the factory begins selling it.

**Still open:** partial/failed production output; whether waste carries allocated cost.

## Phase 6 — Sales (not started)

Sale of Whole Coconut, Dehusked Coconut, Copra, 7C/9C/10C, and Raw Shell (by `NOS` count
or by `KG`).

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
