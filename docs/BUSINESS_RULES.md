# Business Rules — Coconut Factory ERP

> Only **confirmed** rules are stated as rules. Unconfirmed items are marked `TBD`.
> Do not invent business rules; document the uncertainty instead.

## 1. Whole Coconut

- **Primary UOM:** `NOS`
- May be **purchased** from suppliers.
- May be **sold directly** to customers.
- May be **sent to the dehusking process**.

## 2. Dehusking

A processing operation converting Whole Coconut into two outputs.

**Fixed relationship — 1 : 1 : 1**

```
Input:   N  Whole Coconut     (NOS)
Output:  N  Dehusked Coconut  (NOS)
         N  Raw Coconut Husk  (NOS)
```

**Worked example**

| Movement | Product | Quantity |
| --- | --- | --- |
| Decrease | Whole Coconut | 1,000 NOS |
| Increase | Dehusked Coconut | 1,000 NOS |
| Increase | Raw Coconut Husk | 1,000 NOS |

This is the **only** confirmed fixed-ratio conversion in the system.

- `TBD` — Can dehusking produce a partial or failed result (input consumed without full
  output)? Not confirmed; assume 1:1:1 until told otherwise.

## 3. Dehusked Coconut

- **Primary UOM:** `NOS`
- May be **sold directly**.
- May be **used for copra production**.

## 4. Raw Coconut Husk

- **Primary UOM:** `NOS`
- Enters inventory from **two sources**:
  1. Internal coconut dehusking
  2. Direct purchase from a supplier
- Is processed through **husk chipping**.

## 5. Husk Chipping

```
Input:   Raw Husk            (NOS)
Output:  7C                  (KG)
         9C                  (KG)
         10C                 (KG)
         waste / loss        (recorded, NOT stocked)
```

**Confirmed rules**

- **Actual production output weights must be entered by the operator.**
- **There is NO fixed conversion from husks (NOS) to KG.** The system must never
  calculate output weight from input quantity.
- **Waste / loss is recorded as production waste and does NOT create inventory.** ✅ DECIDED

**Waste treatment (Version 1)** — ✅ **DECIDED**

Chipping waste and loss are recorded against the production record as **production waste**.
They do **not** create an inventory item and do **not** appear in stock.

If the factory later begins selling dust or another by-product, that material may be
**promoted to a proper inventory product** at that point. **No `DUST` product is to be
created now.**

`TBD` — whether waste should later carry an allocated cost, and the trigger for promoting a
by-product to a stocked product.

## 6. Copra Production

```
Input:   Dehusked Coconut    (NOS)
Output:  Copra               (KG)
         Raw Coconut Shell   (Qty + KG)
```

**Confirmed rules**

- Raw Shell must record **both quantity (`NOS`) and weight (`KG`)**.
- **One coconut yields one shell quantity** — unless this rule is changed later.
- **There is NO fixed NOS-to-KG conversion for shell**, because shell weights vary.
  Actual weight must be captured.

**`TBD`**

- Copra output weight per coconut — variable, operator-entered. No yield standard confirmed.

## 7. Raw Coconut Shell

- **Primary UOM:** `NOS`, **Secondary UOM:** `KG`
- May be sold **by quantity (`NOS`)** *or* **by weight (`KG`)**. The user interface may
  label the count as "Qty"; the inventory unit is `NOS`.
- Inventory must preserve **both actual quantity and actual weight** where available.

## 8. Costing

- **Intended inventory costing method: Weighted Average Cost.**
- Production / joint-product cost allocation will later support a **configurable**
  allocation method, such as market value / NRV.
- **Do not implement detailed finance or cost allocation yet.**

## 9. Transaction integrity rules

| Rule | Detail |
| --- | --- |
| No silent modification | A posted business transaction is never edited in place |
| Reversal only | Posted transactions are reversed or cancelled per business rules |
| Audit history preserved | Audit history is never deleted |
| Configurable values | Business values that could change are never hard-coded |

## 10. Summary of confirmed conversions

| Process | Input | Output | Ratio |
| --- | --- | --- | --- |
| Dehusking | Whole Coconut (NOS) | Dehusked Coconut (NOS) + Raw Husk (NOS) | **Fixed 1:1:1** |
| Husk chipping | Raw Husk (NOS) | 7C, 9C, 10C (KG) + waste (not stocked) | **Operator-entered, no fixed ratio** |
| Copra production | Dehusked Coconut (NOS) | Copra (KG) + Raw Shell (NOS + KG) | **Shell count 1:1; all weights operator-entered** |

## 11. Purchasing — purchase order lifecycle

**A purchase order records an intention to buy. It never creates inventory.**

```
DRAFT ──approve──▶ APPROVED ──receive──▶ PARTIALLY_RECEIVED ──receive──▶ RECEIVED
  │                    │                         │
  └────cancel────▶ CANCELLED ◀──────cancel───────┘
```

| Rule | Detail |
| --- | --- |
| Free editing | **Only a `DRAFT`** may have its header or lines changed |
| Controlled document | Once `APPROVED`, the order may not be edited — only received against or cancelled |
| Approval requires lines | An order with no lines cannot be approved |
| Cancellation | Allowed from `DRAFT` or `APPROVED`. A **cancelled order can never be received against** |
| Partially received | Set automatically when some, but not all, ordered quantity has been received |
| Fully received | Set automatically when every line is fully received; no further quantity may be received |
| Over-receipt | **Rejected.** A receipt may not exceed a line's outstanding quantity |
| History | Approved and received history is never silently modified |

