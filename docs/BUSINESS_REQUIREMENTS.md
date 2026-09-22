# Business Requirements — Coconut Factory ERP

> **Status:** Living document. Only requirements explicitly confirmed by the business owner
> are recorded here. Anything not confirmed is marked `TBD` and must not be invented.

## 1. Purpose

An ERP system for a coconut processing factory, covering the purchase, processing,
inventory and sale of coconut products.

## 2. Business overview

The factory buys whole coconuts (and may also buy raw husk directly), processes them
through several stages, and sells both intermediate and finished products.

```
                    ┌──────────────────┐
  Supplier ───────▶ │  Whole Coconut   │ ───────▶ Customer (direct sale)
                    └────────┬─────────┘
                             │ Dehusking (1:1:1)
                   ┌─────────┴──────────┐
                   ▼                    ▼
        ┌────────────────────┐  ┌──────────────────┐
        │ Dehusked Coconut   │  │ Raw Coconut Husk │ ◀─── Supplier (direct purchase)
        └─────┬──────────┬───┘  └────────┬─────────┘
              │          │               │ Husk chipping
              │          ▼               ▼
              │     Customer      ┌──────────────────┐
              │   (direct sale)   │  7C / 9C / 10C   │ (KG)
              │                   │  + waste (loss)  │
              │ Copra production  └──────────────────┘
              ▼
    ┌───────────────────┐
    │ Copra (KG)        │
    │ Raw Shell (NOS+KG)│ ───────▶ Customer (by count or KG)
    └───────────────────┘
```

## 3. Products in scope

| # | Product | Code | Primary UOM | Secondary UOM |
| --- | --- | --- | --- | --- |
| 1 | Whole Coconut | `COCONUT` | `NOS` | — |
| 2 | Dehusked Coconut | `DEHUSKED-COCONUT` | `NOS` | — |
| 3 | Raw Coconut Husk | `RAW-HUSK` | `NOS` | — |
| 4 | Copra | `COPRA` | `KG` | — |
| 5 | 7C | `HUSK-7C` | `KG` | — |
| 6 | 9C | `HUSK-9C` | `KG` | — |
| 7 | 10C | `HUSK-10C` | `KG` | — |
| 8 | Raw Coconut Shell | `RAW-SHELL` | `NOS` | `KG` |

The product list must be **extensible** — new products and UOMs are added through data,
never through code changes.

**Units of measure: exactly two.** `NOS` for countable physical items, `KG` for weight.
There is no separate "Qty" unit — that is UI wording for a `NOS` count.
See `DATA_MODEL.md` decision D-1.

## 4. Functional scope

### 4.1 Implemented

- **Product Master** — list, search, create, edit, view, activate/deactivate.
- **Unit of Measure master** — seeded; no UI yet.
- **Supplier Master** — one supplier table for every material bought. Full CRUD UI.
- **Customer Master** — one customer table for every product sold. Full CRUD UI.
- **Warehouse Master** — multi-warehouse, one seeded default (`MAIN`). Full CRUD UI.
- **Inventory engine** — ledger, lots, weighted-average costing, transfers, reversals, and
  three read-only screens (Stock on Hand, Stock Ledger, Lots).
- **Purchasing** — purchase orders and goods receipts, with landed-cost calculation, lot
  creation, posting to the inventory ledger, and reversal. Supports both receipts against a
  purchase order and direct receipts with no order.
- **Sales** — sales orders and deliveries, issuing `SALES_ISSUE` movements, recording cost
  of goods sold and gross profit, with lot selection, dual-unit pricing, and reversal.
  Supports both deliveries against a sales order and direct deliveries.
- **Dehusking production** — converts whole coconut into dehusked coconut and raw husk at
  the confirmed 1:1:1 ratio, creating traceable output lots and sharing the consumed value
  by configurable percentages, with reversal.
