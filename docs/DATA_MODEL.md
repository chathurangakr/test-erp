# Data Model — Coconut Factory ERP

> **Status:** Master data (Phases 1-2), the inventory engine (Phase 3), Purchasing
> (Phase 4), Sales (Phase 5) and Dehusking production (Phase 6) implemented. Husk
> chipping, copra and finance are not started.

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
| `unit_cost` | **`decimal(20,6)`** | the **rounded per-unit representation** of `total_cost`; weighted average at posting time for an ordinary OUT |
| `total_cost` | **`decimal(20,6)`** | **AUTHORITATIVE.** The monetary value of the movement. Not required to equal `quantity × unit_cost` — see decision **I-4** |
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


### 1.13 `sales_orders`

An intention to sell. Reserves nothing and creates **no inventory movement**.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `so_number` | string(50), **unique** | `SO-YYYYMMDD-000001` |
| `customer_id` | FK → `customers.id` | `restrictOnDelete` |
| `warehouse_id` | FK → `warehouses.id` | `restrictOnDelete` |
| `order_date` | date | |
| `expected_delivery_date` | date, nullable | |
| `customer_reference` | string(100), nullable | |
| `status` | string(30) | `App\Enums\SalesOrderStatus` |
| `notes` | text, nullable | |
| `created_by` / `approved_by` / `cancelled_by` | FK → `users.id`, nullable | |
| `approved_at` / `cancelled_at` | datetime, nullable | |
| `created_at` / `updated_at` | timestamps | |

### 1.14 `sales_order_lines`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `sales_order_id` | FK → `sales_orders.id` | `cascadeOnDelete` |
| `product_id` | FK → `products.id` | `restrictOnDelete` |
| `quantity` | **`decimal(20,3)`** | |
| `secondary_quantity` | **`decimal(20,3)`**, nullable | dual-UOM products |
| `pricing_basis` | string(20), default `PRIMARY` | `App\Enums\PricingBasis` |
| `unit_price` | **`decimal(20,6)`** | |
| `line_total` | **`decimal(20,6)`** | priced quantity × unit price |
| `delivered_quantity` | **`decimal(20,3)`**, default `0` | a **progress counter**, not stock |
| `delivered_secondary_quantity` | **`decimal(20,3)`**, nullable | |
| `created_at` / `updated_at` | timestamps | |

### 1.15 `deliveries`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `delivery_number` | string(50), **unique** | `DN-YYYYMMDD-000001` |
| `customer_id` | FK → `customers.id` | `restrictOnDelete` |
| `sales_order_id` | FK → `sales_orders.id`, **nullable** | Null = direct delivery |
| `warehouse_id` | FK → `warehouses.id` | `restrictOnDelete` |
| `delivery_date` | date | |
| `customer_reference` / `vehicle_number` / `driver_name` | string, nullable | dispatch details |
| `status` | string(30) | `App\Enums\DeliveryStatus` — DRAFT / POSTED / REVERSED / CANCELLED |
| `inventory_transaction_id` | FK → `inventory_transactions.id`, nullable | the `SALES_ISSUE` it created |
| `reversal_inventory_transaction_id` | FK → `inventory_transactions.id`, nullable | its reversal, if any |
| `posted_by` / `posted_at` | FK users / datetime, nullable | |
| `reversed_by` / `reversed_at` | FK users / datetime, nullable | |
| `created_by` | FK → `users.id`, nullable | |
| `created_at` / `updated_at` | timestamps | |

**Immutable once posted.** The model throws on update unless the change is confined to the
reversal audit fields, and refuses deletion of anything but a draft.

### 1.16 `delivery_lines`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `delivery_id` | FK → `deliveries.id` | `cascadeOnDelete` |
| `sales_order_line_id` | FK → `sales_order_lines.id`, nullable | `restrictOnDelete` |
| `product_id` | FK → `products.id` | `restrictOnDelete` |
| `lot_id` | FK → `inventory_lots.id`, nullable | required where lotted stock exists |
| `quantity` | **`decimal(20,3)`** | what leaves stock |
| `secondary_quantity` | **`decimal(20,3)`**, nullable | |
| `pricing_basis` | string(20) | which quantity revenue follows |
| `unit_price` | **`decimal(20,6)`** | commercial price |
| `sales_value` | **`decimal(20,6)`** | priced quantity × unit price |
| `inventory_unit_cost` | **`decimal(20,6)`**, **nullable** | read back from the ledger at posting |
| `cost_of_goods_sold` | **`decimal(20,6)`**, **nullable** | primary quantity × inventory unit cost |
| `created_at` / `updated_at` | timestamps | |

Both cost columns stay `null` until the delivery is posted — a draft has no cost because no
stock has moved. **Gross profit and margin are computed, never stored**, so they cannot
drift from the figures they derive from.


### 1.17 `production_recipes` and `production_recipe_outputs`

Configuration, not transactions. These two tables are the **only** place that knows which
products a production type involves and how it shares value — product codes appear nowhere
else in the application except the seeder.

`production_recipes`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `production_type` | string(30), **unique** | `App\Enums\ProductionType` |
| `input_product_id` | FK → `products.id` | `restrictOnDelete` |
| `notes` | text, nullable | |
| `is_active` | boolean, default `true` | |

`production_recipe_outputs`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `production_recipe_id` | FK → `production_recipes.id` | `cascadeOnDelete` |
| `product_id` | FK → `products.id` | `restrictOnDelete`; unique per recipe |
| `output_ratio` | **`decimal(20,6)`**, **nullable**, default `1` | units of output per unit of input. `NULL` = **no fixed ratio; the operator measures the output** — see decision **P-6** |
| `requires_secondary_quantity` | boolean, default `false` | this output must also be **measured in its secondary unit** — see decision **P-9** |
| `allocation_percent` | **`decimal(9,6)`** | share of consumed value; **must total exactly 100** |
| `sort_order` | unsigned smallint | |

