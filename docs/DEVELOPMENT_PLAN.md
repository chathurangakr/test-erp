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

**Deliberately not implemented:** VAT/SVAT, multi-currency, bank reconciliation, payroll,
year-end closing.

**Still open:** the permanent purchase-price variance policy (`ACCOUNTING_RULES.md` §16).

## Phase 12 — Finance 4: accounts receivable ✅ COMPLETE

Sales invoices recognising revenue against delivered goods, and the receipts that settle them.

- [x] `sales_invoices`, `sales_invoice_lines`, `customer_receipts`,
      `customer_receipt_allocations`, plus `trade_receivables_account_id` on the settings —
      five forward-only migrations
- [x] Every invoice line bills a **delivery line**; the revenue account is resolved through the
      product's mapping and never offered as a choice
- [x] The billable amount is the delivery line's `sales_value`, never recalculated — so dual-unit
      raw shell needs no NOS↔KG conversion
- [x] **Partial billing both ways** — one delivery across many invoices, one invoice across many
      deliveries, capped at the remainder and locked against concurrent over-billing
- [x] Pre-cutover and reversed deliveries are unbillable; no historic data is backfilled
- [x] `SalesBillingReconciliationService` and `AccountsReceivableService` — every figure derived,
      nothing stored
- [x] Receipts allocated to invoices (no advances), invoice status derived from what is still
      owed, aging bucketed by how overdue
- [x] Mutual reversal blocks: invoice blocked while receipted, delivery blocked while invoiced
- [x] Screens: sales invoices, customer receipts, AR aging, unbilled deliveries, and an AR panel
      on Customer Show
- [x] 57 new tests asserting the critical invariant throughout: Trade Receivables balance ==
      total invoice outstanding, and revenue reconciled per invoice journal

**Deliberately not implemented:** credit notes, sales returns, allowances, customer advances and
deposits, VAT/SVAT, multi-currency, bank reconciliation, payroll, production overhead
accounting, year-end closing.

**Still open:** credit notes are a separate commercial feature — reversal is an audit correction
and must not be used in their place (`ACCOUNTING_RULES.md` §19).

## Phase 13 — Finance 5: cash, bank and expenses ✅ COMPLETE

Where the money physically is, what leaves it immediately, and moving it between accounts.

- [x] `cash_bank_accounts`, `expense_vouchers`, `expense_voucher_lines`, `fund_transfers` —
      four forward-only migrations, no balance column on any of them
- [x] One rule for "may money move through this account" —
      `AccountingPolicy::assertCashBankAccount()`: active, ASSET, not a control account, not a
      header, **and configured**. Supplier payments and customer receipts now require it too, so
      an ordinary asset account is no longer accepted merely for being an asset
- [x] Expense vouchers — `Dr each expense / Cr cash or bank` in one journal, raising **no
      payable**; supplier optional and reporting-only; cost of sales deliberately not chargeable
- [x] Fund transfers — `Dr destination / Cr source`, one figure used twice; no P&L, no AP, no
      AR, no inventory, combined cash+bank unchanged
- [x] `CashBankBookService` — money in, money out and a running balance derived entirely from
      posted journal lines, with no module special-cased
- [x] Screens: cash/bank accounts with derived balances, cash/bank book, expense vouchers,
      fund transfers
- [x] 78 new tests asserting the fourth invariant throughout: book closing balance == that
      account's general-ledger balance, across every kind of document that moves money

**Deliberately not implemented:** bank reconciliation, cheque lifecycle, VAT/SVAT, payroll,
foreign currency, credit notes, customer and supplier advances, loans, fixed assets, year-end
closing, production overhead allocation.

**Still open:** the negative-cash and overdraft policy (`ACCOUNTING_RULES.md` §23) — three
business questions, deliberately unanswered rather than guessed at.

## Phase 14 — Finance 6: financial statements and management reports ✅ COMPLETE

Reports over what the ledgers already hold. **No migration, no stored balance.**

- [x] `ProfitLossService` — revenue, cost of sales, gross profit, operating expenses, net
      profit, by date range or fiscal period; classified by `AccountType`, never code prefix
- [x] `BalanceSheetService` — assets, liabilities, equity and a **computed** Current Earnings
      line; the equation `Assets = Liabilities + Equity + Current Earnings` checked on every
      request. Retained Earnings shows its real balance and is never written to
- [x] `AccountTreeService` — chart hierarchy with parent subtotals, each parent counted once
- [x] `AccountingBalanceService` gains a `from` date on `totalsByAccount()` and a
      presentation-only sign method; ledger arithmetic unchanged