- **Husk chipping production** — converts raw husk (NOS) into 7C, 9C and 10C chips (KG)
  using the **weights the operator actually measured**; there is no husk-to-KG conversion
  in the system. Waste is recorded for information only and creates no stock. Value is
  shared by configurable percentages, redistributed when a grade produces nothing, with
  traceable output lots and reversal.
- **Copra production** — dries dehusked coconut (NOS) into copra (KG) and raw coconut shell.
  The copra weight and the shell weight are both **measured by the operator**; only the
  shell **count** is derived, one shell per coconut processed. Shell stock keeps both its
  count (`NOS`) and its measured weight (`KG`), with no conversion between them. Value is
  shared by configurable percentages (provisionally 75 / 25), with traceable output lots and
  reversal.
- **Accounting foundation** — a chart of accounts, fiscal periods, manual double-entry
  journals with reversal, derived account balances, a general ledger and a trial balance.
  Every posted journal balances exactly; balances are never stored. **No operational
  document generates a journal yet.**

No master record can be deleted through the UI; all use active/inactive status. Posted
inventory transactions cannot be edited or deleted at all.

### 4.2 Planned, not started

Accounts Payable, Accounts Receivable, customer
payments, purchase and sales invoices, automatic journals from operational documents,
VAT/SVAT, payroll, bank reconciliation, P&L and balance sheet, year-end closing, production expenses (labour / electricity / machine / fuel / overhead), the
permanent joint-product costing method, role-based permissions, REST API for the Flutter
mobile application.

**Production creates no accounting entries and adds no expenses.** Total input inventory
value equals total output inventory value exactly. A test asserts no ledger, costing or
overhead tables exist.

**Sales creates no accounting entries.** A delivery moves stock and records operational
profitability; nothing is receivable, posted to a ledger account, or tracked as a customer
balance. A test asserts no accounts, journals, receivable or payment tables exist.

**Purchasing creates no accounting entries.** A goods receipt moves stock and records its
landed cost; nothing is owed, posted to a ledger account, or tracked as a payable. A test
asserts no accounts, journals or payable tables exist.

Nothing seeds stock or purchases — real inventory is zero until a goods receipt is posted.

## 5. Non-functional requirements

| Requirement | Detail |
| --- | --- |
| Auditability | Every stock movement and journal traceable to source document and user |
| Precision | `decimal` columns for money and quantities; never float |
| Responsiveness | UI must work on desktop, tablet and mobile browser widths |
| Mobile API | A REST API will later serve a Flutter app; business logic must be reusable |
| Data safety | Business records are never hard-deleted; use active/inactive or reversal |

## 6. Resolved decisions

