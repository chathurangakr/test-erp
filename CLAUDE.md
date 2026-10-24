# Coconut Factory ERP

This repository is an **ERP system for a coconut processing factory**. It manages the
purchase, processing, inventory and sale of coconut products — whole coconuts, dehusked
coconuts, husk, husk chips (7C / 9C / 10C), copra and coconut shell.

The rules in this file are **mandatory**. They exist because this is an accounting and
inventory system: mistakes here corrupt real financial and stock records, and corrupted
records are often impossible to reconstruct.

---

## Technology stack

| Layer | Technology |
| --- | --- |
| Framework | Laravel 13 |
| Language | PHP 8.5 |
| Frontend | Vue 3 + Inertia.js + TypeScript |
| Styling | Tailwind CSS |
| Database | MariaDB |
| Web server | Nginx |
| Mobile (future) | Flutter app consuming a REST API |

Because a Flutter mobile application will later consume this system, business logic must
live where **both** Inertia controllers and REST API controllers can reuse it — i.e. in
action/service classes, never inside a controller or a Vue component.

---

## General development rules

1. **Follow Laravel conventions.** Use `php artisan make:*` generators. Match the structure
   and naming of existing sibling files.
2. **Keep business logic out of controllers** where practical. Controllers validate input,
   call an action, and return a response.
3. **Use service/action classes for important business operations.** Anything that moves
   stock, posts a journal, or changes a business document belongs in an action class under
   `app/Actions/`.
4. **Use database transactions** for inventory, production and accounting postings. A
   partially-written stock movement or journal is a corrupt record.
5. **Use foreign keys and database constraints.** The database is the last line of defence;
   do not rely on application validation alone.
6. **Use `decimal` columns for money and measurable quantities. Never use float or double
   for accounting values.** Floating-point arithmetic loses precision and will silently
   produce unbalanced journals.
7. **Never hard-code business values that should be configurable** — rates, thresholds,
   allocation methods, product codes and UOM codes belong in the database or config.
8. **Every significant business feature must include automated tests.**
9. **Never silently modify posted business transactions.**
10. **Posted transactions must be reversed or cancelled according to business rules**,
    never edited in place.
11. **Never delete audit history.**
12. **Do not invent business rules.** If a requirement is unclear, implement what is
    confirmed, mark the gap `TBD` in `docs/`, and raise it with the user.

---

## Critical inventory rules

**Stock must be derived from inventory transactions.**

**Never directly maintain inventory by simply changing a product stock field.** There must
be no `current_stock` (or equivalent) column on `products`. On-hand quantity is always
calculated from the inventory ledger.

Every stock movement must record at minimum:

- product
- warehouse — **required; multiple warehouses must be supported**
- quantity
- UOM
- movement direction / type
- transaction / source type
- source record ID
- date / time
- user
- cost where relevant
- lot / batch — **nullable, but the architecture must support traceability**

**Negative inventory is NOT allowed by default.** A transaction that would take available
stock below zero must **fail atomically** — the whole business event rolls back, leaving no
partial movements. A future configuration setting may relax this; the default does not.

**Units of measure: exactly three.** `NOS` for countable physical items, `KG` for weight,
`L` for volume. There is no separate "Qty" unit — "Qty" is UI wording for a `NOS` count.
**No conversion exists between any two units, and none may ever be added.** A product
measured both by weight and by volume is **two stock items** (`HUSK-7C` in KG, `HUSK-7C-L`
in L), because the ledger holds a product's quantity in its own primary unit — so a KG
balance and an L balance can never mix or net off. See `docs/INVENTORY_RULES.md` §16.

**Pooled stock has no lots.** `products.tracks_lots = false` makes a product ONE pooled
stock: its movements carry no lot and no screen asks for one. Coconut, dehusked coconut, raw
husk, the chip grades and cocopeat are pooled; copra and shell keep lots. Old lots keep their
history; `php artisan inventory:consolidate-pooled-lots` moves their balances into the pool
once, value-neutrally. See `docs/INVENTORY_RULES.md` §15.

---

## Inventory engine rules (Phase 3 — implemented)

The inventory engine exists and is tested. These rules are permanent:

- **All inventory is written through `App\Actions\Inventory\PostInventoryTransaction`.**
  Never insert `inventory_transaction_lines` directly, and never put inventory arithmetic in
  a controller. Purchasing, sales and production must all post through this action so the
  locking, negative-stock and costing guarantees apply uniformly.
- **Posted transactions and lines are immutable.** The models throw on update and delete.
  The only permitted post-write is the `reversed_by_id` audit pointer. Correct mistakes with
  `ReverseInventoryTransaction`, never by editing.
- **Use `App\Support\DecimalMath` (BCMath) for every quantity and cost calculation.**
  PHP floats must never appear in the costing path. Quantities are `decimal(20,3)`, costs
  `decimal(20,6)`.
- **Quantities are always stored positive.** `direction` (`IN`/`OUT`) alone decides the sign.
- **Weighted average cost is company-wide per product, not per warehouse.** This is what
  makes transfers value-neutral. Lock the *product* row when posting.
- **Outgoing stock is never priced by the caller** — it is valued at the weighted average in
  force at posting time. The one exception is an internal system cost used by transfers and
  reversals.
- **Stock is always derived from the ledger.** No stock column on any table, and no cache.
  If a cache is ever added it must be documented as non-authoritative and rebuildable.
- **Inactive products may be issued OUT but must never receive NEW stock.** Purchases,
  production outputs, opening balances and adjustments in are rejected for an inactive
  product; transfers and reversals are exempt, because they relocate or undo stock that
  already exists. Use `InventoryTransactionType::bringsNewStock()` — never a blanket check
  on direction. See `docs/INVENTORY_RULES.md` §14.
- **A reversal reuses the original line's unit cost AND its authoritative total**, not the
  current weighted average, so it cancels the original exactly in both quantity and value.
- **`total_cost` is the authoritative monetary value of a ledger line; `unit_cost` is only
  its rounded per-unit representation.** Never assert or rely on
  `total_cost == quantity × unit_cost` — a six-decimal rate cannot always reproduce a total
  derived the other way round. Trusted domain services pass the exact figure through
  `PendingInventoryLine::withAuthoritativeCost()`; `fromArray()` has no total-cost key, so
  no request can set a stock movement's value. See `docs/INVENTORY_RULES.md` §8.1.
- **An issue that takes the last of a product takes its entire remaining value**, so a
  sold-out product holds zero quantity *and* zero value. The rule lives once, in
  `StockQueryService::issueValue()`. See `docs/INVENTORY_RULES.md` §8.2.
- **A transfer's two legs share one authoritative total**, so it is exactly value-neutral.
  Never re-derive the IN value from a rounded unit cost.
- **Secondary quantity is informational.** It is recorded where known, is not guarded
  against going negative, and has **no fixed conversion** to the primary unit. The primary
  quantity is authoritative for negative-stock prevention.