`received_quantity` on a line is a **progress counter**, not stock. Stock lives only in the
inventory ledger. The order status is always **recomputed from the line quantities** after a
receipt is posted or reversed, so it can never drift.

**Numbering:** `PO-YYYYMMDD-000001` — a daily sequence encoding no supplier or product
meaning.

**Purchasable products are not hard-coded.** Any active product may be ordered; Whole
Coconut and Raw Husk are simply the first ones the factory buys.

## 12. Purchasing — goods receipt lifecycle

```
DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
  │
  └──cancel──▶ CANCELLED
```

**`REVERSED` and `CANCELLED` are deliberately distinct.** `CANCELLED` means the receipt
never reached inventory — it was abandoned as a draft. `REVERSED` means stock really did
move and was subsequently undone by an opposite ledger transaction. Collapsing the two
would lose that audit distinction: you could no longer tell, from the status alone, whether
a receipt ever affected stock.

| Rule | Detail |
| --- | --- |
| Draft | Creates **no inventory at all**. Freely editable |
| Posted | Writes a `PURCHASE_RECEIPT` to the ledger. **Immutable** thereafter |
| Correction | A posted receipt is corrected by **reversal**, never by editing |
| Cancelled | A draft abandoned before posting. Creates no stock. **Only a draft may be cancelled** — a posted receipt has real stock behind it and must be reversed |
| Reversed | A posted receipt undone by an opposite ledger transaction |
| Double posting | **Prohibited** — enforced under a row lock, and by refusing to post a receipt that already holds a ledger link |

**Two ways to receive**, both fully supported:

1. **Against an approved purchase order** — lines may be pre-filled from the order's
   outstanding quantities, and posting updates the order.
2. **Direct, with no purchase order** — because factory purchases often happen on the spot.
   Supplier, warehouse, product, quantity and purchase rate are all required.

**Posting validates, in order:** receipt is `DRAFT` → supplier active → warehouse active →
product active for incoming stock (see `INVENTORY_RULES.md` §14) → quantities greater than
zero → purchase order open and matching → no over-receipt.

**Everything happens in one database transaction.** If any step fails there is no inventory
movement, no lot, no purchase-order update and no partially posted receipt.

**Reversal of a goods receipt:**

- reverses its `PURCHASE_RECEIPT` through the inventory engine, reusing each original
  line's unit cost (`INVENTORY_RULES.md` I-1);
- restores the purchase order's received quantities and recomputes its status;
- marks the receipt `CANCELLED` with audit references to the reversal, who did it and when;
- **never deletes the original receipt or its lines**;
- is **refused** if already reversed, or if the received stock has since been issued onward
  such that reversing would drive inventory negative;
- **reuses each original line's unit cost**, so it removes exactly the value the receipt
  added — never the current weighted average, which may have moved in the meantime.

**Numbering:** `GRN-YYYYMMDD-000001`.

## 13. Purchasing — landed cost

The cost posted to inventory is the **landed** cost, not the bare purchase price.

```
line material value   = quantity × unit purchase price
total landed cost     = transport + loading + other
line landed share     = total landed cost × (line material value / total material value)
line total cost       = line material value + line landed share
final unit cost       = line total cost / quantity          ← posted to inventory
```

**Worked example — single product**

| | |
| --- | --- |
| 10,000 Coconut @ Rs.100 | Rs.1,000,000 material value |
| Transport | Rs.20,000 |
| Loading | Rs.5,000 |
| Other | Rs.0 |
| **Total landed cost** | **Rs.1,025,000** |
| **Final unit cost** | **Rs.102.50 per coconut** |

Rs.102.50 — not Rs.100 — is what reaches the inventory ledger and the weighted average.

**Allocation is by material value, never by quantity.** Lines on one receipt may use
different units of measure, so 1,000 NOS of coconut and 400 KG of husk cannot meaningfully
be compared by quantity. Value is the only common basis.

**Worked example — allocation across two lines**

| Line | Quantity | Price | Material value | Share | Landed share | Final unit cost |
| --- | --- | --- | --- | --- | --- | --- |
| Coconut | 1,000 | 100 | 100,000 | 80% | 8,000 | 108.00 |
| Raw Husk | 1,000 | 25 | 25,000 | 20% | 2,000 | 27.00 |
| **Total** | | | **125,000** | 100% | **10,000** | |

**Rounding — deterministic residual handling.** Shares are computed to six decimals for
every line except the last, which is assigned `total landed cost − sum of the others`. The
allocations therefore sum **exactly** to the header total, always, whatever the split.

Worked example — Rs.100 landed across three equal-value lines:

| Line | Share |
| --- | --- |
| 1 | 33.333333 |
| 2 | 33.333333 |
| 3 | **33.333334** ← absorbs the residual |
| **Total** | **100.000000** ✅ exact |

