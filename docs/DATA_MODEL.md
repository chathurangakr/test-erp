# Data Model — Coconut Factory ERP

> **Status:** Product Master implemented. All other entities are `TBD` / not started.

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

## 3. Not yet designed — required future shape

These tables are **not implemented**. The constraints below are confirmed decisions that
the future schema must satisfy.

| Entity | Confirmed requirement |
| --- | --- |
| `warehouses` | **Multiple warehouses must be supported.** A single default `MAIN` / "Main Warehouse" is created in a later task. See `INVENTORY_RULES.md` §5. |
| `inventory_movements` (ledger) | Must carry **a warehouse reference** and **an optional lot/batch reference**, plus every field in `INVENTORY_RULES.md` §3. |
| `inventory_lots` | **Lot/batch traceability must be supported.** See `INVENTORY_RULES.md` §6. |
| `suppliers`, `customers` | Not started |
| `purchase_orders`, `sales_orders` | Not started |
| `production_orders` (dehusking / chipping / copra) | Must record production **waste** that does not create inventory. See `BUSINESS_RULES.md` §5. |
| `accounts`, `journals`, `journal_lines` | `ACCOUNTING_RULES.md` |
| `roles`, `permissions` | Deferred by instruction |

## 4. Conventions

- Money and measurable quantities use **`decimal`**, never float. (No such columns exist
  yet — Product Master stores no quantities.)
- Foreign keys are declared at the database level.
- Business records are **not hard-deleted**; use `is_active`, or reversal for transactions.
