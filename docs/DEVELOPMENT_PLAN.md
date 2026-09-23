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

## Phase 5 — Production — Dehusking ✅ COMPLETE

*(Numbered before Sales in the original plan; Sales was built first at the user's direction.)*

- [x] `production_recipes`, `production_recipe_outputs`, `production_orders`,
      `production_inputs`, `production_outputs`
- [x] Extensible `ProductionType` (DEHUSKING implemented; HUSK_CHIPPING and COPRA declared
      but not yet postable — both built in later phases)
- [x] Lifecycle DRAFT → POSTED → REVERSED, plus CANCELLED
- [x] Fixed 1:1:1 conversion held as recipe configuration, outputs derived and read-only
- [x] Posts `PRODUCTION_CONSUMPTION` + `PRODUCTION_OUTPUT` atomically
- [x] Configurable value allocation (85/15, provisional) summing exactly to consumed value
- [x] New traceable output lot per output, linked to the run and the consumed lot
- [x] Reversal restoring stock at original cost, refused once output has been consumed
- [x] Dehusking index / create / show screens
- [x] 44 production tests

**Deliberately not implemented at the time:** copra (built in Phase 8), production
planning, BOM editor, maintenance,
labour/electricity/machine/fuel/overhead costs, and any general-ledger posting.

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

## Phase 7 — Production — Husk Chipping ✅ COMPLETE

Built on the Phase 5 production engine — no second engine, no duplicated posting code.

- [x] `HUSK_CHIPPING` becomes an implemented `ProductionType`
- [x] `output_ratio` made **nullable**; `NULL` means *no fixed conversion — the operator
      measures the output*, which is what makes a husk→KG ratio structurally impossible
- [x] Recipe seeded `RAW-HUSK` → `HUSK-7C` / `HUSK-9C` / `HUSK-10C`, 30 / 30 / 40 provisional
- [x] Operator-entered output weights; zero allowed per grade, never for every grade;
      negatives refused in the request and again at posting
- [x] Shared `ProductionCostAllocator` with **zero-output redistribution**, used by
      dehusking too
- [x] `waste_quantity` + `waste_uom_id` — informational only, optional, no product, no lot,
      no cost, no `DUST`
- [x] A new lot per **produced** grade, traceable to the consumed husk lot
- [x] Reversal via the existing `ReverseProduction`, refused entirely if any grade has been
      consumed
- [x] Index / create / edit / show screens with a live allocation preview; sidebar entry
- [x] 55 husk chipping tests

**Deliberately not implemented at the time:** copra (built in Phase 8), finance, GL,
production planning, maintenance, generic BOM editor.

**Still open:** the waste measurement basis (`BUSINESS_RULES.md` §19 TBD-1); whether waste
should ever carry an allocated cost; the permanent joint-product costing method.

## Phase 8 — Production — Copra & Raw Coconut Shell ✅ COMPLETE

Built on the same production engine again — still no second engine, still no duplicated
posting code.

- [x] `COPRA` becomes an implemented `ProductionType`
- [x] `production_outputs.secondary_quantity` added — the operator's measured weight, never
      a conversion of the primary quantity
- [x] `production_recipe_outputs.requires_secondary_quantity` added — the smallest reusable
      extension that lets one recipe hold a **derived** output and a **measured** one
      together, with no product code in business logic
- [x] Recipe seeded `DEHUSKED-COCONUT` → `COPRA` (ratio `NULL`, 75%) + `RAW-SHELL`
      (ratio `1.000000`, secondary required, 25%)
- [x] Operator-entered copra KG and shell KG, both required and positive; a submitted shell
      **count** is refused by the request and recalculated by the posting action regardless
- [x] Shared `ProductionCostAllocator` — value conserved exactly, shell valued per `NOS`
- [x] Informational shell cost per KG, computed for display only and labelled as such
- [x] A new lot per output, traceable back through dehusking to the whole coconut lot
- [x] Reversal via the existing `ReverseProduction`, mirroring the measured weight, refused
      entirely if either output has been consumed
- [x] Index / create / edit / show screens with a read-only derived shell count and a
      provisional allocation preview; sidebar entry
- [x] 74 copra tests

**Deliberately not implemented:** finance, GL, production planning, maintenance, generic
BOM editor, activated carbon / shell processing.

**Still open:** drying / moisture / process loss (`BUSINESS_RULES.md` §20 TBD-3); the
permanent joint-product costing method that replaces 75 / 25 (TBD-2).

## Cross-cutting — End-to-end factory validation ✅ COMPLETE

Not a business module: a validation phase proving the finished modules agree with one
another. No new business logic was built.

- [x] `tests/Feature/Integration/FactoryWorkflowIntegrationTest.php` — one realistic flow
      across master data, purchasing, inventory, sales, all three production types, dual-UOM
      shell stock, lot traceability, weighted average costing and reversals
- [x] Value conservation asserted exactly at document level and within the documented
      rounding bound at ledger level, for all three production types
- [x] Reconciliation sweep: no negative product/lot/warehouse stock, warehouse totals equal
      company totals, every weighted average recomputes from the ledger, no orphan lots,
      document lines or transactions
- [x] Reversal safety: all three production runs, and the coconut receipt, refuse reversal
      once downstream stock has been sold — atomically, with no partial restoration
- [x] Document lifecycle: posted documents immutable, drafts move no stock, reversed records
      stay historically visible
- [x] Scenario, arithmetic and findings written up in `docs/END_TO_END_VALIDATION.md`

**Found and fixed:** `PostDehuskingProduction` did not refuse a draft that already carried
output rows, which would have duplicated its outputs and double-counted the run's value. No
current caller could reach that state, but the planned REST API would reuse the action.

## Cross-cutting — Inventory valuation hardening ✅ COMPLETE

A precision fix taken **before** Finance, because accounting cannot open on a balance nobody
can reconcile. No new business logic, no schema change.

- [x] `total_cost` is the **authoritative** monetary value of a ledger line;
      `unit_cost` is only its rounded per-unit representation
- [x] `total_cost == quantity × unit_cost` retired as an invariant and replaced with
      representation rules (`INVENTORY_RULES.md` §8.1)
- [x] `PendingInventoryLine::withAuthoritativeCost()` — trusted domain services only;
      `fromArray()` has no total-cost key, so no request can value a stock movement
- [x] Purchasing passes the exact landed line value; all three production types pass the
      allocator's exact `allocated_cost`; transfers share one total across both legs;
      reversals reuse the original total
- [x] **Full depletion** takes the entire remaining value, so a sold-out product holds zero
      quantity *and* zero value — one rule, in `StockQueryService::issueValue()`
- [x] 19 new tests in `AuthoritativeCostTest`; two existing tests that asserted the old
      limitation **strengthened to exactness**, none weakened
- [x] End-to-end production conservation is now exact: residue `0.000000` for all three

**Result:** dehusking, husk chipping and copra all conserve ledger value to the last decimal.

## Phase 9 — Finance 1: accounting foundation ✅ COMPLETE

The accounting engine only. No operational document generates a journal yet.

- [x] `fiscal_periods`, `accounts`, `journal_entries`, `journal_entry_lines` — four
      forward-only migrations, no balance column anywhere
- [x] Enums: `AccountType` (with `COST_OF_SALES` as its own type), `NormalBalance`,
      `FiscalPeriodStatus`, `JournalEntryStatus`
- [x] `config/accounting.php` — base currency LKR as configuration, never a literal
- [x] 46-account starting chart, seeded and idempotent; control accounts and group headers
      refuse manual posting
- [x] `PostJournalEntry` enforcing all eleven posting rules atomically, including
      **debits == credits exactly**
- [x] `ReverseJournalEntry` — new entry, sides swapped at the exact original amounts,
      linked both ways, double reversal impossible at the database level
- [x] `CancelJournalEntry`, `CloseFiscalPeriod` (refuses while a draft remains),
      `GenerateJournalReference` (`JV-YYYYMMDD-000001`)
- [x] `AccountingBalanceService` — every figure derived from posted lines: totals, net,
      normal-side balance, as-of date, per period, by type, general ledger, trial balance
- [x] Screens: chart of accounts, journal entries (dynamic lines with a live
      debit/credit/difference footer), general ledger, trial balance, fiscal periods
- [x] 108 finance tests, including `NoOperationalAccountingTest` asserting that GRN,
      delivery and all three production types create **no** journal

**Deliberately not implemented:** AP, AR, payments, invoices, automatic journals from
operational documents, production expense accounting, VAT/SVAT, payroll, bank
reconciliation, P&L, balance sheet, year-end closing.

**Still open:** which business events generate journals and with what account mappings
(`ACCOUNTING_RULES.md` §12).

## Phase 10 — Finance 2: inventory accounting integration ✅ COMPLETE

Stock movements now reach the general ledger. No second accounting engine: the operational
actions build a draft and hand it to the existing `PostJournalEntry`.

- [x] `accounting_settings` (the cutover date and GRNI account) and
      `product_account_mappings` — two forward-only migrations
- [x] Chart extended: `2120` Goods Received Not Invoiced (liability, control),
      `4160` Raw Husk Sales, `5160` Raw Husk COGS → 49 accounts
- [x] The eight official products mapped; the QA product deliberately left **unmapped**
- [x] `AccountingPolicy` — does accounting apply to this date, which account, which period
- [x] `RecordOperationalJournal` — builds journals from the authoritative `total_cost` and
      posts them through the existing engine; owns reversal too
- [x] Hooks in all six operational actions, **inside their own transactions**, so stock and
      accounting commit or roll back together
- [x] GRN `Dr inventory / Cr GRNI` · delivery `Dr COGS / Cr inventory` (no revenue) ·
      production `Dr output / Cr input` · **transfer posts nothing**
- [x] Cutover frozen once operational journals exist
- [x] `/finance/accounting-setup` — cutover, GRNI, mapping table with unmapped warnings
- [x] 39 new tests: exact journals, reconciliation to the inventory ledger, trial balance
      balanced after every step, atomicity, duplicates, control-account access

**Deliberately not implemented:** supplier invoices and payables, sales invoices and
receivables, payments, revenue recognition, tax, payroll, bank reconciliation, year-end.

**Expected for this phase:** GRNI accumulates the purchase accrual and cost of sales
accumulates without revenue, because no invoice clears either yet.

## Phase 11 — Finance 3: accounts payable ✅ COMPLETE

Supplier invoices clearing the goods-received accrual, and the payments that settle them.

- [x] `supplier_invoices`, `supplier_invoice_lines`, `supplier_payments`,
      `supplier_payment_allocations`, plus `trade_payables_account_id` on the settings —
      five forward-only migrations
- [x] Two line types only: `GRNI_MATCH` (Dr GRNI) and `DIRECT_EXPENSE` (Dr expense), both
      crediting Trade Payables from configuration
- [x] **Partial, multi-supplier GRNI matching** — goods, transport and loading may each clear
      part of one receipt, capped at the remainder and locked against concurrent overdraw
- [x] The accrual read from the receipt's own posted journal, never recalculated; pre-cutover
      and reversed receipts are unmatchable
- [x] `GrniReconciliationService` and `AccountsPayableService` — every figure derived,
      nothing stored
- [x] Payments allocated to invoices (no prepayments), invoice status derived from what is
      still owed, aging bucketed by how overdue
- [x] Mutual reversal blocks: invoice blocked while paid, goods receipt blocked while invoiced
- [x] Screens: supplier invoices, supplier payments, AP aging, GRNI reconciliation, and an AP
      panel on Supplier Show
- [x] 58 new tests asserting the two critical invariants throughout: GRNI balance ==
      uninvoiced accrual, Trade Payables balance == total outstanding

**Deliberately not implemented:** sales invoices, receivables, customer receipts, revenue
recognition, VAT/SVAT, multi-currency, bank reconciliation, payroll, year-end closing.

**Still open:** the permanent purchase-price variance policy (`ACCOUNTING_RULES.md` §16).

## Phase 12 — Finance 4: accounts receivable (not started)

Sales invoices (`Dr Trade Receivables / Cr Revenue`) and customer receipts.
**Blocked on:** invoice document design, tax scope.

## Phase 13 — Permissions (not started)

Role-based permissions. Currently **any authenticated user** may access Product Master.

## Phase 14 — REST API for Flutter (not started)

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

The remaining questions cluster around Finance integration (Phase 10): chart of accounts, currency, tax,
and joint-product cost allocation. These do not block inventory or production work.