**Seeded recipe — DEHUSKING**

| Input | Output | Ratio | Allocation |
| --- | --- | --- | --- |
| `COCONUT` | `DEHUSKED-COCONUT` | 1.000000 | **85%** |
| `COCONUT` | `RAW-HUSK` | 1.000000 | **15%** |

**Seeded recipe — HUSK_CHIPPING**

| Input | Output | Ratio | Allocation |
| --- | --- | --- | --- |
| `RAW-HUSK` | `HUSK-7C` | **`NULL`** — operator-entered | **30%** |
| `RAW-HUSK` | `HUSK-9C` | **`NULL`** — operator-entered | **30%** |
| `RAW-HUSK` | `HUSK-10C` | **`NULL`** — operator-entered | **40%** |

**Seeded recipe — COPRA**

| Input | Output | Ratio | Secondary required | Allocation |
| --- | --- | --- | --- | --- |
| `DEHUSKED-COCONUT` | `COPRA` | **`NULL`** — operator-entered KG | no | **75%** |
| `DEHUSKED-COCONUT` | `RAW-SHELL` | **`1.000000`** — one shell per coconut | **yes — measured KG** | **25%** |

Copra is the first recipe that mixes both kinds of output in one row set: the shell *count*
is derived while its *weight*, and the copra weight, are measured. That is why
`output_ratio` is per output and nullable rather than a property of the production type.

The percentages are **provisional** pending the factory's costing policy. See
`BUSINESS_RULES.md` §18, §19 and §20.

### 1.18 `production_orders`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint unsigned, PK | |
| `production_number` | string(50), **unique** | `PRD-YYYYMMDD-000001` |
| `production_type` | string(30) | `App\Enums\ProductionType` |
| `production_date` | date | |
| `warehouse_id` | FK → `warehouses.id` | `restrictOnDelete` |
| `status` | string(30) | DRAFT / POSTED / REVERSED / CANCELLED |
| `notes` | text, nullable | |
| `waste_quantity` | **`decimal(20,3)`**, nullable | informational only — creates **no** inventory, lot or cost |
| `waste_uom_id` | FK → `units_of_measure.id`, nullable | required when waste is recorded; the real UOM master, never an invented unit |
| `inventory_transaction_id` | FK, nullable | the `PRODUCTION_CONSUMPTION` |
| `output_inventory_transaction_id` | FK, nullable | the `PRODUCTION_OUTPUT` |
| `reversal_inventory_transaction_id` | FK, nullable | reversal of the consumption |
| `output_reversal_transaction_id` | FK, nullable | reversal of the output |
| `created_by` / `posted_by` / `reversed_by` | FK users, nullable | |
| `posted_at` / `reversed_at` | datetime, nullable | |

**Four transaction links, not two** — see decision **P-3** below.

**Immutable once posted.** The model throws on update unless the change is confined to the
reversal audit fields, and refuses deletion of anything but a draft.

### 1.19 `production_inputs` and `production_outputs`

`production_inputs`

| Column | Type | Notes |
| --- | --- | --- |
| `production_order_id` | FK, `cascadeOnDelete` | |
| `product_id` | FK → `products.id` | |
| `lot_id` | FK → `inventory_lots.id`, nullable | **required for every implemented production type** — traceability |
| `quantity` | **`decimal(20,3)`** | |
| `unit_cost` / `total_cost` | **`decimal(20,6)`**, **nullable** | read back from the ledger at posting |

`production_outputs`

| Column | Type | Notes |
| --- | --- | --- |
| `production_order_id` | FK, `cascadeOnDelete` | |
| `product_id` | FK → `products.id` | |
| `lot_id` | FK → `inventory_lots.id`, nullable | a **new** lot created at posting; stays `NULL` for a grade that measured zero |
| `quantity` | **`decimal(20,3)`** | derived from the recipe ratio, or **measured by the operator** where the ratio is `NULL` |
| `secondary_quantity` | **`decimal(20,3)`**, **nullable** | the operator's measurement in the product's secondary unit — raw shell KG. **Never a conversion of `quantity`** |
| `allocation_percent` | **`decimal(9,6)`** | the share **actually applied**, snapshotted — `0` for a grade that produced nothing |
| `allocated_cost` | **`decimal(20,6)`**, **nullable** | `NULL` on a draft; sums **exactly** to the consumed value once posted |
| `unit_cost` | **`decimal(20,6)`**, **nullable** | `NULL` on a draft; allocated cost / **primary** quantity once posted |

No labour, electricity, machine, fuel, drying or overhead columns exist anywhere — a test
asserts their absence.

### 1.20 `fiscal_periods`

| Column | Type | Notes |
| --- | --- | --- |
| `name` | string(100), **unique** | e.g. "September 2026" |
| `start_date` / `end_date` | date | Arbitrary — **nothing assumes a calendar year** |
| `status` | string(20) | OPEN / CLOSED |
| `closed_at` / `closed_by` | datetime, FK users, nullable | |

A journal posts only into an **OPEN** period containing its date. Periods must not overlap.
Reopening is deliberately not implemented. See `ACCOUNTING_RULES.md` §7.

### 1.21 `accounts`

| Column | Type | Notes |
| --- | --- | --- |
| `code` | string(20), **unique** | Uppercased by `HasUppercaseCode` |
| `name` | string(255) | |
| `account_type` | string(30) | `App\Enums\AccountType` |
| `parent_id` | FK → `accounts.id`, nullable | `restrictOnDelete` |
| `normal_balance` | string(10) | DEBIT / CREDIT — stored explicitly, defaulted by type |
| `is_control_account` | boolean | Reconciled from a subsidiary ledger |
| `allow_manual_posting` | boolean | False for group headers |
| `is_active` | boolean | |