- **Damaged stock is written off, never deleted or edited** (`RecordStockDamage`, Inventory →
  Damaged stock): `ADJUSTMENT_OUT` at the engine's weighted average, `Dr stock damage and losses
  (accounting_settings.stock_loss_account_id) / Cr inventory`, recorded in one step and corrected
  by `ReverseStockDamage`. See `docs/INVENTORY_RULES.md` §17.
- **Returnable bags lent to customers stay our stock** (`RecordCustomerBagMovement`, Sales →
  Customer bags): given / returned are value-neutral transfers to and from the one
  `holds_customer_items` warehouse, a loss is a write-off. What a customer holds is derived
  (`CustomerBagBalances`). That warehouse is never offered on documents — pickers use
  `Warehouse::operational()` — and the engine refuses ordinary documents there. See §18.
- **No new stock may land in a lot of a pooled product.** The engine refuses it for every
  type except `REVERSAL` (back where it came from) and `LOT_CONSOLIDATION`. Decide lots with
  `Product::tracksLots()`, never a product code.

---

## Purchasing rules (Phase 4 — implemented)

- **A purchase order never creates inventory.** Stock appears only when a goods receipt is
  posted. `received_quantity` on a purchase order line is a progress counter, not stock.
- **Only a DRAFT document may be edited.** An approved purchase order and a posted goods
  receipt are controlled documents; correct them by cancellation or reversal, never by
  editing. The `GoodsReceipt` model throws on any post-posting change beyond its reversal
  audit fields.
- **Post goods receipts through `App\Actions\Purchasing\PostGoodsReceipt`**, which in turn
  posts through the inventory engine. Never write ledger rows from a purchasing controller.
- **The landed unit cost is what reaches inventory** — never the bare purchase price.
- **Allocate header landed costs by line material value, never by quantity**, because lines
  may use different units of measure. The last line absorbs the rounding remainder so
  allocations sum exactly. See `docs/BUSINESS_RULES.md` §13.
- **Never accept a calculated cost from a request.** `material_cost`,
  `allocated_landed_cost` and `final_unit_cost` are computed server-side and shown
  read-only.
- **Purchasable products are not hard-coded.** Any active product may be purchased.
- **Purchasing creates no accounting entries** — no payables, no journals. Finance is a
  later phase, and a test asserts those tables do not exist.
- **`REVERSED` and `CANCELLED` are different states.** A cancelled goods receipt never
  reached inventory (a draft, abandoned); a reversed one did and was undone by an opposite
  ledger transaction. Only a draft may be cancelled; a posted receipt must be reversed.
- **Landed-cost allocation must sum exactly to the header total.** The last line absorbs the
  rounding residual. A separate, bounded residue exists at the inventory layer because a
  *unit* cost stored to six decimals cannot always reproduce its line total — that is
  inherent to posting unit costs, not an allocation defect.
- **Document numbers encode no business meaning:** `PO-YYYYMMDD-000001`,
  `GRN-YYYYMMDD-000001`, generated via `App\Support\DailyDocumentSequence`.

---

## Sales rules (Phase 5 — implemented)

- **A sales order never creates or reserves inventory.** Stock leaves only when a delivery
  is posted. `delivered_quantity` on a sales order line is a progress counter, not stock.
- **Only a DRAFT document may be edited.** An approved sales order and a posted delivery are
  controlled documents; correct them by cancellation or reversal. The `Delivery` model
  throws on any post-posting change beyond its reversal audit fields.
- **Post deliveries through `App\Actions\Sales\PostDelivery`**, which posts a
  `SALES_ISSUE` through the inventory engine. Never write ledger rows from a sales
  controller.
- **Selling price and inventory cost are entirely separate.** The seller never states what
  stock cost: the engine values the issue at the weighted average and the delivery reads
  that cost back. A high selling price must never influence the weighted average.
- **Cost of goods sold always uses the PRIMARY quantity**, even on a line priced per KG,
  because the primary quantity is what leaves stock. Revenue follows `pricing_basis`; cost
  follows the stock movement. See `docs/DATA_MODEL.md` S-1.
- **`pricing_basis` is governed by the product's `secondary_uom_id`, never by a product
  code.** `SECONDARY` is permitted only where a secondary unit exists; everything else is
  `PRIMARY`. The priced quantity is derived from the basis, never typed separately.
- **A lot must be explicitly chosen wherever lotted stock of a LOT-TRACKED product exists.**
  Never silently assume one. A pooled product has no lots, and a lot sent for one is refused.
  See `docs/DATA_MODEL.md` S-2.
- **A chipping output's delivery backflushes its raw husk.** The line states "Raw Husks Used"
  (positive, in the raw material's unit); posting issues them from the pool at the weighted
  average in a separate `BACKFLUSH_ISSUE` transaction **inside the same database transaction**
  and adds that cost to the line's `cost_of_goods_sold`. Not enough husk → the whole delivery
  fails. Which products need it comes from the recipe (`ProductionRecipe::backflushingFor()`),
  never from a code. Reversal returns the husks at their original cost. See
  `docs/BUSINESS_RULES.md` §19.
- **Gross profit and margin are computed, never stored**, so they cannot drift. Zero sales
  value yields a zero margin, never a division error.
- **A delivery journals cost of sales only, never revenue.** Revenue and the receivable
  belong to the sales invoice — see the Accounting rules section. A sales order still
  journals nothing at all.
- **Document numbers:** `SO-YYYYMMDD-000001`, `DN-YYYYMMDD-000001`.

---

## Production rules (Phases 6, 7, 8 & 19 — dehusking, husk chipping and copra implemented)

- **All three production types are postable** — dehusking, husk chipping and copra.
- **There is ONE production engine.** Every type reuses the production order, the two ledger
  transactions, `ReverseProduction`, `CancelProduction` and `ProductionCostAllocator`. Never
  add a parallel engine for a new production type.
- **Product codes live only in `ProductionRecipeSeeder`.** Everything resolves products
  through `production_recipes` / `production_recipe_outputs`. Never reference `COCONUT`,
  `DEHUSKED-COCONUT`, the size items (`COCONUT-LARGE` …), `RAW-HUSK`, `HUSK-7C`, `HUSK-9C`,
  `HUSK-10C`, `COCOPEAT`, the `-L` items, `COPRA` or `RAW-SHELL` in a controller, service or action.
- **A type has one recipe per INPUT PRODUCT** — dehusking and copra have one per coconut size
  (`docs/BUSINESS_RULES.md` §33). `ProductionRecipe::forType($type, $inputProductId)` finds it;
  with no input named it returns the original input's recipe, so a request naming no
  `input_product_id` behaves as before. Husk, copra and shell stay one stock each.
- **Recipes are versioned.** Exactly one version per type and input product is active;
  a superseded version is deactivated, never deleted or edited, and each posted run records the
  version it used (`production_recipe_id`). Change a rule by seeding a new version.
- **Whether an output is derived or measured is a per-OUTPUT fact, not a per-type one.**
  `ProductionRecipeOutput::derivesQuantity()` (is there an `output_ratio`?) is the
  authority; `ProductionType::hasFixedOutputRatio()` is only a coarse summary, and copra
  mixes both kinds inside one recipe.
- **The 1:1:1 dehusking ratio is configuration, not code.** It is the recipe's
  `output_ratio`. Dehusking output quantities are derived and never accepted from a request.
- **Production conserves value: outputs = consumed value + labour, exactly.** Item count
  rises — one coconut becomes two things — but money only grows by the run's stated labour.
  Allocated shares sum **exactly** to the consumed value (the final output absorbs the
  rounding remainder), labour is added whole to the output the recipe marks
  `absorbs_labour`, and those exact figures reach the ledger unchanged.
- **The allocation percentages must total exactly 100** for a recipe that consumes at
  production, enforced by `ProductionRecipe::assertAllocationIsComplete()`. Dehusking v2 is
  100 / 0 (dehusked / husk) and copra 100 / 0 (copra / shell — the shell comes free); never hard-code them.
- **Dehusking labour is the raw husk's cost.** The operator confirms a labour rate per
  coconut (pre-filled from `default_labour_rate` — Rs. 4.00 today; the owner changes it from time to time, and each run keeps the rate it used);
  posting requires it (0 is allowed) and stores `labour_rate` / `labour_cost`. The journal
  credits the production labour account (`accounting_settings.production_labour_account_id`,
  6310 — a contra-expense). Never create cash or a payable for it.
- **An output that produced nothing takes no value and gets no lot.** Only positive-quantity
  outputs participate in allocation, and their percentages are renormalised to 100%. Store
  the share **actually applied**, not the configured one.
- **Consumed cost is read back from the ledger**, never recomputed in production code.
- **Posting writes TWO inventory transactions** — `PRODUCTION_CONSUMPTION` and
  `PRODUCTION_OUTPUT` — inside one database transaction. See `docs/DATA_MODEL.md` P-3.
- **Lots follow `tracks_lots` (`ProductionLots`).** A lot-tracked input needs its lot, and each
  lot-tracked output gets a new lot recording the run; a pooled input refuses a lot and a
  pooled output gets none — the production order is then the trace.
- **Reverse outputs before the consumption**, so a reversal blocked by already-consumed
  output fails before any input is restored.
- **No electricity, machine, fuel, drying or overhead cost** is added; only dehusking labour.

### Copra specifically

- **The shell COUNT is derived; every WEIGHT is measured, never derived.** One dehusked coconut
  leaves one shell — the recipe's `output_ratio` of `1.000000`. Copra KG is entered by the
  operator; shell KG is entered when measured, and **no fixed NOS↔KG conversion may ever be
  added** for either.
- **A submitted shell count is refused, not ignored.** `CopraRequest` marks a derived
  output's quantity prohibited, and `PostCopraProduction` recalculates it from
  `input × ratio` regardless. Two independent guards, on purpose.
- **Shell KG is OPTIONAL since copra recipe version 2** (2026-10-18: the factory has no scale for
  shell). Whether a weight is required is the recipe's `requires_secondary_quantity` flag, never
  a check for `RAW-SHELL`; an optional weight is recorded when entered and must then be > 0. Runs
  on version 1 keep the weights they were made with. Existing databases got version 2 from the
  migration `make_copra_shell_weight_optional`.
- **Shell is valued against its PRIMARY quantity (`NOS`).** A cost per KG may be *displayed*
  and must be labelled informational; it is never stored and never used as a weighted
  average. See `docs/DATA_MODEL.md` P-10.
- **The copra takes 100% of the value and the shell 0%** (version 3, 2026-10-24: the factory buys
  coconuts, not shell, so the shell comes free; versions 1-2 split 75 / 25). Configurable, in
  `production_recipe_outputs.allocation_percent` — never in the posting action. Existing
  databases got version 3 from the migration `give_copra_the_whole_coconut_value`.
- **Moisture and drying loss are not modelled.** No waste product, no mass-balance equation
  between the NOS input and the KG outputs, no loss quantity. See `docs/BUSINESS_RULES.md`
  §20 TBD-3.

### Husk chipping specifically

- **Chipping takes NO raw husk.** The recipe's `input_consumption` is `AT_DELIVERY`: posting a
  run writes one `PRODUCTION_OUTPUT` at **zero cost** ("raw husk material cost pending
  backflush"), no consumption, no journal. The husk is backflushed by the delivery (Sales
  rules). A draft still carrying an old-method input line is refused, not reinterpreted.
- **There is NO conversion from husk (NOS) to chips (KG or L), and one must never be
  added.** Every chipping output's `output_ratio` is `NULL`. Do not seed a placeholder ratio.
- **Outputs are flexible rows**: any recipe output (7C / 9C / 10C / Cocopeat, by KG or by L —
  separate stock items), each product once, every row **positive**. A grade not made is left
  out; zero rows are refused. `lot_id` and `quantity` are refused in the request.
- **Waste is information only.** `waste_quantity` + `waste_uom_id` on the order; no
  inventory line, no lot, **no `DUST` product**, and no cost. Both fields are optional
  because the measurement basis is unconfirmed — do not invent a unit to fill them.

---

## Accounting rules (Finance Phases 1-6 — foundation, inventory, AP, AR, cash and reporting implemented)

- Use **double-entry accounting**. Every posted journal satisfies **Total Debit = Total
  Credit**, exactly, enforced by `App\Actions\Accounting\PostJournalEntry` inside one
  database transaction — before the status changes.
- **Never directly modify account balances.** There is no balance column on `accounts` and
  there must never be one. Balances are derived from posted journal lines by
  `App\Services\Accounting\AccountingBalanceService`, exactly as stock is derived from the
  inventory ledger. The general ledger's running balance is calculated per request and never
  persisted.
- **A journal line is a debit OR a credit** — never both, never neither, never negative, and
  both columns hold positive amounts.
- **Posted journals are immutable**, lines included. Correct them with a reversing entry,
  which mirrors every line with debit and credit swapped **at the exact original amounts**.
  A reversal cannot itself be reversed, and nothing is ever deleted.
- **Journals post only into an OPEN fiscal period containing their date.** A period holding
  a DRAFT cannot be closed. A reversal posts into the open period containing its own date,
  never back into a closed one.
- **Control accounts and group headers refuse manual posting.** Control accounts are the
  system's to post to — an entry naming a `source_type` may use them; a manual one may not.
- **Accounts are deactivated, never deleted**, and an account with journal history refuses
  deletion outright. No destroy route exists anywhere in Finance.
- **Base currency is configuration**, in `config/accounting.php` — never the literal "LKR"
  in business logic. Multi-currency is TBD.
- **Money is rupees and cents** (`accounting.money_decimals` = 2). Typed money uses the
  `App\Rules\Money` rule (fractions of a cent refused, trailing zeros fine); amounts owed —
  delivery `sales_value`, goods receipt `material_cost`, order line totals — round half up with
  `DecimalMath::money()`; screens show money with `money()` from `@/lib/money`. **Inventory
  costs are never rounded to cents** — they keep the six-decimal scale. See
  `docs/ACCOUNTING_RULES.md` §31.
- **When Finance later posts inventory values it must use the authoritative `total_cost`**,
  never `quantity × rounded unit cost`. The accounting scale is `decimal(20,6)` to match the
  ledger exactly. See `docs/ACCOUNTING_RULES.md` §8.
- **Operational documents generate journals, governed by a cutover date.** While
  `accounting_settings.operational_accounting_start_date` is NULL nothing changes; once set,
  documents on or after it must journal, and the date is then **frozen**. It is never
  invented by a seeder — an accountant sets it. See `docs/ACCOUNTING_RULES.md` §13.
- **The journal is written inside the operational action's own transaction.** An unmapped
  product, a closed period or a missing period fails the whole posting: never stock without
  its journal, never a journal without its posting.
- **Event → journal:** GRN `Dr inventory / Cr GRNI` · delivery `Dr cost of sales / Cr
  inventory`, plus any backflushed raw material `Dr the delivered product's cost of sales / Cr
  raw material inventory` · production `Dr output inventory / Cr input inventory`, plus labour
  `Cr production labour absorbed` · **husk chipping, warehouse transfer and lot consolidation
  post nothing** (no value moves). Zero-value lines are never written; a document that moved no
  value writes no journal (a zero-cost delivery stays billable).
- **Payments record how money moved: `CASH`, `CHEQUE` or `BANK_TRANSFER`** (`PaymentMethod`).
  Cash goes through a CASH-kind cash/bank account; a cheque or transfer through a BANK-kind one
  (`AccountingPolicy::assertMethodFitsAccount`, request and posting). A cheque records number,
  date and bank — **no status, no clearing account** (cheque lifecycle is still not started).
  **"Credit" is not a method**: it is an invoice left outstanding. See
  `docs/ACCOUNTING_RULES.md` §29-§30.
- **A delivery recognises NO revenue.** It is a physical issue; revenue and the debt belong
  to the sales invoice. Neither accrual accumulates unchecked any more: supplier invoices
  clear GRNI, sales invoices bill deliveries, and what remains of each is reported and
  reconciled.
- **Account codes live only in `ChartOfAccountsSeeder` and `ProductAccountMappingSeeder`.**
  Everything resolves accounts through `product_account_mappings`. Never write an account
  number into a posting action.
- **An unmapped product cannot move stock once accounting applies.** It is refused, not
  guessed at — the QA test product is deliberately left unmapped.
- **Accounts payable is implemented.** Supplier invoice `Dr GRNI or expense / Cr Trade
  Payables`; supplier payment `Dr Trade Payables / Cr Cash or Bank`. Trade Payables and GRNI
  both come from `accounting_settings` — never write either account code into AP logic.
- **Posting a supplier invoice changes NO inventory.** Not the quantity, the ledger, the
  weighted average or a production cost. The goods receipt established the valuation; the
  invoice records who is owed.
- **One goods receipt may be cleared by several suppliers.** Goods, transport and loading are
  commonly three different companies, so GRNI matching is per-amount and partial, capped at
  the receipt's remaining accrual. The invoice supplier need not be the receipt supplier.
- **A receipt's original accrual is read from its own posted journal**, never re-derived from
  quantity × unit cost. Pre-cutover and reversed receipts raised no accrual and cannot be
  matched.
- **Two mutual blocks:** an invoice cannot be reversed while a posted payment is allocated to
  it, and a goods receipt cannot be reversed once a posted invoice has matched against it.
- **No purchase-price variance is posted.** An uncleared remainder stays visible on the GRNI
  reconciliation rather than being written off somewhere nobody asked for.
- **AP balances are derived, never stored.** A supplier has no payable column. An invoice's
  outstanding is its total less the allocations of payments that are still posted, and its
  PARTIALLY_PAID / PAID status follows from that rather than being typed.
- **Two invariants must always hold:** GRNI balance == uninvoiced accrual, and Trade Payables
  balance == total invoice outstanding. See `docs/ACCOUNTING_RULES.md` §16-§18.
- **No VAT, no foreign currency.** Amounts are tax-unmodelled and LKR only.
- **Accounts receivable is implemented.** Sales invoice `Dr Trade Receivables / Cr Sales
  Revenue`; customer receipt `Dr Cash or Bank / Cr Trade Receivables`. Trade Receivables
  comes from `accounting_settings` — never write 1210 into AR logic.
- **Every invoice line bills a DELIVERY LINE.** Version 1 invoices delivered goods and
  nothing else — no service lines, no ad-hoc charges. If it was not delivered, it cannot be
  invoiced.
- **The revenue account is resolved from the product's mapping, never chosen.** No screen
  offers a revenue selector and no request field carries one. An unmapped product cannot be
  invoiced, exactly as it cannot move stock.
- **The billed amount is the delivery line's `sales_value`** — never recalculated from
  quantity × price. Re-deriving it for a dual-unit product priced per KG would need a
  NOS↔KG conversion, and **no such conversion exists or may ever be added**. See
  `docs/DATA_MODEL.md` S-4.
- **A pre-cutover or reversed delivery cannot be invoiced.** It raised no cost of sales in
  the ledger, so billing it would show revenue with no matching cost. Do not backfill
  historic deliveries.
- **Partial billing works both ways** — one delivery line across several invoices, one
  invoice across several deliveries of the same customer — capped at the remaining amount
  under a row lock.
- **Two more mutual blocks:** an invoice cannot be reversed while a posted receipt is
  allocated to it, and a delivery cannot be reversed once a posted invoice has billed it.
- **Reversal is an audit correction, NOT a credit note.** Reversing says the invoice should
  never have existed. Returned goods are a **sales return** (below); post-hoc discounts and
  quality allowances are still **not built** — never use a reversal in their place. See
  `docs/ACCOUNTING_RULES.md` §19.
- **Sales returns are implemented** (`RecordSalesReturn`, Sales → Returns, §32): against an
  INVOICED delivery line, never more than delivered nor credited beyond what was billed. The
  credit is the PRICE CHARGED; good goods re-enter stock (`SALES_RETURN`) at the line's
  `inventory_unit_cost` into its lot, damaged ones are a stock loss. Settled by reducing an
  invoice of the customer (`CREDIT`; `EXCHANGE` = the invoice for replacement goods) or money back
  (`REFUND`, capped at what was paid on the original invoice). Journal: `Dr revenue / Cr trade
  receivables or cash`, `Dr inventory + stock loss / Cr cost of sales`. An invoice's outstanding
  is total − receipts − returns credited (`AccountsReceivableService::settledAmount`); an invoice
  linked to a standing return cannot be reversed. Backflushed lines (chips) cannot be returned.
- **Receipts are always allocated to invoices** — no customer advances, deposits or
  on-account credits — and the allocations must total the receipt exactly.
- **AR balances are derived, never stored.** A customer has no receivable column.
  `credit_limit` and `credit_days` are agreed terms; the credit days only suggest a due
  date, which stays editable.
- **The third invariant:** Trade Receivables balance == total sales invoice outstanding.
  Revenue reconciles **per invoice journal**, not globally — a manual journal may legitimately
  credit revenue. See `docs/ACCOUNTING_RULES.md` §20-§21.
- **Cash and bank management is implemented.** Which ledger accounts hold money is
  **configuration**, in `cash_bank_accounts` — not a guess from the chart's shape.
- **`cash_bank_accounts` has no balance column and must never grow one.** What is in the till
  or the bank is derived from posted journal lines, exactly as stock is derived from the
  inventory ledger.
- **One rule decides whether money may move through an account:**
  `AccountingPolicy::assertCashBankAccount()` — active, ASSET, not a control account,
  hand-postable (which excludes headers), **and configured and active**. That last condition is
  the strict one: `1400 Other Current Assets` passes every other test and is still not somewhere
  cash may be paid into or out of.
- **Supplier payments and customer receipts now require a CONFIGURED account too.** Being an
  asset account is no longer sufficient. Never relax this back to a blanket asset check.
- **An expense voucher and a supplier invoice are different workflows and must never be
  combined.** A voucher is an expense already paid — `Dr expense / Cr cash or bank`, raising
  **no payable**. A supplier invoice is an expense owed — `Dr expense / Cr Trade Payables` —
  settled later by a payment. A voucher for a credit purchase loses the debt; an invoice for an
  immediate payment invents one.
- **A voucher's supplier is reporting only.** Naming one creates no Trade Payables and changes
  nothing about the journal.
- **A voucher may charge ordinary EXPENSE accounts only.** `COST_OF_SALES` is deliberately
  excluded — it is derived from stock movements, and charging it by hand would put it out of
  step with the inventory ledger. Inventory, revenue, equity and control accounts are excluded
  by the type check.
- **A fund transfer is a balance-sheet event and nothing else.** `Dr destination / Cr source`,
  the same figure used twice. No expense, no revenue, no payable, no receivable, no inventory,
  and combined cash + bank is unchanged. The same account on both sides is refused.
- **Neither document touches inventory** — no quantity, no ledger row, no weighted average, no
  lot.
- **The cash/bank book derives entirely from posted journal lines** and special-cases no
  module. Payments, receipts, vouchers, transfers and manual journals appear because each wrote
  a line. Money in is a debit, money out a credit, and the running balance is never persisted.
- **The fourth invariant:** cash/bank book closing balance == that account's general-ledger
  balance. It holds by construction — both read the same rows. See
  `docs/ACCOUNTING_RULES.md` §22.
- **Negative cash and overdrafts are `TBD`.** Nothing currently prevents an account going
  below zero; **no policy was invented**, and a test records the current behaviour so that
  adding one later is a visible change. See `docs/ACCOUNTING_RULES.md` §23.
- **`Rule::exists()->where($column, false)` matches nothing** — the value is stringified, and
  `(string) false` is `''`. Use `0` and `1` in exists/unique rules, never booleans.
- **Do not start bank reconciliation, cheque lifecycle, discount / allowance credit notes, customer
  or supplier advances, VAT/SVAT, depreciation or production overhead accounting**
  without instruction.
- Financial records must remain **auditable** — every posting traceable to its source
  document and user.

---

## Financial reporting rules (Finance Phase 6 — implemented)

- **Reports derive from their source ledgers and store nothing.** Statements read posted
  journal lines through `AccountingBalanceService`; inventory valuation reads
  `StockQueryService`; product profitability reads posted delivery lines. Never add a profit,
  asset, liability, cash, AR or AP column, and never a summary table for convenience. Phase 6
  added no migration.
- **Use the central posted-line rule.** POSTED and REVERSED entries both count, so a reversal
  nets against its original. Never exclude reversed journals to make them "disappear".
- **P&L:** `Revenue − Cost of Sales = Gross Profit`; `Gross Profit − Operating Expenses = Net
  Profit`. Classify by `AccountType` (`REVENUE`, `COST_OF_SALES`, `EXPENSE`) — **never by an
  account-code prefix**. Group by the chart's `parent_id` hierarchy through
  `AccountTreeService`, which counts each parent exactly once.
- **Sign presentation is a reporting concern only.** `presentationAmount()` flips
  credit-natured types so revenue prints positive; an abnormal balance prints negative. Its
  result must never feed back into ledger arithmetic.
- **Balance sheet:** `Assets = Liabilities + Equity + Current Earnings` (profit since the last
  close). It holds by construction; the report still computes the difference.
- **Current Earnings is computed, never posted.** Profit reaches Retained Earnings only through a
  year-end close an accountant performs (`CloseFinancialYear`, 1.20.0): one journal dated the
  year end (financial year ends on the last day of `accounting_settings.financial_year_end_month`,
  March), source `FinancialYearClosing`. **The P&L excludes closing journals; Current Earnings
  (profit since the last close) includes them** — never change one without the other, or profit
  is lost or counted twice. Years close in order and are undone only from the latest, dated the
  same year end. See `docs/ACCOUNTING_RULES.md` §24.
- **Operational product profitability is NOT the financial P&L.** It uses stored
  `DeliveryLine.sales_value` and `cost_of_goods_sold` from POSTED deliveries, never
  recomputed (so no NOS↔KG conversion), and must be labelled "Operational". The P&L shows
  recognised (invoiced) revenue. See `docs/ACCOUNTING_RULES.md` §25.
- **Inventory valuation is current only.** `StockQueryService` has no date filter; do not
  fake a historical as-of valuation.
- **Inventory-vs-GL differences are displayed, never hidden or auto-fixed.** Pre-cutover stock
  legitimately has no journal; it needs an opening-balance process later. Never backfill
  journals. Reconcile per inventory GL **account**, because products share accounts. See
  `docs/ACCOUNTING_RULES.md` §26.
- **The dashboard fetches every figure from the owning service** and takes an explicit fiscal
  period or date range — never an assumed "current month" in the service.
- **Report routes are GET-only.** A test asserts nothing in the reporting area writes.
- **Exports (1.19.0):** every report downloads as PDF or Excel (`ReportExports` → dompdf / `XlsxWriter`)
  through its own export route with the SAME permission as the report, built from the same
  services and filters as the screen — never a second calculation. See `docs/REPORT_EXPORTS.md`.
- **Not built:** cash flow statement, comparative periods,
  historical inventory valuation.

---

## Confirmed business rules

Full detail lives in `docs/BUSINESS_RULES.md`. Summary:

### Whole Coconut — primary UOM `NOS`

Can be purchased from suppliers, sold directly to customers, or sent to dehusking.

### Dehusking

`N Whole Coconut` → `N Dehusked Coconut` + `N Raw Coconut Husk`

Example — input 1,000 Whole Coconut produces 1,000 Dehusked Coconut and 1,000 Raw Coconut
Husk. Whole Coconut decreases by 1,000; Dehusked Coconut increases by 1,000; Raw Husk
increases by 1,000. This is a **fixed 1:1:1 relationship**. The dehusked coconut carries the
whole coconut value; the raw husk carries only the dehusking labour (quantity × rate).

✅ **Implemented** — see the Production rules section above.

### Dehusked Coconut — primary UOM `NOS`

Can be sold directly, or used for copra production.

### Raw Coconut Husk — primary UOM `NOS`

Enters inventory from two sources: internal dehusking, or direct supplier purchase — **one
pooled stock** at one company-wide average. Processed through husk chipping, and leaves
stock when the chips or cocopeat made from it are delivered (backflush).

### Husk Chipping — implemented

Input: Raw Husk in `NOS`, **taken at delivery, not at chipping**. Outputs: `7C`, `9C`, `10C`
and `Cocopeat`, each by `KG` or by `L` (separate stock items).

- **Waste / loss is recorded as production waste and creates NO inventory.** It may be
  promoted to a proper inventory product later if the factory begins selling dust or
  another by-product. **Do not create a `DUST` product.**
- **Actual output quantities are entered by the operator.**
- **Do not use a fixed conversion from husks to KG or litres.**
- Outputs carry no cost until delivered; the delivery's "Raw Husks Used" are backflushed at
  the husk's weighted average into cost of goods sold. See `docs/BUSINESS_RULES.md` §19.

### Cocopeat — primary UOM `KG` (and `COCOPEAT-L` in `L`)

A husk chipping output with its own accounts: 1370 inventory, 5170 cost of sales, 4170 sales.
The litre chip items share their grade's accounts (1340 / 5130 / 4130).

### Copra Production — implemented

Input: Dehusked Coconut in `NOS`. Outputs: Copra in `KG`, plus Raw Coconut Shell.

- Raw Shell holds **both quantity (`NOS`) and weight (`KG`)**.
- One coconut yields one shell quantity — the only fixed relationship in this process.
- **Do not use a fixed NOS-to-KG conversion** — shell and copra weights vary and are
  measured.
- The copra carries the whole value; the shell comes free (configurable). See `docs/BUSINESS_RULES.md` §20.

### Raw Coconut Shell

Sellable **by count (`NOS`) or by weight (`KG`)**. Inventory must preserve both actual
quantity and actual weight where available.

### Costing

- Intended inventory costing method: **Weighted Average Cost**.
- Joint-product cost allocation will later support a configurable method such as market
  value / NRV.
- **Do not implement detailed finance or cost allocation yet.**

---

## Authorization rules (Phase 15 — implemented)

Full detail lives in `docs/AUTHORIZATION_RULES.md`.

- **Authorization is enforced SERVER-SIDE, on every route.** Each signed-in route declares
  exactly one permission with `->can(Permission::X)` (or `middlewareFor(..., Permission::X->middleware())`
  on resources). Hiding a button in Vue is a courtesy, never the protection.
  `RouteAuthorizationTest` fails if a new route has no permission — add one, never an exemption.
  The only open routes are the dashboard and the user's own settings.
- **Permissions are defined in code only** — the enum `App\Enums\Permission`, mirrored into the
  `permissions` table by `RolesAndPermissionsSeeder`. Adding a capability means adding an enum
  case, using it on a route, and re-running the seeder. No screen creates permissions.
- **Never check a role name to authorize anything.** Roles are data: bundles of permissions,
  edited on the Roles screen. Ask `$user->hasPermission()` / the Gate — never
  `if ($user->role === ...)`. `Role::ADMINISTRATOR` exists only for the lockout safety rules.
- **CREATE, APPROVE, POST, REVERSE and CANCEL are separate permissions** wherever a module has
  them. Follow `module.document_action` naming for new ones.
- **A user may hold several roles**; effective permissions are the union of their ACTIVE roles.
  An inactive role grants nothing; an inactive user holds nothing and cannot sign in.
- **Permissions never override document state.** Both must pass. Even ADMIN cannot edit a posted
  document. Do not put permission checks inside inventory, production or accounting arithmetic.
- **Domain actions assume their caller is authorized.** Every entry point — web routes, API
  routes, and jobs later — checks the permission before calling an action.
- **Never delete a user who has business history** — deactivate them. The database refuses it
  (audit foreign keys are RESTRICT) and `DeleteOwnAccount` refuses it first, using `UserHistory`.
- **The system can never lose its last active administrator.** Wrap any change to roles, activity
  or accounts in `AdministratorGuard::protect()`. The ADMIN role always holds every permission
  and cannot be deactivated or deleted.
- **New users never become administrators automatically.** Registration grants no role; it can
  be closed (`SELF_REGISTRATION=no`), and administrators add accounts on Users → Add user
  (`CreateUser`). The seeder's one-time bootstrap (no assignments exist → existing users become ADMIN) never
  fires again; a fresh install uses `php artisan authorization:grant-admin {email}`.
- **Business-feature tests use `User::factory()->admin()`**; access tests use `withPermissions()`
  / `withRoles()`. A plain factory user holds no role.
- **Not built:** warehouse-scoped access, monetary approval limits, field-level security,
  invitations.

---

## Mobile API rules (Phase 16 — implemented)

Full detail lives in `docs/API_V1.md`.

- **The mobile app never touches the database.** It calls `/api/v1` over HTTPS; Laravel calls the
  same domain actions as the website. Never give a client database access.
- **Every endpoint is versioned** under `/api/v1` (`routes/api.php` → `routes/api/v1/*.php`). A
  breaking change becomes `/api/v2` beside v1, never an edit in place.
- **One domain layer, two front doors.** Draft create/edit lives in shared `Save*` actions
  (`app/Actions/**/Save*.php`) called by both web and API controllers; posting, approving,
  reversing and cancelling reuse the existing actions. **API controllers only validate (with the
  web FormRequests), call an action and return a Resource** — never copy costing, allocation,
  journal or AP/AR logic into them. New document types need a `Save*` action from the start.
- **Same RBAC.** Every API route declares exactly one `->can(Permission::X)`, the same permission
  as its web twin; API route names are `api.v1.` + the web route name, with the same parameter
  names. `ApiRouteAuthorizationTest` enforces all of it. No mobile-only roles or permissions.
- **Bearer tokens (Sanctum) authenticate devices; roles authorize actions.** Tokens are hashed,
  carry only the `mobile-api` ability, expire after `config('api.token_lifetime_days')` (90), and
  are deleted on deactivation or password change. The API honours two-factor authentication. A
  browser session never authenticates the API.
- **Inactive users are refused** (`api.active` middleware), even with an old token.
- **Decimals are transmitted as strings** (quantities 3 dp, money 6 dp) through `ApiResource`
  helpers; dates `YYYY-MM-DD`, timestamps ISO 8601 UTC. Never return a raw model or a float.
- **Business-state conflicts are 409** — every action reports them under the `status` error key.
  Keep using that key for "wrong state" errors so the API mapping stays true.
- **Tunables live in `config/api.php`** (token life, rate limits, page sizes, HTTPS). Lists
  paginate with a hard maximum of 100.
- **Offline entries are recorded once (`docs/API_V1.md` §17).** `POST …/record` on goods receipts,
  deliveries and the three production types saves AND posts through `RecordKioskEntry`, keeps the
  real entry date, and needs the create and post permissions. Each call carries an
  `Idempotency-Key` UUID made on the phone; `RecordApiSubmission` writes the `api_submissions` row
  in the SAME transaction as the document (unique key), replays a repeat before validation, and
  refuses a key reused for another entry. A refused entry stores no key. Never record an offline
  entry any other way, and never update or delete a submission.
- **The offline app reads simple mode's data from `GET /api/v1/kiosk/options`** (`docs/API_V1.md`
  §18): the same `KioskOptions` / `KioskTasks` the kiosk pages use, sections filtered by permission.
  Recipe facts reach the phone only through it — never let the app hard-code a product code.
- **API answers follow the language** (`SetApiLocale`): `Accept-Language` from the app, else
  `users.locale`, else the default. `locale` is returned by login and `/auth/me`.
- **Payroll is in the API** (`routes/api/v1/payroll.php`, `docs/API_V1.md` §19). Paying an
  advance or a month's salaries needs an `Idempotency-Key` (`recordOnce`), because each moves
  money in one step.
- **No public API registration, no user/role administration API, no attachments, and no
  idempotency keys on the ordinary draft create endpoints yet.**

---

## Simple mode and time rules (Phase 17 — implemented)

Full detail lives in `docs/KIOSK.md` and `docs/DATA_MODEL.md` T-1.

- **Stored moments are UTC; the business calendar is Asia/Colombo.** Never change
  `config('app.timezone')`. Use `App\Support\BusinessClock` for "today", document-number dates,
  overdue checks, form defaults (`businessToday` shared prop / `@/lib/businessDate`) and for
  displaying times (`BusinessClock::display()`). Never write `BusinessClock::now()` into a
  timestamp column, and never use `new Date().toISOString()` for a default date.
- **Simple mode (`/kiosk`) is a second front door, not a second system.** Done composes the
  existing `Save*` and `Post*` actions in ONE transaction (`RecordKioskEntry`) — a failed post
  leaves nothing behind. Kiosk FormRequests extend the web ones and only force today's date.
- **A kiosk job requires both the create and the post permission** (`KioskTasks`); its routes
  declare the post permission and the store actions also check create.
- **What a worker types comes from the recipe** (`derived`, `requires_secondary_quantity`),
  never from a product code; derived quantities are never sent.
- **Every kiosk word lives in `resources/js/kiosk/text.ts`** in English and Sinhala; a test fails
  if a key is missing in either. Server messages come from `lang/si.json` on kiosk routes.
  **Never translate text that is saved** (journal / ledger descriptions).

---

## Language rules (Sinhala + English — implemented)

Full detail lives in `docs/LOCALISATION.md`.

- **Screens are bilingual; saved records are English.** Write every stored text (journal /
  ledger descriptions, notes) with `App\Support\RecordText::of()` — never `__()`. Pass enums
  themselves (not `->label()`) into it. Never add a `RecordText` key to `lang/si.json`.
- **One translation file:** `lang/si.json`, keyed by the English text, used by both `__()` and the
  screens' `useT()` (`@/lib/i18n`). Wrap every new piece of visible English in `t('…')` with
  `:placeholders`; never concatenate keys. `Heading`, `Breadcrumbs` and `NavMain` translate their
  own props — pages pass English. `LocalisationTest` fails if a `t('…')` key has no Sinhala entry.
- **Enum `label()` values are display names** and are translated; never save one.
- **Language is per person** (`users.locale`, `SetLocale` middleware); simple mode stays per
  device. Terminology: plain Sinhala, accounting terms with the English in brackets.
- **Every screen is bilingual, Finance included.** Terminology: `docs/SINHALA_GLOSSARY.md`.
- **The test suite runs at a fixed moment** (`Tests\TestCase::FIXED_NOW`, inside September 2026,
  where its scenarios live). Tests needing another moment travel there themselves.

---

## Payroll rules (implemented — 1.5.0)

Full detail lives in `docs/PAYROLL.md`.

- **Daily-rate pay:** full day = the day's rate, half day = half (rounded half up to the cent),
  leave and unrecorded days = nothing. Only days worked are paid. No EPF/ETF.
- **Each attendance day keeps the rate it was recorded at.** Never re-price a recorded day.
- **No balance on employees.** Earned, advanced and owed are derived (`PayrollCalculator`).
- **Pay any period, any people** (1.13.0): a payroll covers `period_start` … `period_end` (a day, a
  few days, a month) for everyone with unpaid days or only the employees named. **A day is paid
  once** — `employee_attendances.payroll_run_id` marks it — and a paid DAY is frozen until its
  payroll is reversed; nothing else in the month is.
- **The payer chooses how much advance to take back** per employee, 0 … min(owed as of the pay
  date, earned); not named → the most (the old behaviour). Employee Advances (a control account) in
  the GL always equals what employees still owe.
- **Advances and payrolls are paid in one step and immutable** — correct by reversal. A period is
  paid on or after its last day; an advance already taken back cannot be reversed.
- **Accounts are configuration:** `wages_account_id` and `employee_advances_account_id` in
  `accounting_settings`, never codes in payroll logic. Payroll journals need an open period,
  not the operational cutover.

---

## Loans (implemented — 2026-10-24)

Full detail lives in `docs/LOANS.md`.

- **No balance on a loan.** Still owed = amount − principal of standing payments (`LoanBalances`);
  the invariant is Loans Payable (a control account) == total still owed.
- **Each payment states what was actually paid and its interest** (from the bank slip); the rest
  reduces the loan, never below zero. Interest-free loans (machinery) refuse interest.
- **A running loan comes in at what is still owed** — `Dr opening balance equity / Cr loans`; a new
  one `Dr cash/bank / Cr loans`. Accounts are `accounting_settings` configuration.
- **Money is corrected by reversal**; only the description and reminder schedule
  (`Loan::EDITABLE`) are edited.
- **`loans:remind`** daily 08:00 Sri Lanka time: 7, 2 and 0 days before the due day, unless that
  installment is paid, by email to users holding `loans.reminders` and to the Telegram chats on
  Administration → Telegram settings (`telegram_settings`, token encrypted with APP_KEY, never sent
  to a page or an error message); each reminder sent once (`loan_reminders`).

## Fixed assets (implemented — 2026-10-25)

Full detail lives in `docs/FIXED_ASSETS.md`.

- **No depreciation** (the owner's choice): an asset stays at cost; a sale's profit or loss is sale
  price − cost (`SellFixedAsset`; scrapping is a sale at 0). Fixed assets balance == cost of assets
  in use.
- **Bought on a loan → the loan is opened with it** (`loans.funding = ASSET`) for price − down
  payment, sharing the asset's journal; undo it by reversing the asset, never the loan.
- **Maintenance** is a log per asset charged to repairs and maintenance; a free job has no journal.
- Purchase figures are immutable (`FixedAsset::EDITABLE` only); everything is corrected by reversal.

## Telegram alerts and the monthly report (implemented — 1.16.0)

Full detail lives in `docs/TELEGRAM.md` and `docs/WEEKLY_UPDATE.md`.

- **Two bots, one row each** in `telegram_settings` (`purpose`: `reminders`, `transactions`), tokens
  encrypted with APP_KEY and never sent to a page. Use `TelegramSetting::current($purpose)`.
- **A transaction alert is read from a status change, never called from an action.**
  `TransactionAlertObserver` (registered for `TransactionAlertCatalog::MODELS`) writes a
  `telegram_alerts` row in the posting's OWN transaction on posted / reversed (and an asset's sold /
  sale undone). Add a new document type to the catalog and `TransactionAlertMessage`; never send
  to Telegram from inside a posting.
- **`telegram:send-alerts` (every minute) sends them**, each chat once (`delivered_to`); unreachable
  → wait, refused → retried, FAILED after `MAX_ATTEMPTS`. Messages are written at send time from
  the document; only what an event erases goes in `context`.
- **Commands (1.17.0): /stock, /cash, /today, /owed** to the transactions bot, through a webhook
  (`POST telegram/webhook`, outside the web group, trusted ONLY by the secret header set with
  setWebhook — `telegram_settings.webhook_secret`, encrypted). Answers go only to ACTIVE users who
  linked their Telegram (`users.telegram_user_id`, one-time code from Settings → Profile), only in
  private chats, and only what their permissions allow (`TelegramAnswers::COMMANDS`, checked on
  every message and button). Read-only: never post or change anything from Telegram.
- **`reports:monthly-update`** on the 1st at 08:00 Sri Lanka time: `MonthlyUpdateReport` (reads,
  never stores) by email to holders of `reports.monthly_email` and to every bot with
  `sends_monthly_report`.

## Weekly update email (implemented — 1.10.0)

Full detail lives in `docs/WEEKLY_UPDATE.md`.

- **Sunday 20:00 Sri Lanka time**, `reports:weekly-update`, to every active user holding
  `reports.weekly_email` (administrators always do). Recipients follow the permission — never a
  role name.
- **It reads, never recalculates:** figures come from the finance dashboard, cash book, aging and
  posted production orders through `WeeklyUpdateReport`; nothing is stored.
- **Mail credentials are data, never code:** Administration → Email settings (`mail_settings`,
  permission `mail.manage`) stores the Gmail app password **encrypted with APP_KEY** and never
  sends it back to a page; `deploy/set-mail.sh` / `.env` is the fallback while it is off.

---

## Master data rules

These apply to every master table (products, units of measure, suppliers, customers,
warehouses) and to every master added later:

- **Codes are unique and normalised to uppercase.** Use the `App\Concerns\HasUppercaseCode`
  trait on the model, never a controller, so seeders and the REST API get the same rule.
- **Supplier, customer and employee codes are assigned automatically** (`CreateSupplier`,
  `CreateCustomer`, `CreateEmployee` → `App\Support\SequentialCode`): prefix from config
  (`config/masters.php`, `config/payroll.php`) plus one more than the highest number in use,
  read under lock. The web form shows the next code read-only; the API numbers a record sent
  without a code. Supplier and customer codes stay correctable on edit; employee codes never change.
- **No destroy route and no hard delete in the UI.** Master records are deactivated with
  `is_active`, because historic transactions must keep referring to something meaningful.
- **No balances, totals or stock columns on master tables.** A supplier has no payable, a
  customer has no receivable, a product has no stock. Those are always derived from posted
  transactions. `credit_limit` is an agreed *term*, not a balance.
- **The company's own name, address and logo are data** (`company_profiles`, one row, edited on
  Administration → Company details, permission `company.manage`). The logo is a data URI in that
  row so backups carry it. Never write a customer's name or logo into the code or the public repo.
  Printouts use the shared `company` prop (`Letterhead`, `PrintButton`, the A4 print CSS in
  `app.css`); a page named `…/Print` gets the menu-less `PrintLayout`.
- **Exactly one active warehouse is the default.** Never write `is_default` directly; use
  `App\Actions\Warehouses\SetDefaultWarehouse`, which maintains the invariant inside a
  transaction. See `docs/DATA_MODEL.md` D-2.

---

## Current scope

Implemented: project documentation, **Product Master** (products + units of measure),
**Supplier Master**, **Customer Master**, **Warehouse Master**, the
**Inventory engine** (ledger, lots, weighted-average costing, transfers, reversals) with
read-only Stock on Hand / Stock Ledger / Lots screens, **Purchasing** (purchase orders,
goods receipts, landed cost, lot creation, posting and reversal), **Sales** (sales orders,
deliveries, cost of goods sold, gross profit, lot selection, dual-unit pricing, posting and
reversal), **Dehusking production** (recipe-driven 1:1:1 conversion, traceable output lots,
configurable value allocation, posting and reversal), and **Husk chipping production**
(operator-measured output weights with no husk-to-KG conversion, zero-output
redistribution, informational waste, traceable output lots, posting and reversal), and
**Copra production** (measured copra and shell weights, a 1:1 derived shell count that
cannot be overridden, dual-unit shell stock, a configurable value allocation (shell free since 2026-10-24), traceable
output lots, posting and reversal), and the **accounting foundation** (chart of accounts,
fiscal periods, manual journal entries with reversal, derived balances, general ledger and
trial balance), and **inventory accounting integration** (goods receipts, deliveries and all
three production types generating journals behind a configurable cutover date, with
product-to-account mappings and a GRNI accrual), and **accounts payable** (supplier invoices
clearing that accrual — partially, and from several suppliers per receipt — direct expense
invoices, supplier payments allocated to invoices, AP aging and GRNI reconciliation), and
**accounts receivable** (sales invoices billing delivered goods at the delivery's own
authoritative value, revenue resolved through product mappings, partial billing in both
directions, customer receipts allocated to invoices, AR aging and an unbilled-deliveries
report), and **cash and bank management** (configured cash/bank accounts with derived
balances, expense vouchers for immediately-paid expenses, cash and bank fund transfers, and a
cash/bank book that reconciles exactly to the general ledger), and **financial statements and
management reports** (profit and loss, balance sheet with computed Current Earnings, inventory
valuation, inventory-to-GL reconciliation, cash/bank position, operational product
profitability and a finance dashboard — all derived, none stored), and **role-based access
control** (105 permissions, seven editable starting roles, multiple roles per user, every route
enforced server-side, user deactivation and last-administrator protection), and a **versioned
mobile REST API** (`/api/v1`, Sanctum bearer tokens per device, the same RBAC and domain actions
as the website, decimals as strings, JSON errors with 409 for state conflicts, and offline entries
recorded exactly once per idempotency key), and **simple mode** (big-button Sinhala/English
screens at `/kiosk` for receiving, dispatch, the three production jobs and stock, where Done saves
and posts in one transaction) with **Sri Lanka business time** throughout, and **Sinhala / English** for every screen
(each person chooses; saved records stay English), and the **husk production rework** (dehusking
labour as the husk's cost, pooled lot-free stock for coconut / husk / chips / cocopeat with a
one-time lot consolidation command, husk chipping with flexible KG / litre outputs and raw husk
backflushed at delivery into cost of goods sold, Cocopeat with its own accounts, the `L` unit,
versioned recipes, and cash / cheque / bank-transfer payment methods with cheque details), and
**payroll** (employees with daily rates, a daily attendance register with full / half day and
unpaid leave, salary advances, and salaries for any period and any people — each day paid
once, the advance taken back chosen each time — posted to the ledger), and **damaged stock write-offs** (bags, coconuts or any product, valued by the ledger and
charged to a stock-loss expense), and **returnable bags lent to customers** (given, returned and
lost per customer, with what each holds derived), and **coconut sizes** (large / small / immature
whole and dehusked stock, one dehusking and copra recipe per size), and **sales returns**
(credited at the price charged, good goods back in stock, damaged ones a loss, settled by
credit, exchange or money back), and **loans** (running and new loans, installments with interest
from the bank slip, what is still owed derived, and email reminders before each due day), and
**fixed assets** (bought for cash, on a loan or already owned, sold or scrapped with the profit or
loss against cost, and a maintenance log), and **Telegram transaction alerts** (a second bot told of
every posting and reversal) with a **monthly report** by email and Telegram on the 1st, **report
exports** to PDF and Excel, and **year-end closing** into retained earnings (financial year to 31 March).

**Not yet started — do not begin without instruction:** bank reconciliation, cheque lifecycle
(cheque status, clearing account, bounced-cheque handling),
discount and allowance credit notes, customer and supplier advances, production expense
accounting, VAT/SVAT, EPF/ETF and payslips, depreciation, cash flow statement, the permanent joint-product costing method, production planning,
BOM editor, maintenance planning, warehouse-scoped access, approval limits, field-level security, the
Flutter app itself (its offline queue included — the server side is built), API attachments.

The inventory engine still has no direct posting UI by design — business documents drive it.

Access is governed by roles and permissions (see Authorization rules). A signed-in user with
no role sees only the dashboard and their own settings.

---

## Working agreement

- Read `docs/` before implementing a business feature.
- `tests/Feature/Integration/FactoryWorkflowIntegrationTest.php` walks one realistic flow
  across every module and asserts the figures in `docs/END_TO_END_VALIDATION.md`. If a
  change to purchasing, sales, inventory or production breaks it, the modules have stopped
  agreeing with each other — fix the cause, never the expected figures.
- Run `vendor/bin/pint --dirty` after changing PHP files.
- Run `php artisan test` and `npm run build` before reporting work complete.
- Use forward migrations. **Do not use `migrate:fresh`** without explaining why first.
- The development database is `coconut_erp_dev`. Never drop unrelated tables.
- **Installing on another Ubuntu computer is one script:** `deploy/provision.sh`, driven by
  `deploy/provision.env` (copied from `provision.env.example`; git-ignored because it can hold
  the sudo and database passwords). It installs PHP-FPM, Nginx (HTTPS), MariaDB, Composer and
  Node, writes `.env`, migrates, seeds master data on a fresh database only, creates the first
  administrator (`php artisan authorization:create-admin`, password from
  `COCONUT_ADMIN_PASSWORD`), and sets up the firewall, scheduler and nightly backups. It is
  idempotent and never deletes data. Keep it working when adding a PHP extension, an
  environment variable or a scheduled task.
- **Restoring a backup / moving data between computers is `deploy/restore.sh <backup.sql.gz>`:** it
  backs up first, loads the dump and the `env-…` file's APP_KEY, migrates, and puts the old data
  back on any failure; a dump from a newer version is refused. With a real domain as
  `SERVER_NAME` and port 80, the installer gets a Let's Encrypt certificate (`LETSENCRYPT=yes`).
- **Updating an installed computer is `deploy/update.sh`:** it backs up the database, pulls the
  project folder and runs the installer taken from the newest version (never the folder's copy).
  An update refreshes permissions and runs `php artisan masters:add-new`, which adds only the
  accounts, products, mappings, recipes and unset settings a newer version introduced (each
  seeder's `addMissing()`), never changing an existing row. New master data therefore goes into
  the seeders, never into a manual step.
- **Cloud servers are supported** (`docs/CLOUD_SERVER.md`). With `SERVER_NAME` blank the installer
  uses the public IP when the cloud metadata service (169.254.169.254) answers, else the LAN IP.
  On Oracle's Ubuntu images (iptables-persistent with an `icmp-host-prohibited` REJECT) it opens
  the web ports there and does NOT install ufw, which would remove iptables-persistent.
  `coconut-erp.sh` asks no password when `sudo -k -n true` works (passwordless sudo).
- **Every release has a version number.** `VERSION` (e.g. `1.0.0`) is the single source; it is
  shown on screen (`config('app.version')` → the shared `appVersion` prop). When pushing a
  release, raise it (last number for fixes, middle for features), add a plain-language entry to
  `CHANGELOG.md`, and tag the commit `vX.Y.Z`.
- **`deploy/coconut-erp.sh` is the one file a customer runs, for install and update alike.** It asks
  only for the sudo password (saved as `SUDO_PASSWORD` in `provision.env`), clones into
  `~/coconut-erp`, then runs `provision.sh` the first time and `update.sh` afterwards.

---
<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
