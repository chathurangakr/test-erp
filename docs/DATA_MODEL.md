# Data Model — Coconut Factory ERP

> **Status:** Master data (Phases 1-2), the inventory engine (Phase 3) and Purchasing
> (Phase 4) implemented. Sales, production and finance are not started.

## 1. Implemented tables

### 1.1 `units_of_measure`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `code` | string(20), **unique** | Stored uppercase, e.g. `NOS`, `KG` |
| `name` | string(100) | e.g. "Numbers", "Kilogram" |
| `decimal_places` | unsigned tinyint | How many decimals this UOM allows |
| `is_active` | boolean, default `true` | |
| `created_at` / `updated_at` | timestamps | |

**Seeded UOMs**

| Code | Name | decimal_places | Rationale |
| --- | --- | --- | --- |
| `NOS` | Numbers | `0` | Countable physical items; cannot be fractional |
| `KG` | Kilogram | `3` | Weight, recorded to the gram |

**There are exactly two units of measure.** `NOS` for countable items, `KG` for weight.
See decision **D-1** below.

### 1.2 `products`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `code` | string(50), **unique** | **Normalised to uppercase** |
| `name` | string(150) | |
| `description` | text, **nullable** | |
| `primary_uom_id` | FK → `units_of_measure.id`, **required** | `restrictOnDelete` |
| `secondary_uom_id` | FK → `units_of_measure.id`, **nullable** | `restrictOnDelete` |
| `is_active` | boolean, default `true` | |
| `created_at` / `updated_at` | timestamps | |

**Deliberately absent: there is NO `current_stock` column** — and an automated test asserts
it stays absent. Stock is derived from the future inventory ledger.
See `INVENTORY_RULES.md` §1–2.

**Constraints**

- `code` unique (database-level unique index, not just validation).
- Both UOM foreign keys use `restrictOnDelete` — a UOM in use cannot be deleted.
- `primary_uom_id` and `secondary_uom_id` must differ (application-level validation).

**Seeded products**

| Code | Name | Primary UOM | Secondary UOM |
| --- | --- | --- | --- |
| `COCONUT` | Whole Coconut | `NOS` | — |
| `DEHUSKED-COCONUT` | Dehusked Coconut | `NOS` | — |
| `RAW-HUSK` | Raw Coconut Husk | `NOS` | — |
| `COPRA` | Copra | `KG` | — |
| `HUSK-7C` | 7C | `KG` | — |
| `HUSK-9C` | 9C | `KG` | — |
| `HUSK-10C` | 10C | `KG` | — |
| `RAW-SHELL` | Raw Coconut Shell | `NOS` | `KG` |

Products are **seeded, never hard-coded in application logic**.


### 1.3 `suppliers`

One supplier table covers every material the factory buys — whole coconut, raw husk, and
anything added later. There are deliberately **no separate coconut/husk supplier tables**.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `code` | string(50), **unique** | **Normalised to uppercase** |
| `name` | string(150) | required |
| `contact_person` | string(150), nullable | |
| `phone` / `secondary_phone` | string(30), nullable | |
| `email` | string(150), nullable | validated as an email |
| `address_line_1` / `address_line_2` | string(200), nullable | |
| `city` / `district` | string(100), nullable | |
| `registration_number` / `tax_number` | string(100), nullable | |
| `payment_terms_days` | unsigned smallint, default `0` | **cannot be negative** |
| `notes` | text, nullable | |
| `is_active` | boolean, default `true` | |
| `created_at` / `updated_at` | timestamps | |

**No balance, outstanding or purchase-total column.** Amounts owed are derived from posted
transactions in the finance module. An automated test guards this.

### 1.4 `customers`

One customer table covers every product the factory sells. There are deliberately **no
separate customer tables per product**.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `code` | string(50), **unique** | **Normalised to uppercase** |
| `name` | string(150) | required |
| `contact_person` | string(150), nullable | |
| `phone` / `secondary_phone` | string(30), nullable | |
| `email` | string(150), nullable | |
| `address_line_1` / `address_line_2` | string(200), nullable | |
| `city` / `district` | string(100), nullable | |
| `registration_number` / `tax_number` | string(100), nullable | |
| `credit_limit` | **`decimal(15,2)`**, default `0` | **cannot be negative**; an agreed term, *not* a balance |
| `credit_days` | unsigned smallint, default `0` | **cannot be negative** |
| `notes` | text, nullable | |
| `is_active` | boolean, default `true` | |
| `created_at` / `updated_at` | timestamps | |