**There is NO balance column, and there must never be one** — see decision **A-1**. An
account with journal history refuses deletion; there is no destroy route.

### 1.22 `journal_entries`

| Column | Type | Notes |
| --- | --- | --- |
| `journal_number` | string(50), **unique** | `JV-YYYYMMDD-000001` |
| `journal_date` | date | Must fall inside its fiscal period |
| `fiscal_period_id` | FK → `fiscal_periods.id` | `restrictOnDelete` |
| `source_type` / `source_id` | string / bigint, nullable | **Null on a manual entry.** Reserved for generated journals — see decision **A-3** |
| `description` | string(500) | |
| `status` | string(20) | DRAFT / POSTED / REVERSED / CANCELLED |
| `reversal_of_id` | FK, nullable, **unique** | Points at the original |
| `reversed_by_id` | FK, nullable | Points at the reversal |
| `created_by` / `posted_by` / `posted_at` / `reversed_by_user_id` / `reversed_at` | | Full audit trail |

**Immutable once posted.** The model throws on update unless the change is confined to the
reversal audit fields, and refuses deletion of anything but a draft. The **unique** index on
`reversal_of_id` makes a second reversal impossible at the database level, not only in code.

### 1.23 `journal_entry_lines`

| Column | Type | Notes |
| --- | --- | --- |
| `journal_entry_id` | FK, `cascadeOnDelete` | |
| `account_id` | FK → `accounts.id` | `restrictOnDelete` |
| `description` | string(500), nullable | |
| `debit` | **`decimal(20,6)`**, default 0 | Positive |
| `credit` | **`decimal(20,6)`**, default 0 | Positive |

A line is a debit **or** a credit — never both, never neither — see decision **A-2**. Lines
of a posted entry are immutable, so history cannot be edited from underneath the header.

The scale matches `inventory_transaction_lines.total_cost` **on purpose**: when Finance
later posts inventory values, the authoritative figure must transfer without rounding
(decision **I-4**).

### 1.24 `accounting_settings`

A single row of configuration.

| Column | Type | Notes |
| --- | --- | --- |
| `operational_accounting_start_date` | date, **nullable** | The **cutover**. NULL means operational accounting is not switched on. **Never seeded** — see decision **A-5** |
| `grni_account_id` | FK → `accounts.id`, nullable | Goods Received Not Invoiced |
| `updated_by` | FK users, nullable | |

### 1.25 `product_account_mappings`

| Column | Type | Notes |
| --- | --- | --- |
| `product_id` | FK → `products.id`, **unique**, `cascadeOnDelete` | |
| `inventory_account_id` | FK → `accounts.id` | Must be an **ASSET** account |
| `cogs_account_id` | FK → `accounts.id` | Must be a **COST_OF_SALES** account |
| `revenue_account_id` | FK → `accounts.id`, nullable | Must be a **REVENUE** account. Configured now, unused until sales invoicing |

The only place a product meets the chart of accounts — see decision **A-6**. A product with
no row here cannot move stock once the cutover is reached.

### 1.26 `supplier_invoices` and `supplier_invoice_lines`

`supplier_invoices`

| Column | Type | Notes |
| --- | --- | --- |
| `internal_invoice_number` | string(50), **unique** | `PINV-YYYYMMDD-000001` — ours |
| `supplier_id` | FK → `suppliers.id` | |
| `supplier_invoice_number` | string(100) | The supplier's own number, as typed |
| `supplier_invoice_number_normalised` | string(100) | Trimmed, uppercased. **Unique per supplier** — see decision **P-11** |
| `invoice_date` / `due_date` | date | |
| `fiscal_period_id` | FK, nullable | Resolved at posting |
| `status` | string(30) | DRAFT / POSTED / PARTIALLY_PAID / PAID / REVERSED / CANCELLED |
| `journal_entry_id` / `reversal_journal_entry_id` | FK, nullable | |
| audit columns | | created / posted / reversed, each with user and timestamp |

**No outstanding-balance column.** What the invoice still owes is derived — decision **P-12**.

`supplier_invoice_lines`

| Column | Type | Notes |
| --- | --- | --- |
| `line_type` | string(30) | `GRNI_MATCH` or `DIRECT_EXPENSE` |
| `goods_receipt_id` | FK, nullable | Set for a GRNI match |
| `debit_account_id` | FK → `accounts.id`, nullable | Set for a direct expense |
| `amount` | **`decimal(20,6)`** | |

Exactly one of the two foreign keys is set, decided by `line_type`. There are **no tax
lines**: VAT/SVAT is not modelled.

### 1.27 `supplier_payments` and `supplier_payment_allocations`

`supplier_payments`

| Column | Type | Notes |
| --- | --- | --- |
| `payment_number` | string(50), **unique** | `PAY-YYYYMMDD-000001` |
| `supplier_id` | FK → `suppliers.id` | |
| `payment_date` | date | |
| `payment_account_id` | FK → `accounts.id` | The cash or bank account paid from |
| `amount` | **`decimal(20,6)`** | |
| `status` | string(30) | DRAFT / POSTED / REVERSED / CANCELLED |
| `journal_entry_id` / `reversal_journal_entry_id` | FK, nullable | |

`supplier_payment_allocations` — `supplier_payment_id` + `supplier_invoice_id` (unique
together) + `amount`. Many-to-many on purpose: one payment may settle several invoices, and
one invoice may take several payments. Allocations of a REVERSED payment stay on the record
and stop counting.