**A separate, bounded residue exists at the inventory layer.** The ledger stores a *unit*
cost to six decimals, and a unit cost cannot always reproduce its own line total:
`10 × 13.333333 = 133.333330`, not `133.333333`. The shortfall is at most half a unit-cost
tick per line — `quantity × 0.0000005` — and is inherent to posting unit costs rather than
line totals, not a defect in the allocation. The allocation requirement above is met
exactly; this is a different and much smaller effect, and is covered by a test.

**Zero material value:** if the lines carry no material value but landed costs exist,
allocation by value is undefined and **posting is refused** rather than guessed at.

**Users never type a calculated cost.** `material_cost`, `allocated_landed_cost` and
`final_unit_cost` are computed by the server; the screens show them read-only, and the form
request does not accept them.

## 14. Purchasing — lot creation

- Posting a goods receipt creates **one inventory lot per line**, unless the line
  deliberately references an existing lot.
- The internal lot number comes from the existing lot service: `LOT-YYYYMMDD-000001`.
- The **supplier's own batch reference is stored separately** in `supplier_lot_number`, so
  external numbering never collides with or replaces the internal identifier.
- A lot's product **must match** the receipt line's product.
- The lot records the receipt as its origin (`source_type` / `source_id`), giving full
  traceability from stock back to the delivery — which is what enables lot traceability for
  coconut and raw husk purchases.

## 15. Sales — sales order lifecycle

**A sales order records an intention to sell. It reserves nothing and creates no inventory
movement.**

```
DRAFT ──approve──▶ APPROVED ──deliver──▶ PARTIALLY_DELIVERED ──deliver──▶ DELIVERED
  │                    │                           │
  └────cancel────▶ CANCELLED ◀────────cancel───────┘
```

| Rule | Detail |
| --- | --- |
| Free editing | **Only a `DRAFT`** may have its header or lines changed |
| Controlled document | Once `APPROVED`, the commercial values — quantities and prices — are preserved |
| Approval requires lines | An order with no lines cannot be approved |
| Cancellation | Allowed from `DRAFT` or `APPROVED`. A **cancelled order can never be delivered against** |
| Partially delivered | Set automatically when some, but not all, ordered quantity has been delivered |
| Fully delivered | Set automatically when every line is complete; no further quantity may be delivered |
| Over-delivery | **Rejected.** A delivery may not exceed a line's outstanding quantity |
| History | Orders are never deleted |

`delivered_quantity` on a line is a **progress counter**, not stock. The order status is
always **recomputed from the line quantities** after a delivery is posted or reversed, so it
can never drift.

**Numbering:** `SO-YYYYMMDD-000001`, from the same daily sequence as every other document.

**Sellable products are not hard-coded.** Any active product with available stock may be
sold.

## 16. Sales — delivery lifecycle

```
DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
  │
  └──cancel──▶ CANCELLED
```

| Rule | Detail |
| --- | --- |
| Draft | Creates **no inventory movement at all**. Freely editable |
| Posted | Issues `SALES_ISSUE` movements. **Immutable** thereafter |
| Correction | A posted delivery is corrected by **reversal**, never by editing |
| Cancelled | A draft abandoned before posting; creates no stock. Only a draft may be cancelled |
| Reversed | A posted delivery undone by an opposite ledger transaction |
| Double posting / double reversal | **Prohibited**, enforced under a row lock |

**Two ways to deliver**, both fully supported:

1. **Against an approved sales order** — lines may be pre-filled from its outstanding
   quantities, and posting updates the order.
2. **Direct, with no sales order** — requires an active customer, active warehouse, product,
   quantity and selling price.

**Posting validates, in order:** delivery is `DRAFT` → customer active → warehouse active →
product exists → quantity greater than zero → pricing basis valid for the product → lot
belongs to the product and is explicitly chosen where required → sales order open and
matching → no over-delivery. The inventory engine then independently enforces stock
availability, including per-lot.

**Everything happens in one database transaction.** If any step fails there is no inventory
movement, no sales order update and no partially posted delivery.

### Cost of goods sold

The seller never states what stock cost. Posting hands the lines to the inventory engine,
which values the `SALES_ISSUE` at the weighted average in force; the delivery then **reads
back** the cost the ledger actually used:

```
sales_value         = selling quantity x unit_price
inventory_unit_cost = the weighted average the LEDGER used
cost_of_goods_sold  = PRIMARY quantity x inventory_unit_cost
gross_profit        = sales_value - cost_of_goods_sold
gross_margin %      = gross_profit / sales_value x 100      (0 when sales value is 0)
```

**Worked example** — 2,000 Whole Coconut sold at Rs.130 against a weighted average of
Rs.102.50:

| | |
| --- | --- |
| Revenue | 2,000 × 130 = **Rs.260,000** |
| Inventory OUT | 2,000 NOS |
| Cost of goods sold | 2,000 × 102.50 = **Rs.205,000** |
| Gross profit | **Rs.55,000** |
| Gross margin | **21.15%** |

**Selling price never affects the weighted average cost.** The two are entirely separate:
the engine refuses a caller-supplied price on an outgoing line, so a high selling price
cannot inflate inventory valuation.

**Reversal of a delivery:**

- reverses the `SALES_ISSUE` through the inventory engine, which reuses each original line's
  unit cost, so stock returns at exactly the value it left at — not at whatever the average
  has drifted to since;
