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

No master record can be deleted through the UI; all use active/inactive status. Posted
inventory transactions cannot be edited or deleted at all.

### 4.2 Planned, not started

Production (dehusking / husk chipping / copra), Sales, Finance (double-entry), Payables,
Receivables, Costing beyond purchase landed cost, role-based permissions, REST API for the
Flutter mobile application.

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

## 7. Remaining open questions (`TBD`)

| # | Question | Blocks |
| --- | --- | --- |
| 1 | Which **joint-product cost allocation** method (market value / NRV / other)? | Finance |
| 2 | What is the **chart of accounts**? | Finance |
| 3 | Are there **multiple currencies**, or LKR only? | Finance |
| 4 | Is **tax** (VAT/SVAT) in scope? | Finance |
| 5 | What **roles and permissions** are required? | Permissions |
| 6 | Is **partial/failed production output** possible (input consumed, no output)? | Production |
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

**Confirmed and closed this phase:** reversal cost basis (I-1), secondary quantity (I-2),
company-wide weighted average (I-3), and the inactive-product incoming-stock rule.