`accounting_settings` also gained **`trade_payables_account_id`**, seeded to 2110, so no AP
action contains an account code.

### 1.28 `sales_invoices` and `sales_invoice_lines`

`sales_invoices`

| Column | Type | Notes |
| --- | --- | --- |
| `invoice_number` | string(50), **unique** | `SINV-YYYYMMDD-000001` |
| `customer_id` | FK → `customers.id` | |
| `invoice_date` / `due_date` | date | The due date defaults from `customers.credit_days` and stays editable |
| `fiscal_period_id` | FK, nullable | Resolved at posting |
| `customer_reference` | string(100), nullable | The customer's own order or reference |
| `status` | string(30) | DRAFT / POSTED / PARTIALLY_PAID / PAID / REVERSED / CANCELLED |
| `journal_entry_id` / `reversal_journal_entry_id` | FK, nullable | |
| audit columns | | created / posted / reversed, each with user and timestamp |

**No outstanding-balance column** and **no total column.** Both are derived — decision **S-3**.

`sales_invoice_lines`

| Column | Type | Notes |
| --- | --- | --- |
| `sales_invoice_id` | FK | |
| `delivery_line_id` | FK → `delivery_lines.id` | **Always set** — a line always bills delivered goods |
| `product_id` | FK | Copied from the delivery line; resolves the revenue account |
| `description` | string, nullable | |
| `amount` | **`decimal(20,6)`** | Never exceeds the delivery line's remaining value |

Indexed `(delivery_line_id, sales_invoice_id)` as `sil_delivery_invoice_index` — named
explicitly because the generated name would exceed MariaDB's 64-character limit. There are
**no tax lines**: VAT/SVAT is not modelled.

### 1.29 `customer_receipts` and `customer_receipt_allocations`

`customer_receipts`

| Column | Type | Notes |
| --- | --- | --- |
| `receipt_number` | string(50), **unique** | `REC-YYYYMMDD-000001` |
| `customer_id` | FK → `customers.id` | |
| `receipt_date` | date | |
| `receipt_account_id` | FK → `accounts.id` | The cash or bank account received into |
| `reference` | string(100), nullable | Cheque or transfer number |
| `amount` | **`decimal(20,6)`** | |
| `status` | string(30) | DRAFT / POSTED / REVERSED / CANCELLED |
| `journal_entry_id` / `reversal_journal_entry_id` | FK, nullable | |

`customer_receipt_allocations` — `customer_receipt_id` + `sales_invoice_id` (unique together as
`cra_receipt_invoice_unique`) + `amount`. Many-to-many on purpose: one receipt may settle
several invoices, and one invoice may take several receipts. Allocations of a REVERSED receipt
stay on the record and stop counting. The allocations must total the receipt exactly — there is
no unallocated remainder, because customer advances are not modelled.

`accounting_settings` also gained **`trade_receivables_account_id`**, seeded to 1210, so no AR
action contains an account code.

### 1.30 `cash_bank_accounts`

| Column | Type | Notes |
| --- | --- | --- |
| `account_id` | FK → `accounts.id`, **unique** | One configuration per ledger account — decision **C-1** |
| `account_kind` | string(20) | `CASH` or `BANK` |
| `display_name` | string(150) | What people call it |
| `bank_name` / `branch_name` / `account_number` | string, nullable | Meaningful for a BANK only |
| `notes` | string(500), nullable | |
| `is_active` | boolean | Deactivated, never deleted |

**No balance column, and there never will be one.** What is in the till or the bank is derived
from posted journal lines. This table is configuration: it says which ledger accounts are real
money, so every module that moves money consults one list.

Seeded with `1110 → CASH` and `1120 → BANK`. Nothing else, and no balances.

### 1.31 `expense_vouchers` and `expense_voucher_lines`

`expense_vouchers`

| Column | Type | Notes |
| --- | --- | --- |
| `voucher_number` | string(50), **unique** | `EXP-YYYYMMDD-000001` |
| `voucher_date` | date | |
| `fiscal_period_id` | FK, nullable | Resolved at posting |
| `cash_bank_account_id` | FK → **`cash_bank_accounts.id`** | Not to `accounts` — decision **C-2** |
| `supplier_id` | FK, nullable | **Reporting only.** Raises no payable |
| `payee_name` | string(150), nullable | When the payee is not a supplier |
| `reference` / `description` | string, nullable | |
| `status` | string(30) | DRAFT / POSTED / REVERSED / CANCELLED |
| `journal_entry_id` / `reversal_journal_entry_id` | FK, nullable | |
| audit columns | | created / posted / reversed, each with user and timestamp |

**No total column.** The total is the sum of the lines, derived every time.

`expense_voucher_lines` — `expense_voucher_id` + `expense_account_id` +
`description` + `amount decimal(20,6)`. The account is restricted to ordinary EXPENSE accounts;
there are **no tax lines**.

### 1.32 `fund_transfers`

| Column | Type | Notes |
| --- | --- | --- |
| `transfer_number` | string(50), **unique** | `TRF-YYYYMMDD-000001` |
| `transfer_date` | date | |
| `fiscal_period_id` | FK, nullable | Resolved at posting |
| `from_cash_bank_account_id` | FK → `cash_bank_accounts.id` | |
| `to_cash_bank_account_id` | FK → `cash_bank_accounts.id` | Must differ from the source |
| `amount` | **`decimal(20,6)`** | Used twice in the journal, so the legs cannot disagree |
| `reference` / `description` | string, nullable | |
| `status` | string(30) | DRAFT / POSTED / REVERSED / CANCELLED |
| `journal_entry_id` / `reversal_journal_entry_id` | FK, nullable | |