- [x] `InventoryValuationReportService` — current valuation from the inventory ledger, with
      secondary quantity and mapped GL account; no as-of date (not faked)
- [x] `InventoryGlReconciliationService` — inventory ledger vs GL per inventory account, to six
      decimals; differences shown with cutover context, never hidden or backfilled
- [x] `ProductProfitabilityService` — **operational** profitability from posted deliveries'
      stored sales value and COGS; kept separate from the financial P&L by name and source
- [x] `FinanceDashboardService` — every figure fetched from the service that owns it; period
      always explicit
- [x] Screens: finance dashboard, profit & loss, balance sheet, inventory valuation, inventory
      vs GL, cash/bank position, product profitability — all GET-only, with drill-downs to the
      general ledger, aging, cash/bank book and deliveries
- [x] 56 new tests, including an integrated scenario (capital → purchase → supplier invoice →
      payment → delivery → sales invoice → receipt → expense) asserting the P&L, balance sheet
      equation, AR, AP, cash, inventory and trial balance together

**Deliberately not implemented:** cash flow statement, year-end closing, PDF/Excel export,
historical inventory valuation, VAT, payroll, depreciation, bank reconciliation, loans,
multi-currency, credit notes, advances, production overhead, permanent joint-product costing.

**Still open:** financial-year definition and year-end closing (Current Earnings currently
accumulates from inception); inventory opening balances at cutover. See
`ACCOUNTING_RULES.md` §28.

## Phase 15 — Roles, permissions and access control ✅ COMPLETE

Who may do what — enforced by the server on every route.

- [x] `roles`, `permissions`, `permission_role`, `role_user`, and `users.is_active` — forward
      migrations; plus a constraint-only migration making every audit foreign key to `users`
      RESTRICT, so a user with history can never be deleted
- [x] `App\Enums\Permission` — 73 permissions, `module.document_action`, with CREATE / APPROVE /
      POST / REVERSE / CANCEL separate wherever a module has them
- [x] Every permission a Gate ability; every signed-in route declares exactly one (dashboard and
      own settings excepted); 403 renders an in-app access-denied page
- [x] Seven starting roles (ADMIN, MANAGER, STOREKEEPER, PURCHASING, SALES, PRODUCTION,
      ACCOUNTANT) — editable data, applied only when a role is first created
- [x] Multiple roles per user; effective permissions are the union of active roles
- [x] Inactive users refused at sign-in and signed out mid-session; their names stay on records
- [x] Last-administrator protection (`AdministratorGuard`); ADMIN always holds everything
- [x] Self-deletion kept but refused for users with history or the last administrator
- [x] One-time bootstrap made the existing user ADMIN; new users never become ADMIN;
      `authorization:grant-admin` for fresh installs
- [x] Administration screens: Users (roles + active status) and Roles & Permissions (grouped
      permission matrix); sidebar sections and action buttons hide by permission
- [x] 47 new tests, including a sweep calling every protected route with and without its
      permission

**Decided with the owner:** public registration stays open (new accounts get no access); the
"delete my account" option stays but is refused when unsafe.

**Deliberately not implemented:** warehouse-scoped access, approval limits, field-level
security, invitations, API tokens. See `AUTHORIZATION_RULES.md` §11.

## Phase 16 — Versioned mobile REST API ✅ COMPLETE

The backend connection for the future Flutter app. The app itself is not built.

