# Inventory Rules — Coconut Factory ERP

> **Status:** Rules confirmed. Implementation **not started** — the inventory ledger is a
> future task. This document defines the contract that implementation must satisfy.

## 1. The fundamental rule

**Stock must be derived from inventory transactions.**

On-hand quantity is *always* the sum of the movements in the inventory ledger. It is never
read from a stored total.

## 2. Prohibited

**Never directly maintain inventory by simply changing a product stock field.**

Specifically:

- There must be **no `current_stock` column on `products`** (enforced by an automated test).
- No `UPDATE products SET stock = ...` anywhere.
- No cached balance may be treated as the source of truth. A performance snapshot table is
  permitted **only** if it is rebuildable from the ledger and never authoritative.

**Why:** a stored total has no history. If it drifts, there is no way to discover when or
why. A ledger can always be recomputed and audited.

## 3. Required fields on every stock movement

Every stock movement must record **at minimum**:

| Field | Notes |
| --- | --- |
| product | FK to `products` |
| warehouse | FK to `warehouses` — **required on every movement** (§5) |
| quantity | `decimal`, never float |
| UOM | FK to `units_of_measure` |
| movement direction / type | in / out (and movement classification) |
| transaction / source type | e.g. purchase, sale, production input, production output |
| source record ID | the document that caused the movement |
| date / time | when the movement occurred |
| user | who caused it |
| cost | where relevant |
| lot / batch | nullable FK — **must be supported** (§6) |

## 4. Negative inventory — ✅ **DECIDED**

**Negative inventory is NOT allowed by default.**

A transaction that would take available stock below zero **must fail atomically** — the
entire transaction is rolled back, leaving no partial movements behind.

Requirements for the future implementation:

- The check runs **inside** the same database transaction that writes the movements, so a
  concurrent transaction cannot slip past it.
- Failure rolls back **every** movement in the business event, not just the offending line.
- The guard is a deliberate, **configurable** rule (a future setting may permit negative
  stock for specific cases) — never an accident of ordering.

`TBD` — the exact configuration surface that would permit negative stock, and for which
products or warehouses. Default remains "not allowed".

## 5. Warehouses — ✅ **DECIDED**

**The ERP must support multiple warehouses.**

- Every inventory transaction **requires a warehouse reference**.
- **Version 1** operates with a single default warehouse, created in a later task:

  | Code | Name |
  | --- | --- |
  | `MAIN` | Main Warehouse |

- The schema must **not** assume a single warehouse. Multi-warehouse support is structural,
  not a later migration.

Possible future warehouses — **examples only, not to be seeded**: Coconut Yard, Husk Yard,
Copra Store, Shell Yard, Finished Goods Store.

Warehouse Master is **not implemented in the current task**.

`TBD` — whether inter-warehouse transfers are required, and whether stock is valued per
warehouse or globally.

## 6. Lot / batch traceability — ✅ **DECIDED**

**The inventory architecture must support lot/batch traceability.**

Future inventory transactions **may reference a lot/batch**. Lot tracking is expected to be
used especially for:

- coconut purchases
- raw husk purchases
- dehusking production
- husk chipping production
- copra production
- shell outputs

The lot/batch reference is **nullable** on a movement — not every movement will carry one —
but the schema must accommodate it from the start rather than retrofitting it later.

Inventory lots are **not implemented in the current task**.

`TBD` — lot numbering scheme, whether lots are mandatory per product, and expiry handling
(if any).

## 7. Dual unit of measure (Raw Coconut Shell)

Raw Coconut Shell is stocked in `NOS` and may also be sold by `KG`. The user interface may
label the `NOS` count as "Qty"; the inventory unit is `NOS`.

- Inventory must preserve **both actual quantity and actual weight where available**.
- **No fixed NOS↔KG conversion may be applied**, because shell weights vary.
- A movement may therefore carry a secondary quantity alongside the primary quantity.

`TBD` — whether other products will ever need dual UOM.

## 8. Costing

- Intended method: **Weighted Average Cost**.
- Cost is recorded on movements where relevant.
- Joint-product cost allocation (dehusking, chipping, copra) is **`TBD`** and must not be
  implemented yet.

## 9. Transactional integrity

- All stock movements for one business event must be written **inside a single database
  transaction**. A dehusking that decreased Whole Coconut but failed before increasing
  Dehusked Coconut would corrupt stock.
- Movements are **never edited or deleted**. Corrections are made by posting a reversing
  movement.

## 10. Remaining open questions (`TBD`)

| # | Question |
| --- | --- |
| 1 | Are inter-warehouse transfers required? |
| 2 | Is stock valued per warehouse or globally? |
| 3 | Lot numbering scheme; are lots mandatory for particular products? |
| 4 | The configuration surface that would permit negative stock, if ever |
| 5 | Joint-product cost allocation method (see `ACCOUNTING_RULES.md`) |

**Resolved:** warehouse support (§5), lot/batch support (§6), negative inventory (§4).
Husk-chipping waste is resolved in `BUSINESS_RULES.md` §5 — waste creates **no** inventory.