- restores the sales order's delivered quantities and recomputes its status;
- marks the delivery `REVERSED` with audit references;
- **never deletes the original delivery or its lines**;
- is refused if already reversed, or if it would violate the engine's rules.

**Numbering:** `DN-YYYYMMDD-000001`.

### Lot selection

A lot must be **explicitly chosen** whenever the product holds any lotted stock in the
warehouse — the system never silently assumes one. `null` is accepted only where no lot
holds stock (unlotted opening balances or adjustments). Where exactly one lot is eligible
the UI preselects it, but posting still validates it.

Lot stock can never go negative: the engine checks the lot bucket separately from the
warehouse bucket, so a lot cannot be over-issued even when the warehouse holds other stock
of the same product.

## 17. Sales — dual-unit pricing (`pricing_basis`)

Raw Coconut Shell is stocked in `NOS` but may be sold by weight. A sales or delivery line
therefore carries a **pricing basis**:

| Basis | Revenue calculated from |
| --- | --- |
| `PRIMARY` | the stocked quantity, e.g. Rs/NOS |
| `SECONDARY` | the secondary quantity, e.g. Rs/KG |

**The priced quantity is derived from the basis — never typed independently.** It is always
one of the two quantities already recorded on the line.

**`SECONDARY` is permitted only where the PRODUCT has a secondary unit of measure.** This is
driven entirely by the product's units, never by a hard-coded product code, so any future
dual-UOM product gets the behaviour automatically and every single-UOM product is restricted
to `PRIMARY`. Choosing `SECONDARY` also requires a secondary quantity to be present.

**Worked example** — 500 shell at 208.750 KG, priced per KG at Rs.80:

```
sales_value = 208.750 × 80 = Rs.16,700      ← revenue follows the WEIGHT
inventory OUT = 500 NOS                      ← stock follows the COUNT
cost_of_goods_sold = 500 × unit cost         ← cost follows the COUNT
```

**Cost always follows the primary quantity**, because that is what leaves stock. A
consequence worth knowing: gross margin on a weight-priced line depends on the NOS↔KG ratio
of that particular delivery, since **no fixed conversion exists** — actual weights are
recorded because shell weights genuinely vary.

For Version 1 the **primary quantity remains authoritative** for negative-stock validation;
the secondary quantity is informational, exactly as in `INVENTORY_RULES.md` §7.

## 18. Production — dehusking

**Dehusking and husk chipping are implemented** (§19). Copra production is declared in
`ProductionType` so the architecture is shaped for it, but it is not postable: its shell
weight rules are unresolved. `ProductionType::isImplemented()` enforces that.

### The 1:1:1 rule

```
Input:   1,000 Whole Coconut   (NOS)
Output:  1,000 Dehusked Coconut (NOS)
         1,000 Raw Coconut Husk (NOS)
```

| Product | Movement |
| --- | --- |
| Whole Coconut | **−1,000** NOS |
| Dehusked Coconut | **+1,000** NOS |
| Raw Coconut Husk | **+1,000** NOS |

**Output quantities are derived, never typed.** The operator enters only the input
quantity; the recipe's `output_ratio` (1.0 for each output) produces the rest. The screens
show outputs read-only, and the form request does not accept them at all.

### Production order lifecycle

```
DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
  │
  └──cancel──▶ CANCELLED
```

| Rule | Detail |
| --- | --- |
| Draft | Consumes and produces **nothing**. Freely editable |
| Posted | Converts stock. **Immutable** thereafter |
| Correction | A posted run is corrected by **reversal**, never by editing |
| Cancelled | A draft abandoned before posting; only a draft may be cancelled |
| Reversed | A posted run undone by opposite ledger transactions |
| Double posting / double reversal | **Prohibited**, enforced under a row lock |

**Numbering:** `PRD-YYYYMMDD-000001`.

### Inventory posting

Posting writes **two** inventory transactions, because the ledger records one transaction
type per transaction:

| Transaction | Lines |
| --- | --- |
| `PRODUCTION_CONSUMPTION` | Whole Coconut **OUT**, from the selected lot |
| `PRODUCTION_OUTPUT` | Dehusked Coconut **IN** and Raw Husk **IN**, each into a new lot |

Both are written inside **one database transaction**. Any failure leaves no consumed stock,
no output stock, no lots and no posted order.

### Lot traceability

The input lot must be **explicitly selected** — without it the output could not be traced
back to the coconut it came from.

Posting creates a **new lot for each output**, each recording the production order as its
source and naming the consumed lot in its notes. The chain is therefore complete in both
directions:

```
Supplier → Coconut GRN → Coconut Lot → Dehusking run → Dehusked Lot
                                                      → Raw Husk Lot
```

### Value conservation — the important rule

Dehusking **increases the physical item count**: one coconut becomes two things. This must
never be read as inventory value doubling.

```
Before:  1,000 Coconut                    Rs.102,500
After:   1,000 Dehusked Coconut  85%      Rs. 87,125
       + 1,000 Raw Husk          15%      Rs. 15,375
                                          ──────────
                                          Rs.102,500   ← unchanged
```

Unit costs become Rs.87.125 and Rs.15.375 respectively.