There is no edit screen and no update route: a transfer is three facts, so an incorrect draft is
cancelled and replaced, and an incorrect posted one is reversed.

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

### S-1 — cost of goods sold always follows the PRIMARY quantity

Even on a line priced per KG, stock leaves in the primary unit, so cost is
`primary quantity × inventory unit cost`. Revenue follows the pricing basis, cost follows
the stock movement. A consequence worth knowing when reading margins: gross margin on a
weight-priced shell line depends on the NOS↔KG ratio of that specific delivery, because no
fixed conversion exists.

### S-2 — a lot must be chosen whenever lotted stock exists

`lot_id` is required if the product holds any lot with positive stock in the warehouse;
`null` is accepted only where no lot does. This prevents the system silently assuming a lot,
and avoids the confusing "unlotted bucket is empty" error that a naive null would produce.
Since every goods receipt creates a lot, purchased stock is always lotted in practice.

`TBD` — whether an automatic allocation strategy (FIFO/FEFO across lots) should be offered
later as an explicit user choice.

### P-3 — a production order links FOUR inventory transactions, not two

The ledger records one transaction type per transaction, and production involves two:
`PRODUCTION_CONSUMPTION` for the input and `PRODUCTION_OUTPUT` for the outputs. Forcing
both into a single transaction would mislabel half the lines, so the order carries a link
to each, plus a reversal link for each. All four are written inside one database
transaction, so atomicity is unchanged.

### P-4 — output quantities and costs are never accepted from a request

Outputs come from the recipe ratio; the consumed cost comes from the ledger. The form
request binds neither, and a test submits bogus output quantities and costs to prove they
are ignored. This keeps the 1:1:1 rule and the valuation impossible to subvert from the UI.

### P-5 — the 85 / 15 allocation is configuration, and provisional

Stored in `production_recipe_outputs`, validated to total exactly 100%, and referenced only
through the recipe. The factory has not finalised its joint-product costing policy; when it
does, only the seeded numbers change.

`TBD` — the permanent joint-product costing method (market value / NRV / other), and
whether allocation should vary by period or by product grade.

### P-6 — "no fixed husk-to-KG conversion" is enforced by a NULL, not by a convention

`production_recipe_outputs.output_ratio` is nullable, and every husk chipping output stores
`NULL`. A nullable column says something a missing feature cannot: *no ratio exists here*.
Deriving a chip weight from a husk count is therefore impossible rather than merely
discouraged — there is no number to derive it from, in the seeder, the model or the posting
action. `ProductionType::hasOperatorEnteredOutputs()` reads the same fact from the enum.

The alternative — seeding a placeholder ratio and ignoring it — was rejected: a stored
number invites use, and one careless multiplication would put a fabricated weight into the
ledger.

### P-7 — a grade that produced nothing takes no value, and its share is redistributed

Inventory value must sit on stock. A grade with zero quantity has none, so giving it a share
would strand value on a line with no lot behind it. Instead the producing grades'
percentages are renormalised to 100% (7C 30 → 42.857143% when 9C produces nothing), and the
**share actually applied** is stored on the line — so `allocated cost = consumed value ×
stored share` holds for every row, including the zero one, and a historic run can be
explained years later.

All of this lives in `App\Services\Production\ProductionCostAllocator`, used by **all three**
production types, so value conservation is implemented exactly once.

### P-8 — chipping waste is recorded without asserting what it means

`waste_quantity` + `waste_uom_id` are **both optional**, and the unit is chosen from the UOM
master rather than assumed. The factory has not confirmed whether waste is weighed, counted
or estimated, and inventing a unit to satisfy a column would put a physically meaningless
number into a business record.

Waste creates no inventory line, no lot, no product (**there is no `DUST` product**) and
takes no cost — the whole consumed value stays with the saleable grades.

`TBD` — the measurement basis, and whether waste should later carry an allocated cost.

### P-9 — one recipe may mix derived and measured outputs

Dehusking derives every output; husk chipping measures every one. Copra does both at once —
the shell count follows the coconuts processed 1:1, while the copra weight and the shell
weight are measured.

Rather than special-casing `RAW-SHELL` in business logic, the distinction was pushed into
the data that already existed. `output_ratio` is **per output** and nullable, so
`ProductionRecipeOutput::derivesQuantity()` answers the question for one output; the
type-level `ProductionType::hasFixedOutputRatio()` remains only a coarse summary and is
**false** for copra. One new boolean, `requires_secondary_quantity`, says an output must
also be weighed.

That is the whole schema change. The posting action never asks "is this product
`RAW-SHELL`?" — it asks the recipe — so product codes stay in `ProductionRecipeSeeder`,
where they have always lived.

### P-10 — raw shell is valued by its count; the per-KG figure is informational

The inventory engine values a product in its **primary** unit, and raw shell's primary unit
is `NOS`. So the allocated shell cost is divided by the shell **count**:

```
Rs.25,000 ÷ 1,000 NOS = Rs.25.000000 / NOS     ← the stored inventory cost
Rs.25,000 ÷   420 KG  = Rs.59.523810 / kg      ← display only, labelled informational
```

The per-kilogram figure is shown because the factory thinks about shell by weight, but it is
never stored and never used as a weighted average. Treating it as authoritative would
silently contradict how the engine costs the product — and because shell weight varies per
batch, it would also drift for reasons that have nothing to do with cost.

A **process-level** rule sits on top of decision **I-2**: for copra the shell weight is
*required* and must be positive, even though the engine treats secondary quantity as
informational in general. A shell lot with no weight is operationally useless, so the
requirement is enforced in `CopraRequest` and again in `PostCopraProduction` — and it
changes nothing about how stock is valued.

`TBD` — whether drying / moisture / process loss should be captured, and on what basis. No
waste product, no mass-balance equation and no loss quantity exist in version 1.