**No balance or receivable column.** Outstanding amounts are derived from posted
transactions. An automated test guards this.

### 1.5 `warehouses`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `code` | string(50), **unique** | **Normalised to uppercase** |
| `name` | string(150) | required |
| `description` | text, nullable | |
| `address` | text, nullable | |
| `is_active` | boolean, default `true` | |
| `is_default` | boolean, default `false` | **exactly one active warehouse is the default** |
| `created_at` / `updated_at` | timestamps | |

**Seeded warehouse — only one**

| Code | Name | is_default | is_active |
| --- | --- | --- | --- |
| `MAIN` | Main Warehouse | `true` | `true` |

The example yards discussed for the future (Coconut Yard, Husk Yard, Copra Store, Shell
Yard, Finished Goods Store) are **deliberately not seeded** — they are illustrations, not
confirmed requirements. A test asserts they are absent.


### 1.6 `inventory_lots`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `lot_number` | string(50), **unique** | `LOT-YYYYMMDD-000001`; no business meaning |
| `product_id` | FK → `products.id` | `restrictOnDelete`; a lot belongs to ONE product |
| `supplier_lot_number` | string(100), nullable | the supplier's own batch reference |
| `source_type` / `source_id` | string(100) / bigint, nullable | originating document |
| `manufactured_at` / `received_at` | date, nullable | |
| `notes` | text, nullable | |
| `is_active` | boolean, default `true` | stock cannot be received into an inactive lot |
| `created_at` / `updated_at` | timestamps | |

### 1.7 `inventory_transactions` (header)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `reference` | string(50), **unique** | `INV-YYYYMMDD-000001` |
| `transaction_type` | string(50) | `App\Enums\InventoryTransactionType` |
| `transaction_date` | datetime | |
| `source_type` / `source_id` | string(100) / bigint, nullable | originating document |
| `reversal_of_id` | FK → self, nullable, **unique** | the unique index is what makes double reversal impossible |
| `reversed_by_id` | FK → self, nullable | append-only audit pointer |
| `notes` | text, nullable | |
| `posted_by` | FK → `users.id`, nullable | |
| `posted_at` | datetime | |
| `created_at` / `updated_at` | timestamps | |

**Immutable.** The model throws on `updating` and `deleting`; only `reversed_by_id` may be
written after posting.

### 1.8 `inventory_transaction_lines` (the ledger)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `inventory_transaction_id` | FK → `inventory_transactions.id` | `restrictOnDelete` |
| `product_id` | FK → `products.id` | `restrictOnDelete` |
| `warehouse_id` | FK → `warehouses.id` | `restrictOnDelete`, **required** |
| `lot_id` | FK → `inventory_lots.id`, **nullable** | `restrictOnDelete` |
| `direction` | string(3) | `IN` / `OUT` — decides the sign |
| `quantity` | **`decimal(20,3)`** | always positive |
| `secondary_quantity` | **`decimal(20,3)`**, nullable | dual-UOM products only |
| `unit_cost` | **`decimal(20,6)`** | weighted average at posting time for OUT |
| `total_cost` | **`decimal(20,6)`** | |
| `created_at` / `updated_at` | timestamps | |

**Immutable**, like the header. Indexed on `(product_id, warehouse_id)` and
`(product_id, warehouse_id, lot_id)` to serve the stock queries.

**This table is the single source of truth for stock.** No stock column exists on any
master table, and there is no cache.


### 1.9 `purchase_orders`

An intention to buy. Creates **no inventory**.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `po_number` | string(50), **unique** | `PO-YYYYMMDD-000001` |
| `supplier_id` | FK → `suppliers.id` | `restrictOnDelete` |
| `warehouse_id` | FK → `warehouses.id` | `restrictOnDelete` |
| `order_date` | date | |
| `expected_date` | date, nullable | |
| `status` | string(30) | `App\Enums\PurchaseOrderStatus` |
| `supplier_reference` | string(100), nullable | |
| `notes` | text, nullable | |
| `created_by` / `approved_by` / `cancelled_by` | FK → `users.id`, nullable | |
| `approved_at` / `cancelled_at` | datetime, nullable | |
| `created_at` / `updated_at` | timestamps | |