**Quantity doubles across product types; money does not.** The consumed value is whatever
the ledger charged at the weighted average in force, read back after the consumption is
posted — never recomputed by the production code.

### Cost allocation — configurable, currently 85 / 15

Joint-product costing has **not been finalised by the factory**. Version 1 therefore uses a
**fixed percentage** split, stored as configuration in `production_recipes` /
`production_recipe_outputs`:

| Output | Share |
| --- | --- |
| Dehusked Coconut | **85%** |
| Raw Husk | **15%** |

**These numbers are provisional and are expected to change.** They live in the seeder and
nowhere else — changing the factory's costing policy is a data change, not a code change.

**Percentages must total exactly 100.** A recipe that does not is refused at posting time,
because it would create or destroy inventory value on every run.

**Rounding:** shares are computed to six decimals for every output except the last, which
receives `consumed value − everything already allocated`. The allocated costs therefore sum
**exactly** to the consumed value whatever the percentages are. (As elsewhere, the ledger
stores a *unit* cost at six decimals, so ledger value can differ by at most
`quantity × 0.0000005` per line — see §13.)

### No production expenses yet

Labour, electricity, machine, fuel and overhead costs are **deliberately excluded**. For
this phase, total input inventory value equals total output inventory value exactly. Those
costs arrive in a later costing phase, and a test asserts no such columns or tables exist.

### Reversal

Reversal undoes both halves, **outputs first**:

1. the `PRODUCTION_OUTPUT` is reversed, taking the dehusked coconut and husk back out of
   stock;
2. the `PRODUCTION_CONSUMPTION` is reversed, putting the whole coconut back.

Both reuse their original unit costs, so value returns exactly as it left.

**Reversal is refused if the output has already been consumed** — sold, or processed
onward — because the stock is no longer there to remove. Reversing the outputs first means
that rejection happens before any coconut is restored, and the whole operation rolls back
regardless. Produce 1,000, sell 500, attempt to reverse: **rejected**.

Double reversal is prohibited, and the original run is never deleted or altered.

---

## 19. Production — husk chipping

Husk chipping shares the whole production engine with dehusking — the same order, the same
two ledger transactions, the same reversal and cancellation actions, the same recipe tables.
There is **no second production engine**.

What differs is one thing, and it changes a lot: **the yields are measured, not derived.**

### The operator enters the actual weights

```
Input:   2,000 Raw Husk   (NOS)
Output:    150.500 kg  7C     ← weighed
           205.250 kg  9C     ← weighed
           281.750 kg 10C     ← weighed
           waste / loss       ← recorded, NOT stocked
```

**There is no fixed conversion from husks to kilograms, and there must never be one.**
Chipping yields vary with the husk itself, the moisture and the machine, so a standard
"1 husk = X kg" figure would be fiction — and fiction in a costing path becomes a wrong
stock value on every single run.

This is enforced structurally, not by convention. `production_recipe_outputs.output_ratio`
is **nullable**, and every husk chipping output has it set to `NULL`:

| Type | `output_ratio` | Meaning |
| --- | --- | --- |
| Dehusking | `1.000000` | Derive the output quantity from the input |
| Husk chipping | `NULL` | **No ratio exists.** The operator measures the output |

`ProductionType::hasFixedOutputRatio()` is false for chipping and
`hasOperatorEnteredOutputs()` is true. There is no number anywhere for a weight to be
derived from, so deriving one is impossible rather than merely discouraged.

**The two quantities are not comparable.** Husk is counted in `NOS`; chips are weighed in
`KG`. The system never converts between them, and no screen implies that it does.

### Which weights are accepted

| Rule | Detail |
| --- | --- |
| Zero for one grade | **Allowed.** A batch that produced no 9C records `0` for 9C |
| Zero for every grade | **Refused.** There would be nothing to carry the consumed value |
| Negative | **Refused**, in the form request and again at posting |
| A grade left out | **Refused.** An explicit `0` is a measurement; a missing line is an omission |
| A product outside the recipe | **Refused** |

### Waste is information only

Waste and loss are recorded on the production order as `waste_quantity` + `waste_uom_id`
and go no further. They create:

- **no inventory line** — nothing enters or leaves stock;
- **no lot**;
- **no product** — there is deliberately **no `DUST` product**;
- **no cost** — waste takes none of the consumed value.

Both fields are **optional**, because the factory has not confirmed how (or whether) waste
is measured. When it is recorded, the unit is chosen from the real UOM master rather than
invented, so no physically incorrect unit can be stored.

> `TBD-1` — the measurement basis for chipping waste (weight, husk count, or estimate), and
> whether it should later carry an allocated cost. Until the factory confirms this, the
> field records what was measured and asserts nothing about what it means.

### Value conservation

The consumed husk value is shared across the grades and **sums exactly**:

```
Consumed:  2,000 Raw Husk @ Rs.20.000000        Rs.40,000
Produced:    150 kg  7C    30%                  Rs.12,000   Rs.80.000000 / kg
             200 kg  9C    30%                  Rs.12,000   Rs.60.000000 / kg
             280 kg 10C    40%                  Rs.16,000   Rs.57.142857 / kg
                                                ─────────
                                                Rs.40,000   ← unchanged
```

The consumed value is read back from the ledger after the consumption posts — never
recomputed — so it is always the weighted average actually charged.