| # | Question | Decision |
| --- | --- | --- |
| 1 | Husk-chipping **waste/by-product** treatment | **Recorded as production waste; creates NO inventory.** May be promoted to a stocked product later if the factory sells dust. No `DUST` product now. |
| 2 | **Warehouse** structure | **Multiple warehouses must be supported.** V1 uses one default `MAIN` / "Main Warehouse", created in a later task. Every inventory transaction requires a warehouse. |
| 3 | **Lot/batch** tracking | **Must be supported** by the architecture. Movements may reference a lot/batch (nullable). Not implemented yet. |
| 4 | **Negative inventory** | **Not allowed by default.** A transaction taking stock below zero must fail atomically. |
| 5 | `QTY` vs `NOS` for shell | **No separate `QTY` unit.** Shell uses Primary `NOS`, Secondary `KG`. |
| 6 | Stock scope | Tracked per **product + warehouse**, and per **product + warehouse + lot** where lots apply. |
| 7 | Costing scope | **Weighted average at company/product level**, not per warehouse — so transfers are value-neutral. |
| 8 | Transfer behaviour | OUT + IN at the **same unit cost**, atomic; same-warehouse and inactive-warehouse transfers rejected. |
| 9 | Posted inventory | **Immutable.** Corrections are reversals, never edits. |
| 10 | Inactive products | May be **issued out** to clear existing stock, but may **never receive new stock** from purchases, production outputs, opening balances or adjustments in. |
| 11 | Purchasable products | **Not hard-coded.** Any active product may be purchased; coconut and husk are simply the first. |
| 12 | Landed cost | Transport, loading and other costs are **allocated by material value**, and the landed unit cost is what reaches inventory. |
| 13 | Goods receipts | Supported **both** against a purchase order and **directly** without one. |
| 14 | Selling price vs cost | Entirely separate. Selling price never affects the weighted average cost. |
| 15 | Cost of goods sold | `primary quantity × the weighted average the ledger used`. Never typed by a seller. |
| 16 | Dual-unit pricing | `pricing_basis` chooses which recorded quantity revenue follows; allowed only where the product has a secondary unit. |
| 17 | Lot selection on issue | A lot must be explicitly chosen wherever lotted stock exists. |
| 18 | Dehusking ratio | **Fixed 1:1:1**, held as recipe configuration, not code. Outputs are derived and never typed. |
| 19 | Production value | **Conserved.** Item count rises; total inventory value does not. Allocated output costs sum exactly to the consumed value. |
| 20 | Dehusking cost split | **Configurable fixed percentages**, currently 85% dehusked / 15% husk — provisional until the costing policy is set. Must total 100%. |
| 21 | Production expenses | **None yet.** No labour, electricity, machine, fuel or overhead cost is added in this phase. |
| 22 | Husk chipping yields | **Measured, never derived.** The operator enters the actual 7C / 9C / 10C weights. The recipe stores **no** husk-to-KG ratio, so none can be applied. |
| 23 | Husk chipping cost split | **Configurable fixed percentages**, currently 30% 7C / 30% 9C / 40% 10C — provisional. Must total 100%. |
| 24 | A grade that produced nothing | Takes **no cost and no lot**; its share is redistributed across the grades that were produced. |
| 25 | Chipping waste | **Optional and informational.** No inventory line, no lot, no product, no cost. Its unit comes from the UOM master; no unit is invented. |
| 26 | Copra input | **Dehusked Coconut in `NOS`**, from an explicitly chosen lot and warehouse. |
| 27 | Copra output | **Measured, never derived.** The operator enters the actual copra KG. There is **no** coconut-to-KG ratio in the recipe, so none can be applied. |
| 28 | Raw shell count | **Derived 1:1** — one shell per coconut processed. Held as recipe configuration, never typed; a submitted figure is **refused**, and the server recalculates it regardless. |
| 29 | Raw shell weight | **Measured and required.** No NOS↔KG conversion exists. Recorded as the inventory secondary quantity. |
| 30 | Raw shell valuation | Costed against its **primary `NOS` quantity**. A per-KG figure may be displayed, clearly labelled **informational**; it is never stored and never used as a weighted average. |
| 31 | Copra cost split | **Configurable fixed percentages**, currently 75% copra / 25% shell — provisional. Must total 100%. |
| 32 | Drying / moisture loss | **Not modelled in version 1.** No waste product, no mass-balance between NOS input and KG output, no loss quantity captured. |
| 33 | Accounting method | **True double-entry.** Every posted journal satisfies Total Debit = Total Credit exactly, at `decimal(20,6)`. |
| 34 | Account balances | **Derived from posted journal lines, never stored.** No balance column exists on any account. |
| 35 | Posted journals | **Immutable.** Corrections are reversing entries preserving the exact original amounts; nothing is deleted. |
| 36 | Fiscal periods | Journals post only into an **OPEN** period containing their date. A period with drafts cannot be closed. Reopening is not offered. |
| 37 | Control accounts | Receivables, payables and inventory accounts **refuse manual posting** — they belong to the system actions that will later own them. |
| 38 | Base currency | **LKR**, held as configuration rather than a literal. Multi-currency is TBD. |
| 39 | Operational accounting | **Not yet.** No GRN, delivery or production run creates a journal; the account mapping is still to be confirmed. |