### 1.10 `purchase_order_lines`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `purchase_order_id` | FK → `purchase_orders.id` | `cascadeOnDelete` — a line has no meaning without its draft header |
| `product_id` | FK → `products.id` | `restrictOnDelete` |
| `quantity` | **`decimal(20,3)`** | |
| `unit_price` | **`decimal(20,6)`** | |
| `line_total` | **`decimal(20,6)`** | |
| `received_quantity` | **`decimal(20,3)`**, default `0` | A **progress counter**, not stock |
| `created_at` / `updated_at` | timestamps | |

`received_quantity` is maintained by goods-receipt posting and reversal. It is never a
source of stock — stock lives only in `inventory_transaction_lines`.

### 1.11 `goods_receipts`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `grn_number` | string(50), **unique** | `GRN-YYYYMMDD-000001` |
| `supplier_id` | FK → `suppliers.id` | `restrictOnDelete` |
| `purchase_order_id` | FK → `purchase_orders.id`, **nullable** | Null = direct purchase |
| `warehouse_id` | FK → `warehouses.id` | `restrictOnDelete` |
| `receipt_date` | date | |
| `supplier_delivery_reference` | string(100), nullable | |
| `transport_cost` / `loading_cost` / `other_cost` | **`decimal(20,6)`**, default `0` | Header landed costs |
| `status` | string(30) | `App\Enums\GoodsReceiptStatus` |
| `inventory_transaction_id` | FK → `inventory_transactions.id`, nullable | The ledger posting this receipt created |
| `reversal_inventory_transaction_id` | FK → `inventory_transactions.id`, nullable | Its reversal, if any |
| | | Status values: `DRAFT`, `POSTED`, `REVERSED`, `CANCELLED` |
| `reversed_at` / `reversed_by` | datetime / FK users, nullable | |
| `posted_by` / `posted_at` | FK users / datetime, nullable | |
| `created_by` | FK → `users.id`, nullable | |
| `created_at` / `updated_at` | timestamps | |

**Immutable once posted.** The model throws on update unless the change is confined to the
reversal audit fields, and refuses deletion of anything but a draft.

### 1.12 `goods_receipt_lines`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `goods_receipt_id` | FK → `goods_receipts.id` | `cascadeOnDelete` |
| `purchase_order_line_id` | FK → `purchase_order_lines.id`, **nullable** | `restrictOnDelete` |
| `product_id` | FK → `products.id` | `restrictOnDelete` |
| `quantity` | **`decimal(20,3)`** | |
| `secondary_quantity` | **`decimal(20,3)`**, nullable | Dual-UOM products only |
| `unit_price` | **`decimal(20,6)`** | The bare purchase price |
| `material_cost` | **`decimal(20,6)`** | `quantity × unit_price` |
| `allocated_landed_cost` | **`decimal(20,6)`** | This line's share of the header costs |
| `final_unit_cost` | **`decimal(20,6)`** | **What is posted to inventory** |
| `lot_id` | FK → `inventory_lots.id`, nullable | Created at posting time |
| `supplier_lot_number` | string(100), nullable | The supplier's own batch reference |
| `created_at` / `updated_at` | timestamps | |

The three cost columns exist so a posted receipt can always be **explained**: they record
how the landed unit cost was arrived at. They are calculated by the server and never
accepted from a request.

## 2. Resolved decisions

### D-1 — `QTY` unit of measure — ✅ **RESOLVED**

**Decision: there is no separate `QTY` unit. `NOS` is used for all countable items.**

- `NOS` = countable physical items
- `KG` = weight

Raw Coconut Shell therefore uses **Primary `NOS`, Secondary `KG`**.

The business may describe shell sales as "Qty" or "KG" in the user interface, but the
**inventory** unit for shell quantity is `NOS`. "Qty" is presentation wording, not a unit.