No labour, electricity, machine, packaging or overhead cost is added. Total input inventory
value equals total output inventory value exactly, and a test asserts that no such columns
or tables exist.

### Cost allocation — configurable, currently 30 / 30 / 40

| Output | Share |
| --- | --- |
| 7C | **30%** |
| 9C | **30%** |
| 10C | **40%** |

**These numbers are provisional and are expected to change.** They live in
`production_recipe_outputs.allocation_percent`, seeded by `ProductionRecipeSeeder`, and
nowhere else. Changing the factory's costing policy is a data change, not a code change.

**Percentages must total exactly 100**, or the recipe is refused at posting time.

### Zero-output redistribution

A grade that was not produced has no stock for value to sit on, so it must take none. The
shares of the grades that *were* produced are renormalised to total 100% again:

```
Configured:  7C 30    9C 30    10C 40
Produced:    7C ✓     9C 0     10C ✓

Applied:     7C = 30 / (30 + 40) = 42.857143%
             10C = 40 / (30 + 40) = 57.142857%
             9C  = nothing, and no lot is created for it
```

On Rs.40,000 consumed that is Rs.17,142.857143 to 7C and Rs.22,857.142857 to 10C — still
exactly Rs.40,000.

The **share actually applied** is what gets stored on the output line, so a historic run can
always be explained: `allocated cost = consumed value × stored share` holds for every line,
including the zero one.

**Rounding:** as everywhere, the last participating output receives
`consumed value − everything already allocated`, so the allocation sums exactly — and those
exact figures now reach the inventory ledger unchanged, because `total_cost` is
authoritative (`INVENTORY_RULES.md` §8.1). Ledger input value equals ledger output value to
the last decimal.

This logic lives in `App\Services\Production\ProductionCostAllocator`, which **both**
production types use, so value conservation is guaranteed in exactly one place.

### Lots and traceability

The raw husk lot must be **explicitly selected**. Posting creates a **new lot for each grade
that was actually produced** — and none for a grade that measured zero:

```
Supplier → Husk GRN → Raw Husk Lot ─┬─▶ Chipping run ─┬─▶ 7C Lot
Dehusking → Raw Husk Lot  ──────────┘                 ├─▶ 9C Lot
                                                      └─▶ 10C Lot
```

Each chip lot records the production order as its source and names the consumed husk lot in
its notes, so stock traces in both directions.

### Reversal

Reversal uses the **same** `ReverseProduction` action as dehusking: the `PRODUCTION_OUTPUT`
is reversed first, then the `PRODUCTION_CONSUMPTION`, both at their original unit costs.

**If any one grade has been consumed — sold or processed onward — the entire reversal is
refused.** There is no partial reversal: reversing the outputs first means the rejection
happens before any husk is restored, and the whole operation rolls back regardless. Sell
100 kg of the 7C and the run can no longer be reversed, even though the 9C and 10C are
untouched.

Double reversal is prohibited, and the original run is never deleted or altered.

## 20. Production — copra and raw coconut shell

Copra production shares the whole production engine with dehusking and husk chipping — the
same order, the same two ledger transactions, the same reversal and cancellation actions,
the same recipe tables and the same cost allocator. There is **no second production
engine**.

What is new is that its two outputs behave **differently from each other**. Dehusking
derives every output; chipping measures every output. Copra does both at once:

```
Input:   1,000 Dehusked Coconut  (NOS)   ← chosen lot, chosen warehouse

Output:    190.500 kg  Copra             ← MEASURED by the operator
         1,000     NOS Raw Shell         ← DERIVED, one shell per coconut
           420.750 kg  Raw Shell         ← MEASURED by the operator
```

### The shell count is derived; every weight is measured

**One dehusked coconut processed leaves one shell.** That is a confirmed factory rule, and
it is the only fixed relationship in this process. It lives in the recipe as
`output_ratio = 1.000000`, never in code.

**Copra weight is not derivable from a coconut count, and neither is shell weight.** Drying
loss, nut size and moisture all vary, so a standard "1 coconut = X kg" figure would be
fiction — and fiction in a costing path becomes a wrong stock value on every run. Both
weights are entered by the operator from the scale.

| Output | Primary quantity | Secondary quantity |
| --- | --- | --- |
| `COPRA` (KG) | **Measured.** `output_ratio` is `NULL` | none — copra has no secondary unit |
| `RAW-SHELL` (NOS) | **Derived.** `output_ratio` is `1.000000` | **Measured KG, and required** |

This is structural, not conventional. `output_ratio` is nullable *per output*, so one
recipe can hold both kinds:

- `ProductionRecipeOutput::derivesQuantity()` — "is there a ratio?" — is the authority for
  an individual output. `ProductionType::hasFixedOutputRatio()` is only a coarse type-level
  summary, and it is **false** for copra because not every output is derived.
- `production_recipe_outputs.requires_secondary_quantity` (added for copra) says an output
  must also be weighed.

Both are read from the recipe, so **no product code appears in the posting action, the form
request, the controller or any screen.** Product codes live only in
`ProductionRecipeSeeder`.

### Shell weight is required here, even though the engine treats it as informational

`docs/INVENTORY_RULES.md` says secondary quantity is informational: recorded where known,
never guarded against going negative, never convertible to the primary unit. That remains
true of the **engine**.

