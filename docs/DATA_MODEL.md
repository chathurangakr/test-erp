# Data Model — Coconut Factory ERP

> **Status:** Product Master and Phase 2 master data (suppliers, customers, warehouses)
> implemented. All transactional entities are `TBD` / not started.

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