## 7. Remaining open questions (`TBD`)

| # | Question | Blocks |
| --- | --- | --- |
| 1 | Which **joint-product cost allocation** method (market value / NRV / other)? | Finance |
| 2 | What is the **chart of accounts**? | Finance |
| 3 | Are there **multiple currencies**, or LKR only? | Finance |
| 4 | Is **tax** (VAT/SVAT) in scope? | Finance |
| 5 | What **roles and permissions** are required? | Permissions |
| 6 | Is **partial/failed production output** possible (input consumed, no output)? | Production |
| 7 | What is the **measurement basis for chipping waste** — weight, husk count or estimate? And should waste ever carry an allocated cost? | Husk chipping (recorded but not interpreted) |
| 11 | Should **deactivating the default warehouse** be refused, or should the default move automatically? Currently refused — see `DATA_MODEL.md` D-3. | Warehouse Master |
| 12 | Do suppliers or customers need a **price list or product restriction**? Currently any party may trade any product. | Purchasing / Sales |
| 7 | Are **inter-warehouse transfers** required? | Inventory ledger |
| 8 | Is stock valued **per warehouse or globally**? | Inventory / Costing |
| 9 | **Lot numbering scheme**; are lots mandatory for particular products? | Inventory ledger |
| 10 | Should production **waste carry an allocated cost**? | Costing |
| 13 | Should specific landed-cost types ever be allocated on a basis other than material value (e.g. freight by weight)? See `DATA_MODEL.md` P-1. | Purchasing |
| 14 | How should a **free-goods receipt** carrying transport cost be handled? Posting is currently refused when material value is zero. See P-2. | Purchasing |
| 15 | Should **over-receipt** ever be permitted with a tolerance, or an approval? Currently rejected outright. | Purchasing |
| 16 | Are inter-warehouse **transfer documents/screens** required, or is the service sufficient? | Inventory |
| 17 | Should a purchase order support **partial cancellation** of remaining quantity, rather than all-or-nothing? | Purchasing |
| 18 | Should goods receipts capture **supplier invoice numbers / dates** ahead of the payables module? | Purchasing / Finance |
| 19 | Should an automatic lot allocation strategy (FIFO/FEFO) be offered as an option, rather than always choosing manually? See `DATA_MODEL.md` S-2. | Sales |
| 20 | Should **sales returns** from customers be a distinct document, or handled purely by delivery reversal? | Sales |
| 21 | Are **customer-specific price lists** required, or does price remain per-order? | Sales |
| 22 | Should a sales order **reserve** stock, or continue to reserve nothing until delivery? | Sales / Inventory |
| 23 | What is the **permanent joint-product costing method** (market value / NRV / other), replacing the provisional 85/15? | Costing |
| 24 | Should the dehusking allocation vary by **period or coconut grade**, rather than being a single fixed pair? | Costing |
| 25 | How are **labour, electricity, machine, fuel and overhead** costs captured and absorbed into production? | Costing |
| 26 | Does dehusking ever produce **waste or loss** (shrinkage, rejects), or is 1:1:1 absolute? | Production |
| 27 | Should production record **machine, shift or operator** for throughput reporting? | Production |
| 28 | Should **drying / moisture / process loss** be captured for copra, and on what basis (input weight vs. output weight, per batch or per dryer run)? | Copra production |
| 29 | Is the **1 coconut = 1 shell** rule absolute, or do broken shells and rejects need recording? | Copra production |
| 30 | Should copra be gradeable (edible / milling / rejected), each grade taking its own share? | Copra production / Costing |

**Confirmed and closed this phase:** reversal cost basis (I-1), secondary quantity (I-2),
company-wide weighted average (I-3), and the inactive-product incoming-stock rule.