Copra production adds a **process-level** requirement on top of it: the shell weight must be
present and greater than zero, because the factory sells shell by weight and a shell lot
with no weight is operationally useless. This is enforced twice — in `CopraRequest` and
again in `PostCopraProduction` — and it changes nothing about how the engine values stock.

### Which quantities are accepted

| Rule | Detail |
| --- | --- |
| Copra KG | **Required, must be > 0** |
| Shell NOS | **Refused if submitted.** Sending a figure — even the correct one — is an error, not something to ignore. The server recalculates it from `input × ratio` regardless |
| Shell KG | **Required, must be > 0** |
| A weight on an output with no secondary unit | **Refused** |
| An output left out, or one outside the recipe | **Refused** |
| Any cost figure | **Never accepted.** Costs come from the ledger |

### Value conservation

The consumed coconut value is shared between copra and shell and **sums exactly**:

```
Consumed:  1,000 Dehusked Coconut @ Rs.100.000000     Rs.100,000
Produced:    200     kg  Copra       75%              Rs. 75,000   Rs.375.000000 / kg
           1,000    NOS  Raw Shell   25%              Rs. 25,000   Rs. 25.000000 / NOS
             420     kg  (measured weight)                         Rs. 59.523810 / kg  ← informational
                                                      ──────────
                                                      Rs.100,000   ← unchanged
```

And with the factory's own figures:

```
Consumed:  1,000 Dehusked Coconut @ Rs.87.125000      Rs. 87,125
Produced:    190.500 kg  Copra      75%               Rs. 65,343.750000
           1,000     NOS Raw Shell  25%               Rs. 21,781.250000
             420.750 kg  (measured weight)
                                                      ─────────────────
                                                      Rs. 87,125.000000
```

The consumed value is read back from the ledger after the consumption posts — never
recomputed — so it is always the weighted average actually charged.

No drying labour, fuel, electricity, machine or overhead cost is added. Total input
inventory value equals total output inventory value **exactly at stored precision**, and a
test asserts that no such columns or tables exist.

### Shell is valued by its count, not its weight

The inventory engine values raw shell in `NOS`, because that is its primary unit. So:

```
Shell inventory unit cost  =  allocated shell cost ÷ shell NOS   =  25,000 ÷ 1,000  =  Rs.25.000000 / NOS
```

The measured `KG` stays where it belongs — as the ledger's secondary quantity. A cost per
kilogram **may be displayed**, because the factory thinks about shell by weight:

```
Informational only  =  allocated shell cost ÷ measured KG  =  25,000 ÷ 420  =  Rs.59.523810 / kg
```

It is **labelled informational wherever it appears**, is never stored, and is never used as
a weighted average cost. Deriving a per-KG cost and treating it as authoritative would
silently contradict how the engine values the product.

### Cost allocation — configurable, currently 75 / 25

| Output | Share |
| --- | --- |
| Copra | **75%** |
| Raw Shell | **25%** |

**These numbers are provisional and are expected to change.** They live in
`production_recipe_outputs.allocation_percent`, seeded by `ProductionRecipeSeeder`, and
nowhere else — never in the posting action. Changing the factory's costing policy is a data
change, not a code change.

**Percentages must total exactly 100**, or the recipe is refused at posting time.

> `TBD-2` — the permanent joint-product costing method. 75 / 25 is a placeholder chosen so
> the process can run end to end; the intended replacement is a **market-value / NRV**
> method that shares the consumed value by what each output is actually worth. When that
> lands, it replaces the percentages in configuration — the posting action does not change.

**Rounding:** as everywhere, the last participating output receives
`consumed value − everything already allocated`, so the allocation sums exactly. The shared
`App\Services\Production\ProductionCostAllocator` does this for all three production types,
so value conservation is guaranteed in exactly one place — and because inventory
`total_cost` is authoritative (`INVENTORY_RULES.md` §8.1), those exact figures reach the
ledger unchanged even when the per-unit rate repeats.

### Moisture and process loss

Drying removes a large amount of water: 1,000 coconuts do not become 1,000 kg of anything.
For version 1 the system **does not model this at all**:

- **no waste inventory product** — nothing like `MOISTURE` or `WASTE` exists;
- **no mass-balance equation** between the coconut count and the output weights. Counting
  `NOS` and weighing `KG` are not comparable, and the system never converts between them;
- **no loss quantity is captured** on the copra order.

> `TBD-3` — whether drying / moisture / process loss should be recorded, and on what basis
> (input weight vs. output weight, per batch or per dryer run). Until the factory confirms
> it, recording a number would assert something nobody has measured.

### Lots and traceability

The dehusked coconut lot must be **explicitly selected**. Posting creates a **new lot for
each output**, each recording the copra run as its source and naming the consumed lot in its
notes:

```
Supplier
   └─▶ Whole Coconut GRN
          └─▶ Whole Coconut Lot
                 └─▶ Dehusking run
                        └─▶ Dehusked Coconut Lot
                               └─▶ Copra run ─┬─▶ Copra Lot
                                              └─▶ Raw Shell Lot
```

Stock traces in both directions: forward from a supplier's delivery to the shell sold, and
backward from a shell lot to the coconut it came from.