**Implementation:** migration `2026_09_12_190500_replace_qty_unit_of_measure_with_nos`
repoints any product using `QTY` to `NOS` and then removes `QTY` if nothing references it.
It is idempotent and a no-op on a fresh database. An automated test asserts the unit master
contains exactly `NOS` and `KG`.

### D-2 — the "one default warehouse" rule is enforced in code, not by a constraint

`CLAUDE.md` rule 5 asks for database constraints where appropriate. For "only one **active**
warehouse may be the default", a database constraint is **not** appropriate here:

- MariaDB has no partial/filtered unique index.
- SQLite (used by the test suite) supports one, but with different syntax.

A MariaDB-only constraint would leave the **test schema weaker than production**, which is
exactly the setup that produces failures only in production. The invariant is therefore
enforced in `App\Actions\Warehouses\SetDefaultWarehouse`, inside a database transaction
that clears the old default before setting the new one, so no moment exists with two
defaults. `is_default` is never written directly by a controller. Tests cover it.

### D-3 — the default warehouse cannot be deactivated — ⚠️ **needs confirmation**

The rule "only one **active** warehouse may be the default" does not say what should happen
when someone deactivates the current default. An inactive default is a contradictory state.

Rather than silently moving the default to some other warehouse — which would be inventing
a business rule — deactivating the default is **refused** with a message telling the user to
make another warehouse the default first.

`TBD` — please confirm this is the behaviour you want.

### I-1 — a reversal reuses the original line's unit cost — ✅ **CONFIRMED**

The general rule is that outgoing stock is valued at the current weighted average. A
reversal is the exception: it reuses the **original line's** unit cost, so that it cancels
the original exactly in both quantity and value.

Reversing a receipt of 100 @ 10 after the average had drifted to 12 would otherwise remove
1,200 of value when only 1,000 went in — corrupting the valuation silently.
See `INVENTORY_RULES.md` §12.

### I-2 — secondary quantity is informational — ✅ **CONFIRMED**

Secondary quantities (shell KG) are recorded "where known", so some movements carry `null`.
The secondary balance is therefore not a reliable running total, and is **not** guarded
against going negative. Only the primary quantity is strictly enforced.
See `INVENTORY_RULES.md` §7.

### P-1 — landed cost is allocated by material value, never by quantity

Lines on one goods receipt may use different units of measure, so quantities are not
comparable — 1,000 NOS of coconut against 400 KG of husk has no meaningful quantity ratio.
Material value is the only common basis. The last line absorbs the rounding remainder so
allocations sum exactly to the header total.

`TBD` — whether specific cost types (e.g. freight by weight) should ever use a different
basis. See `BUSINESS_RULES.md` §13.

### P-2 — posting is refused when material value is zero but landed costs exist

Allocation by value is undefined with no value to allocate against. Rather than silently
falling back to quantity — which the rule above rejects — the posting is refused with a
clear message.

`TBD` — how free-goods receipts carrying transport cost should be handled, if that arises.

### I-3 — the weighted average is company-wide, not per warehouse

Version 1 maintains one average per product across all warehouses. This is what makes a
transfer value-neutral: both legs are costed at the same average, so moving stock between
warehouses changes neither company value nor the average. The `PostInventoryTransaction`
lock is taken on the **product** row for exactly this reason.

## 3. Not yet designed — required future shape

These tables are **not implemented**. The constraints below are confirmed decisions that
the future schema must satisfy.

| Entity | Confirmed requirement |
| --- | --- |
| `sales_orders`, `delivery_notes` | Not started. Must post inventory through `App\Actions\Inventory\PostInventoryTransaction`, never directly. |
| `production_orders` (dehusking / chipping / copra) | Must record production **waste** that does not create inventory. See `BUSINESS_RULES.md` §5. |
| `accounts`, `journals`, `journal_lines` | `ACCOUNTING_RULES.md` |
| `roles`, `permissions` | Deferred by instruction |

## 4. Conventions

- Money and measurable quantities use **`decimal`**, never float. Quantities are
  `decimal(20,3)` and costs `decimal(20,6)`; all arithmetic runs through
  `App\Support\DecimalMath` (BCMath), never PHP floats.
- Foreign keys are declared at the database level.
- Business records are **not hard-deleted**; use `is_active`, or reversal for transactions.