### I-4 — `total_cost` is authoritative; `unit_cost` merely represents it

An inventory line's `total_cost` is the monetary value of the movement. `unit_cost` is its
rounded per-unit representation, and **`total_cost = quantity × unit_cost` is not an
invariant**: a unit cost at six decimals cannot always reproduce a total derived the other
way round (Rs.16,000 over 280 KG stores Rs.57.142857, which re-multiplies to
Rs.15,999.999960).

Reconstructing value from the unit cost eroded inventory by millionths of a rupee on every
production run. The alternative — widening `unit_cost` — only moves the rounding point; the
totals would still not reconcile, because the exact figure the business calculated is a
*line total*, not a rate. So the line total is stored as calculated and the rate is derived
from it, not the reverse.

What is enforced instead, on every line carrying a supplied total:

```
quantity > 0,  total_cost >= 0,  unit_cost >= 0
unit_cost == round(total_cost / quantity, 6)
|total_cost − quantity × unit_cost| <= quantity × 0.0000005
```

Only trusted domain services may supply a total, through
`PendingInventoryLine::withAuthoritativeCost()`. `fromArray()` — the request-shaped path —
has no total-cost key, so no payload can set the monetary value of a stock movement, and an
OUT line still refuses a caller-supplied price. **No migration was needed:** `total_cost`
already existed as `decimal(20,6)` and no posted history was rewritten.

Two consequences are rules in their own right (`INVENTORY_RULES.md` §8.2-8.3): an issue that
takes the last of a product takes its entire remaining value, leaving zero quantity **and**
zero value; and a transfer's two legs share one total, so it is exactly value-neutral.

### I-3 — the weighted average is company-wide, not per warehouse

Version 1 maintains one average per product across all warehouses. This is what makes a
transfer value-neutral: both legs are costed at the same average, so moving stock between
warehouses changes neither company value nor the average. The `PostInventoryTransaction`
lock is taken on the **product** row for exactly this reason.

### A-1 — account balances are derived, never stored

There is no balance column on `accounts`, for the same reason there is no stock column on
`products`: a stored total has no history. When it drifts there is no way to discover when
or why, and no way to rebuild it. `AccountingBalanceService` computes every figure from
posted journal lines on request, and the general ledger's running balance is calculated per
view rather than persisted.

Only POSTED and REVERSED entries count. A REVERSED entry's own lines remain posted — the
reversing entry cancels them — because removing them would rewrite history.

### A-2 — debit and credit are separate positive columns, not one signed amount

A line stores `debit` **or** `credit`, both positive. The alternative — one signed column —
makes direction a convention someone has to remember, and makes an unbalanced entry a sign
error rather than a total that does not match. Separate columns are also what a trial
balance reads directly, with no interpretation step.

### A-3 — `source_type` exists before anything generates journals

The column is nullable and unused by manual entries, but it is what distinguishes a
**manual** journal from a **system** one — and that distinction is already load-bearing: a
manual entry may not touch a control account, a generated one may. It also means the later
AP/AR/inventory integrations need no schema change to name the document they came from.

### A-4 — `COST_OF_SALES` is an account type, not an expense subtype

Gross profit (revenue less cost of sales, before operating expenses) is already first-class
in this system. As a subtype, every report would need a magic value to split the P&L
correctly. Both post on the DEBIT side, so a distinct case costs nothing and removes a class
of misreporting. See `ACCOUNTING_RULES.md` §6.

### A-5 — the accounting cutover is a date, and it is frozen once used

Operational accounting is switched on by setting one nullable date. Before it, and while it
is NULL, the operational modules behave exactly as they did before Finance existed. That is
what allows accounting to start against a system already holding operational history: nothing
is backfilled and nothing already posted is reinterpreted.

**No seeder sets it.** A seeded cutover would silently decide that months of existing history
either should or should not have been accounted for — a decision only an accountant can make.

**Once any journal carries a `source_type`, the date can no longer change.** Moving it later
orphans documents already accounted for; moving it earlier leaves a gap of documents that
should have been and never will be. Neither is detectable afterwards by reading the books, so
the change is refused rather than reasoned about. A manual journal does not lock it — manual
entries are independent of the cutover.

### A-6 — a product reaches the ledger only through its mapping

`product_account_mappings` is the single join between the product master and the chart of
accounts. No posting action contains an account code, so renumbering the chart is a data
change.

An **unmapped product is refused**, not defaulted. A default would post real money to a
plausible-looking account and keep doing it until somebody noticed; a refusal is visible
immediately and corrupts nothing. The hand-created QA product is deliberately left unmapped
and shows as such in Accounting Setup.

The mapping is also what grants access to control accounts: the journal it produces names its
source document, and a sourced journal may post where a manual one may not (decision **A-3**).

### P-11 — a supplier's invoice number is unique per supplier, not globally

Two suppliers both numbering their invoices "001" is ordinary; a global constraint would
reject the second for no reason. The uniqueness is therefore `supplier_id` +
`supplier_invoice_number_normalised`, where the normalised form ignores case and inner
whitespace so "inv-001", "INV-001 " and "INV  001" are recognised as one invoice.

`SupplierInvoice::normaliseNumber()` is the single implementation, used by the model's
setter **and** by the validation rule — otherwise a value that passed validation could still
violate the index, which is exactly what happened before they were unified.

### P-12 — accounts payable balances are derived, never stored

No supplier has a payable column and no invoice has an outstanding column. An invoice's
outstanding is its total less the allocations of payments that are still POSTED, computed on
request — the same rule as ledger-derived stock and journal-derived account balances.