### Inventory posting

Two transactions, one database transaction:

| Transaction | Lines |
| --- | --- |
| `PRODUCTION_CONSUMPTION` | Dehusked Coconut **OUT** — selected quantity, warehouse and lot |
| `PRODUCTION_OUTPUT` | Copra **IN** — measured KG, new lot |
| | Raw Shell **IN** — derived NOS *and* measured KG, new lot |

If anything fails — insufficient stock, an inactive product, a missing weight — **nothing**
is written: no coconut consumed, no output stock, no output lots, no partial state.

### Reversal

Reversal uses the **same** `ReverseProduction` action as the other production types: the
`PRODUCTION_OUTPUT` is reversed first, then the `PRODUCTION_CONSUMPTION`, both at their
**original** unit costs rather than at whatever the weighted averages have drifted to.

The shell's measured weight is mirrored onto the reversing line, so the count and the weight
leave stock together and neither is left stranded.

**If either output has been consumed — sold or processed onward — the entire reversal is
refused.** There is no partial reversal: reversing the outputs first means the rejection
happens before any coconut is restored, and the whole operation rolls back regardless.
Selling some of the copra blocks the reversal even though the shell is untouched, and the
reverse is equally true.

Double reversal is prohibited, and the original run is never deleted or altered.

## 21. Accounting — the foundation

The accounting engine exists as of Finance Phase 1. The rules live in full in
`docs/ACCOUNTING_RULES.md`; the ones that constrain everything else are:

- **Double entry, always.** Every posted journal satisfies `Total Debit = Total Credit`
  exactly, enforced before the status changes and inside one database transaction.
- **Balances are derived**, never stored — the same rule as ledger-derived stock.
- **Posted journals are immutable**, lines included. Corrections are reversing entries that
  mirror the original at its exact amounts; both sides stay on the record.
- **A journal belongs to an OPEN fiscal period containing its date.** A period holding a
  draft cannot be closed, and a reversal posts into the period containing its own date.
- **Control accounts and group headers refuse manual posting.** Control accounts belong to
  the system actions that will later own them.
- **Base currency is LKR, held as configuration.** Multi-currency is TBD.

## 22. Accounting — inventory integration

Stock movements reach the general ledger, governed by a **cutover date** that an accountant
sets and a seeder never invents. While it is unset nothing changes; on or after it:

| Event | Journal |
| --- | --- |
| Goods receipt | Dr product inventory / Cr Goods Received Not Invoiced |
| Delivery | Dr product cost of sales / Cr product inventory |
| Production | Dr output inventory / Cr input inventory |
| Warehouse transfer | **none** — same product, same value, same account |
| Any reversal | the original journal reversed exactly |

- **Every amount is the inventory ledger's authoritative `total_cost`.** Finance recalculates
  nothing — not the landed-cost allocation, not the 85/15, 30/30/40 or 75/25 production
  splits. It records what the ledger already holds, which is why debits and credits agree
  exactly and a control account reconciles to stock.
- **A delivery recognises no revenue.** It is a physical issue. Revenue and receivables
  belong to the sales invoice, which is not built — so GRNI and cost of sales accumulate
  without matching entries for now.
- **Accounting and stock commit together.** An unmapped product, a closed period or no open
  period fails the whole posting: no stock, no lot, no journal, document still a draft.
- **A product with no account mapping cannot move stock.** It is refused, not guessed at.
- **Account codes appear only in the two seeders.** Everything else resolves accounts through
  `product_account_mappings`.

Full detail in `docs/ACCOUNTING_RULES.md` §13-§15.

## 23. Accounts payable

```
Goods receipt      Dr Inventory              Cr Goods Received Not Invoiced
Supplier invoice   Dr GRNI  or  Dr Expense   Cr Trade Payables
Supplier payment   Dr Trade Payables         Cr Cash / Bank
```

- **A supplier invoice never changes inventory.** The goods receipt established what the
  stock is worth; the invoice records who is owed for it.
- **One goods receipt may be invoiced by several suppliers.** Goods, transport and loading are
  commonly three different companies, so matching is partial and per-amount, capped at what
  the receipt has left uninvoiced. The invoice supplier need not be the receipt supplier.
- **The accrual is read from the receipt's own posted journal**, never recalculated. A receipt
  from before the accounting cutover, or one that has been reversed, raised no accrual and
  cannot be invoiced against.
- **Nothing is written off automatically.** If the invoices never fully clear a receipt, the
  remainder stays visible on the GRNI reconciliation. The permanent purchase-price variance
  policy is TBD.
- **An invoice cannot be reversed while a posted payment is allocated to it**, and **a goods
  receipt cannot be reversed once a posted invoice has matched against it**. Each error names
  what has to be reversed first.
- **Payments are always allocated to invoices** — no prepayments in Version 1 — and the
  allocations must total the payment exactly. One payment may settle several invoices; one
  invoice may take several payments.
- **Balances are derived, never stored.** A supplier has no payable column; an invoice's
  outstanding and its PARTIALLY_PAID / PAID status follow from the payments allocated to it.
- **No VAT and no foreign currency.** Amounts are tax-unmodelled and in LKR.

Full detail in `docs/ACCOUNTING_RULES.md` §16-§18.