- [x] Laravel Sanctum installed; one forward migration (`personal_access_tokens`) — tokens hashed,
      one per device, `mobile-api` ability only, 90-day expiry (owner's choice), deleted on
      deactivation or password change; API login honours two-factor authentication
- [x] `/api/v1` only — 130 endpoints: session & meta 6, master data 21, inventory 3, purchasing
      13, sales 13, production 21, AP 13, AR 13, cash & bank 15, finance reports 12
- [x] **One domain layer:** draft logic extracted from the web controllers into shared `Save*`
      actions (purchasing 2, sales 2, production 3, AP/AR 4, cash 2, warehouses 1) and report
      helpers moved into their services; web and API both call them, with the web behaviour proven
      unchanged by the existing suite
- [x] Same RBAC: every API route declares the same single permission as its web twin
- [x] JSON errors — 401/403/404/422, **409 for business-state conflicts**, generic 500
- [x] Decimals as strings, ISO 8601 UTC timestamps, API Resources, pagination capped at 100
- [x] Rate limits, closed-by-default CORS, HTTPS required in production, all in `config/api.php`
- [x] Permission-filtered mobile dashboard; meta endpoint
- [x] `ApiRouteAuthorizationTest` audits every API route; 119 new tests in total

**Decided with the owner:** 90-day tokens; finance write endpoints included now.

**Not implemented:** idempotency keys, attachments, offline sync, user/role administration API,
API registration, OpenAPI file, push notifications. See `API_V1.md` §14.

---

## Phase 17 — Simple mode for factory staff + Sri Lanka time ✅ COMPLETE

- [x] **Business timezone** (`Asia/Colombo`) through `BusinessClock`: today, document numbers,
      overdue, aging, form defaults and on-screen times follow Sri Lanka; storage stays UTC and
      no existing row changed
- [x] **Simple mode at `/kiosk`**: big tiles, one question per screen, number keypad, batch cards,
      green result screen; phones, tablets and PCs; idle sign-out for shared devices
- [x] Six jobs: receive, dispatch, dehusking, husk chipping, copra, check stock
- [x] **Done saves and posts in one transaction** (`RecordKioskEntry`) through the existing
      actions; shown only to users with both create and post permission
- [x] **Sinhala + English**, per-device; server error messages translated on kiosk routes
- [x] "Start in simple mode" per user (one forward migration); "Simple mode" sidebar link
- [x] 19 new tests (kiosk flows, permissions, rollback, date, language, start page, clock)

**Decided with the owner:** simple mode inside the ERP (not a separate app); staff type prices;
Done always posts; Sinhala + English; phones, tablets and PCs.

**Not implemented:** scanning, printing, photos, offline, kiosk waste entry, Sinhala in the full
ERP. See `KIOSK.md` §5.

---

## Phase 18 — Sinhala + English ✅ COMPLETE (Step 1: operations · Step 2: Finance)

- [x] Per-person language (`users.locale`, one forward migration), one-tap switch in the top bar
      and on sign-in screens; simple mode stays per device
- [x] One translation file, `lang/si.json`, shared by the server and the screens (no new package);
      untranslated text falls back to English
- [x] All screens except Finance translated: master data, products, inventory, purchasing, sales,
      production, administration, settings, sign-in, dashboard and the app shell (~1,000 entries)
- [x] Status/type names, server messages, form errors, sign-in and page-number messages in Sinhala
- [x] **Saved records always English** — 49 stored texts moved to `RecordText`
- [x] 11 new tests, including completeness and "records stay English" guards

**Decided with the owner:** two steps; accounting terms Sinhala + English in brackets; language
remembered per person.

- [x] **Step 2:** every Finance screen — chart of accounts, journals, ledger, trial balance,
      periods, setup, AP, AR, cash and bank, reports and the finance dashboard — plus finance server
      messages and form fields (~640 more entries, ~1,650 in total)
- [x] The completeness test now covers every screen, and the suite runs at a fixed moment
      (`Tests\TestCase::FIXED_NOW`) so date-dependent tests no longer drift with the calendar
- [x] Terminology published as `docs/SINHALA_GLOSSARY.md` for the factory's review

---

## Phase 19 — Husk production, costing, units, payments and lot-free operations ✅ COMPLETE

- [x] **Dehusking labour:** recipe version 2 gives dehusked coconut 100% of the coconut value and
      raw husk the run's labour (quantity × operator-confirmed rate per coconut, pre-filled from
      `default_labour_rate`, Rs. 4.00 — the owner's current rate). Output = input + labour exactly;
      journal `Dr 1320/1330 · Cr 1310 · Cr 6310 Production Labour Absorbed` (contra-expense — no
      cash, no payable)
- [x] **Husk chipping backflushed at delivery:** recipe version 2 (`input_consumption =
      AT_DELIVERY`) takes no raw husk; flexible output rows (7C / 9C / 10C / Cocopeat, KG or L)
      enter stock at zero value with no journal ("raw husk material cost pending backflush")
- [x] **Backflush:** a delivery line of a chipping output states "Raw Husks Used"; posting issues
      them from the pooled husk in a separate `BACKFLUSH_ISSUE` transaction at the weighted average,
      adds the cost to the line's COGS (`Dr 5130/5170 · Cr 1330`), all in one database transaction;
      insufficient husk refuses the whole delivery; reversal returns the husks at original cost
- [x] **Recipe versioning:** several versions per type, exactly one active; version 1 (85/15,
      30/30/40) deactivated and kept for history; each run records the version it used; a draft
      made under the old chipping method is refused at posting
- [x] **Pooled (lot-free) stock** via `products.tracks_lots`: coconut, dehusked coconut, raw husk,
      chips and cocopeat have no lots on GRN, production, delivery, simple mode or the API; copra
      and shell keep lots; the engine refuses new stock into a pooled product's lot
- [x] **One-time lot consolidation:** `php artisan inventory:consolidate-pooled-lots --user=email`
      (`--dry-run`, `--force`) writes one value-neutral `LOT_CONSOLIDATION` transaction; old lots
      keep their history
- [x] **Unit `L` and litre stock items** `HUSK-7C-L`, `HUSK-9C-L`, `HUSK-10C-L`, `COCOPEAT-L`;
      **Cocopeat** (`COCOPEAT`, KG) with its own accounts 1370 / 5170 / 4170; no KG↔L conversion
- [x] **Payment methods** on supplier payments and customer receipts — cash, cheque (number, date,
      bank) or bank transfer; cash only through a cash account, cheque/transfer only through a bank
      account (request and posting); "Record payment" from invoice screens, pre-filled
- [x] Screens, simple mode (labour step, "what was made" step, "Raw husks used" on dispatch), the
      mobile API resources and Sinhala translations updated; no new permissions
- [x] **5 forward migrations:** `add_lot_tracking_to_products_table`,
      `version_production_recipes_and_record_labour`, `add_raw_material_backflush_to_deliveries`,
      `add_payment_method_to_supplier_payments_and_customer_receipts`,
      `add_production_labour_account_to_accounting_settings`
- [x] Tests: the production, sales, finance, API and kiosk suites rewritten for the new rules;
      `FactoryWorkflowIntegrationTest` re-derived (`END_TO_END_VALIDATION.md` §3-§8); new
      `HuskProductionWorkflowTest`, `DeliveryBackflushTest`, `PooledStockTest`,
      `PaymentMethodTest`. Full suite: 1,089 tests passing

**Decided with the owner:** litre items for 7C, 9C, 10C and Cocopeat; Cocopeat on its own accounts;
cheque details only (no status lifecycle); default dehusking labour rate Rs. 4.00 (changes from
time to time). On 2026-10-04 the owner had all development test transactions deleted (backup
taken first): the dev database now holds master data only, accounting is switched off again, and
the QA test product is deactivated — so no old lots or old-method chips remain.

**Open:** cheque lifecycle and clearing account; whether unsold
chips should show an estimated husk value; whether copra and shell should also be pooled.

---

## Cross-cutting standing rules

| Rule | Source |
| --- | --- |
| Stock derived from ledger, never a stock column | `INVENTORY_RULES.md` §1 |
| All inventory written via `PostInventoryTransaction` | `INVENTORY_RULES.md` §13 |
| Posted inventory is immutable; correct by reversal | `INVENTORY_RULES.md` §10, §12 |
| Decimal-safe arithmetic via `DecimalMath` (BCMath) | `INVENTORY_RULES.md` §8 |
| Inactive products: issue out yes, receive new stock no | `INVENTORY_RULES.md` §14 |
| Pooled products carry no lots; lots follow `tracks_lots`, never a code | `INVENTORY_RULES.md` §15 |
| KG and L are separate stock items; no unit conversion exists | `INVENTORY_RULES.md` §16 |
| Chipping husk is backflushed at delivery, atomically with the sale | `BUSINESS_RULES.md` §19 |
| Landed cost allocated by material value, never quantity | `BUSINESS_RULES.md` §13 |
| Calculated costs are never accepted from a request | `BUSINESS_RULES.md` §13 |
| Debits = Credits on every journal | `ACCOUNTING_RULES.md` §2 |
| Every route enforces a permission server-side; roles are data | `AUTHORIZATION_RULES.md` §1-§3 |
| Reports derive from ledgers; no stored statement balances | `ACCOUNTING_RULES.md` §24 |
| `decimal` for money/quantities, never float | `CLAUDE.md` |
| Transactions for inventory/production/accounting | `CLAUDE.md` |
| Posted transactions reversed, never edited | `CLAUDE.md` |
| Audit history never deleted | `CLAUDE.md` |
| Business logic in action classes, reusable by API | `CLAUDE.md` |
| Forward migrations; no `migrate:fresh` without explanation | `CLAUDE.md` |

## Consolidated open questions

Resolved decisions are recorded in `BUSINESS_REQUIREMENTS.md` §6; remaining questions in
§7. **No open question blocks current work.**

The remaining questions cluster around costing and finance: the standard dehusking labour rate,
the permanent joint-product method for copra / shell, other production expenses (electricity,
fuel, machine, overhead), an estimated value for unsold chips, the cheque lifecycle, tax and
multi-currency — plus whether copra and shell should become pooled stock.