It is also what makes reversal clean. A reversed payment's allocations are **not deleted**:
they stop counting because the payment is no longer posted, the invoice's balance returns on
its own, and the whole history of what was paid and then unpaid remains visible.

The invoice's PARTIALLY_PAID / PAID status is likewise derived — three names for one
underlying fact, recalculated by `RefreshSupplierInvoiceStatus` whenever a payment posts or
reverses, never typed.

### P-13 — one goods receipt, several supplier invoices

A receipt's landed cost commonly comes from three parties — the goods supplier, the
transporter and the loading contractor — so a receipt accrues one GRNI figure and is cleared
by several invoices, from several suppliers, in several amounts. Matching is therefore
per-amount and partial, and **the invoice supplier is deliberately not required to match the
receipt supplier**.

The accrual itself is read from the receipt's own posted journal rather than recalculated, so
the reconciliation always agrees with the books. Receipts are locked `FOR UPDATE` while a
match is validated, so concurrent invoices cannot both consume the same remainder.

The consequence is a pair of mutual blocks: an invoice cannot be reversed while a posted
payment stands against it, and a receipt cannot be reversed once an invoice has matched it.
Either alone would leave the ledger describing something that never happened.

### S-3 — receivable balances are derived, never stored

**Decision:** neither `sales_invoices` nor `customers` carries a balance, a total or a paid
amount. An invoice's total is the sum of its lines; its outstanding is that total less the
allocations of receipts that are still posted; a customer's outstanding is the sum across its
invoices.

**Why:** the same reason stock has no `current_stock` column and an account has no balance
column. A stored balance is a second version of the truth that drifts the moment anything is
reversed, and the reversal of a receipt is precisely the case where a stored figure would be
left stale. Deriving it makes the critical invariant — Trade Receivables GL balance == total
invoice outstanding — a consequence of the design rather than something to maintain.

The status (POSTED / PARTIALLY_PAID / PAID) *is* stored, because it is a document state a user
acts on, but it is only ever written by `RefreshSalesInvoiceStatus` from the derived balance —
never typed, and never trusted as arithmetic.

### S-4 — a sales invoice bills a delivery line, and never recalculates its value

**Decision:** `sales_invoice_lines.delivery_line_id` is mandatory, and the billable amount is
bounded by `delivery_lines.sales_value` less what has already been invoiced against it. AR
never recomputes `quantity × unit_price`.

**Why:** two reasons, one structural and one arithmetic.

Structurally, an invoice that could bill anything would be a way to recognise revenue for goods
that never left the factory. Tying every line to a delivery line means revenue can only ever
follow a physical issue, and the unbilled-deliveries report is then a complete statement of
what is still to bill.

Arithmetically, `sales_value` was computed once when the delivery posted, honouring
`pricing_basis`. On a line priced per KG for a dual-unit product — raw shell — the priced
quantity is the secondary one. Re-deriving the value in AR would require knowing how many KG a
NOS represents, and **there is no such conversion, by deliberate design** (see **P-10**). The
delivery already decided the number; the invoice bills it unchanged.

This makes AR deliberately asymmetric with AP, where the accrual *is* read back from the goods
receipt's journal. The reason is simply that a delivery's journal holds cost of sales only — it
recognises no revenue — so the journal has no sales figure to read.

### C-1 — one configuration per ledger account, and the account cannot be repointed

**Decision:** `cash_bank_accounts.account_id` is unique, and the underlying account is absent
from the update request, so it can never be changed after configuration.

**Why:** two configurations over one account would be two names for the same money, and every
report that rolled them up would double-count. Repointing an existing configuration is worse
still: it would silently rewrite the meaning of every voucher and transfer already recorded
against it, with nothing in the history to show that the meaning had changed. The remedy is to
deactivate one configuration and create another, which leaves both on the record.

### C-2 — cash documents reference the CONFIGURATION, not the account

**Decision:** `expense_vouchers.cash_bank_account_id` and both of `fund_transfers`' account
columns are foreign keys to `cash_bank_accounts`, not to `accounts`.

**Why:** CLAUDE.md's rule that "the database is the last line of defence". If these columns
pointed at `accounts`, the only thing stopping a voucher from crediting Trade Receivables — or
an inventory control account — would be application validation. Pointing them at the
configuration table makes it a **structural** guarantee: the database itself cannot hold a
voucher paid out of an account nobody configured as a source of money.

The trade-off is one extra join to reach the ledger account, which the posting actions do once.

Supplier payments and customer receipts keep their existing `accounts` foreign keys, because
changing them would rewrite posted history; they are constrained by `AccountingPolicy` instead.
That difference is deliberate and is the reason the policy check exists as well as the
constraint.

### C-3 — cash and bank balances are derived, exactly like everything else

**Decision:** no balance column on `cash_bank_accounts`, on `accounts`, or anywhere else. The
cash/bank book and the account summary both derive from posted journal lines on every request,
and the running balance is never persisted.

**Why:** the same reason stock has no `current_stock` column. A stored balance is a second
version of the truth that drifts the moment anything is reversed, and a drifted one cannot be
explained after the fact. Deriving it makes the invariant — book closing balance == general
ledger balance — a **consequence of the design** rather than something to maintain, because
both figures read the same rows.

It also means the book needs no knowledge of what posted each line. A supplier payment, a
customer receipt, an expense voucher, a fund transfer and a manual journal all appear because
each wrote a line; a future module that moves money will appear with no change to the report.

### R-1 — reports add no tables and store no balances

**Decision:** Finance Phase 6 added **no migration**. The profit and loss statement, balance
sheet, inventory valuation, inventory-to-GL reconciliation, cash/bank position, product
profitability and dashboard are all read models computed per request from existing tables.
No summary, snapshot or cached-balance table was created for convenience.

**Why:** the same reason there is no `current_stock` and no account balance column. A stored
statement figure is a second version of the truth that drifts the moment anything is posted
late or reversed. Computing it means the balance sheet equation and the four reconciliation
invariants are consequences of the design, not things to maintain. If performance ever
requires a cache, it must be documented as non-authoritative and rebuildable.

### R-2 — Current Earnings has no column and no account

**Decision:** unclosed profit is shown on the balance sheet as a computed **Current Earnings**
line. It is not posted to `3200 Retained Earnings` or any other account, and no closing entry
is generated. The retained-earnings account is located through
`config('accounting.retained_earnings_code')`, never a literal in a report.

**Why:** year-end closing is not implemented, and `fiscal_periods` has no year grouping, so
there is no recorded financial-year boundary to close at. Current Earnings therefore
accumulates from the beginning of recorded history — exactly right while nothing has ever been
closed. When year-end closing is designed it must add the year boundary, and Current Earnings
must then start from the last close.

### R-3 — inventory reconciliation groups by GL account, not by product

**Decision:** inventory-ledger value is grouped by each product's mapped
`product_account_mappings.inventory_account_id` (`Account::mappedProducts()`), then compared
with that account's general-ledger balance.

**Why:** several products share one inventory account — the three husk-chip grades all post to
Husk Chips Inventory — and the general ledger only knows the account. Comparing per product
would need the ledger to be split in a way it never was.

### U-1 — roles and permissions are normalised, and permissions are code

**Decision:** four tables. `roles` (unique uppercase `code`, `name`, `description`,
`is_active`), `permissions` (unique `code`, `name`, `module`), `permission_role` and `role_user`,
each pivot with a composite primary key so a pairing cannot be stored twice. `role_user.role_id`
restricts deletion, so a role still held by someone cannot vanish. There is no role column on
`users`: a user may hold several roles.

**Why:** authorization must depend on permissions, not role names, and permissions must be ones
the code actually checks. The catalogue therefore lives in `App\Enums\Permission`; the table is
its mirror, written only by `RolesAndPermissionsSeeder`, so roles can use foreign keys.

### U-2 — users are deactivated, and the database refuses to delete one with history

**Decision:** `users.is_active` (default true). Every audit foreign key to `users` — `created_by`,
`approved_by`, `posted_by`, `reversed_by`, `closed_by`, `updated_by` — is `ON DELETE RESTRICT`.
The finance tables were created with `SET NULL` and were changed by a forward migration that
touched only the constraints.

**Why:** `SET NULL` meant deleting a user would silently blank "who posted this" on every journal,
invoice, payment, voucher and transfer — audit history lost with no trace. Deactivation keeps the
name on every record and withdraws all access at once.

### API-1 — Sanctum personal access tokens, hashed, one per device

**Decision:** `personal_access_tokens` (Sanctum's own migration). Each sign-in creates one row
named after the device, with a SHA-256 hash of the token (the plain value is shown once), the
single ability `mobile-api`, and `expires_at` = issue time + `config('api.token_lifetime_days')`
(90 days). No other table changed for the API, and no table was migrated to UUIDs: v1 exposes
the existing numeric IDs.

**Why:** the official, hashed token store rather than an invented one. Per-device rows let a
user sign out one phone without the others. Permissions are deliberately not stored on the
token — roles are read on every request, so a role change or deactivation takes effect at once.

### T-1 — stored moments are UTC; the business calendar is Asia/Colombo

**Decision:** `config('app.timezone')` stays `UTC`, so every stored timestamp keeps its meaning.
A separate `config('app.business_timezone')` (`Asia/Colombo`) decides calendar questions — today's
date, document-number date stamps, overdue and aging, the default date forms suggest — and how
stored moments are displayed, all through `App\Support\BusinessClock`. Stock-ledger
`transaction_date` is a calendar date: documents pass their own date, and reversals / transfers
now default to the business date instead of the UTC moment.

**Why:** switching the application timezone would have reinterpreted every existing UTC row as
Sri Lankan wall-clock time, silently shifting posted history by 5½ hours. Separating "when did it
happen" (UTC) from "which business day is it" (Colombo) fixes the midnight-to-05:30 misdating with
no data change. `BusinessClock` must never be used to write a timestamp column.

### U-3 — `users.start_in_simple_mode` is a start-page preference, not a permission

**Decision:** a boolean, default false, set by an administrator. After signing in, the user is
sent to `/kiosk` instead of the dashboard (`App\Http\Responses\LoginResponse`). It grants no
access; every simple-mode job is still authorized by permissions.

### L-1 — `users.locale` is a display preference; stored text is always English

**Decision:** `users.locale` (nullable, `en` / `si`) chooses the screen language; NULL means the
system default. No other column changed. Every text saved into a business record is written in
English through `App\Support\RecordText`, independent of the screen language.

**Why:** a stored description that followed each poster's language would split one kind of record
across two languages, breaking reports, searches and audits. Translation belongs to display.

## 3. Not yet designed — required future shape

These tables are **not implemented**. The constraints below are confirmed decisions that
the future schema must satisfy.

| Entity | Confirmed requirement |
| --- | --- |
| Warehouse-scoped access | A future `role_warehouse` or `user_warehouse` scope; permissions are company-wide today |
| Approval limits | A future monetary limit per role or permission; not modelled |

## 4. Conventions

- Money and measurable quantities use **`decimal`**, never float. Quantities are
  `decimal(20,3)` and costs `decimal(20,6)`; all arithmetic runs through
  `App\Support\DecimalMath` (BCMath), never PHP floats.
- Foreign keys are declared at the database level.
- Business records are **not hard-deleted**; use `is_active`, or reversal for transactions.
