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

**Costing:** the dehusked coconut carries the whole coconut value; the raw husk carries the
dehusking **labour** (quantity × labour rate per coconut). See §18.

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
- Is processed through **husk chipping** — but chipping does **not** take it out of stock.
  The raw husks a batch used are **backflushed** (issued) when the chips or cocopeat are
  **delivered** (§19).
- Is **one pooled stock** with no lots (§32). Purchased and dehusked husk share one weighted
  average.

## 5. Husk Chipping

```
Input:   Raw Husk            (NOS)   ← backflushed at DELIVERY, not taken at chipping
Output:  7C · 9C · 10C       (KG or L — separate stock items)
         Cocopeat            (KG or L — separate stock items)
         waste / loss        (recorded, NOT stocked)
```

**Confirmed rules**

- **Actual production output quantities must be entered by the operator**, as flexible rows
  (any of the allowed products, each positive, each once).
- **Chipping takes no raw husk.** The delivery of the outputs states "Raw Husks Used" and
  takes them from stock at their weighted average into cost of goods sold (§19).
- **KG and litres never mix.** A product measured both ways is two stock items, e.g. `HUSK-7C`
  (KG) and `HUSK-7C-L` (L). There is no KG↔L conversion.
- **There is NO fixed conversion from husks (NOS) to KG or litres.** The system must never
  calculate an output quantity from a husk count.
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
- **Dehusking labour is costed** (§18): it becomes the raw husk's cost, credited to the
  `6310 Production Labour Absorbed` contra-expense. Electricity, fuel, machine and overhead
  costs are still not costed.
- **Husk chipping is backflushed** (§19): its outputs enter stock at zero value and the husk
  cost reaches cost of goods sold when they are delivered.
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
| Husk chipping | Raw Husk (NOS), **backflushed at delivery** | 7C, 9C, 10C, Cocopeat (KG or L) + waste (not stocked) | **Operator-entered, no fixed ratio; no KG↔L conversion** |
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

- **A pooled product gets no lot** (§32): its stock joins the lot-free pool, traced to the
  receipt through the ledger's source. Any supplier lot number typed stays on the receipt line
  as information, and the screen does not ask for one. The rest of this section applies to
  lot-tracked products (copra, raw shell, and any product added later that tracks lots).
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
backflush_cost      = what the LEDGER charged for raw husk backflushed (chips, cocopeat — §19)
cost_of_goods_sold  = PRIMARY quantity x inventory_unit_cost + backflush_cost
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

**Pooled products have no lot to choose** (§32): the screen shows no lot picker and a lot
submitted for one is refused. Stock still sitting in an old lot of a pooled product is issued
from the warehouse total.

For **lot-tracked** products, a lot must be **explicitly chosen** whenever the product holds any lotted stock in the
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

**All three production types are implemented** — dehusking (here), husk chipping (§19) and
copra (§20) — on one shared production engine.

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
| `PRODUCTION_CONSUMPTION` | Whole Coconut **OUT** |
| `PRODUCTION_OUTPUT` | Dehusked Coconut **IN** and Raw Husk **IN** |

Both are written inside **one database transaction**. Any failure leaves no consumed stock,
no output stock and no posted order. The run records the recipe version it used
(`production_recipe_id`).

### Lots — pooled stock, no lot selection ✅ **CONFIRMED**

Whole coconut, dehusked coconut and raw husk are **pooled** products (`tracks_lots = false`,
§32): each is **one stock** per warehouse with no lots. The operator chooses **no lot**, a lot
submitted for pooled coconut is refused, and the outputs receive **no lot**. Traceability is
to the production order itself, through its consumption and output ledger transactions:

```
Supplier → Coconut GRN → (pooled coconut) → Dehusking run → (pooled dehusked coconut)
                                                          → (pooled raw husk)
```

Historic lots created before pooling keep their full history (§32).

### Costing — coconut value to dehusked coconut, labour to raw husk ✅ **CONFIRMED** (recipe version 2)

The dehusked coconut carries **100%** of the consumed coconut value. The raw husk carries
**none** of it (0%). Instead the raw husk's cost is the run's **labour**:

```
labour cost = quantity dehusked × labour rate per coconut
```

The **labour rate per coconut is entered (confirmed) by the operator on every run**,
pre-filled from the recipe's `default_labour_rate`. **Zero is accepted** — it is a deliberate
answer, and the husk then carries no cost. A run whose rate is missing is refused at posting.

**Worked example** — 100 coconuts at Rs.50, labour Rs.3.00 per coconut:

```
Consumed:   100 Whole Coconut @ Rs.50.00          Rs.5,000
Labour:     100 × Rs.3.00                         Rs.  300
Produced:   100 Dehusked Coconut   100%           Rs.5,000   Rs.50.00 each
          + 100 Raw Husk           labour         Rs.  300   Rs. 3.00 each
                                                  ────────
                                                  Rs.5,300   = input + labour, exactly
```

**Value is conserved exactly: output value = consumed value + labour.** The consumed value is
whatever the ledger charged at the weighted average in force, read back after the consumption
posts — never recomputed. The allocator shares the consumed value (100 / 0, the last output
absorbing any rounding remainder) and the labour is then added, whole, to the output the
recipe marks `absorbs_labour`. Which output carries labour is recipe configuration, never a
product code.

**Default rate ✅ CONFIRMED: Rs. 4.00 per coconut** (`default_labour_rate`, set in
`ProductionRecipeSeeder`). The owner expects it to change from time to time: the operator can
override it on any run, and each run stores the rate it used, so changing the default never
alters a posted run.

### Accounting for the labour ✅ **CONFIRMED**

Once operational accounting applies (§22):

```
Dr 1320 Dehusked Coconut Inventory     5,000
Dr 1330 Raw Husk Inventory               300
    Cr 1310 Whole Coconut Inventory          5,000
    Cr 6310 Production Labour Absorbed         300
```

`6310 Production Labour Absorbed` is a **contra-expense** (an expense account carrying a
credit), configured in `accounting_settings.production_labour_account_id`. Wages are still
paid by **expense voucher** to `6300 Wages and Salaries`; the labour sitting in unsold husk
offsets them until that husk is sold. **Production never creates a cash movement or a
payable.** Zero-value lines are left out, so a zero-labour run writes no 1330 or 6310 line.

### What is not costed

Electricity, machine, fuel and overhead costs are still **excluded**. Labour is the only
cost production adds.

### Version 1 — superseded, kept for history

Recipe version 1 split the coconut value **85 / 15** between dehusked coconut and raw husk
and carried no labour. It is **deactivated, never deleted**; runs posted under it keep the
figures they stored. Exactly one recipe version per production type is active.

### Reversal

Reversal undoes both halves, **outputs first**:

1. the `PRODUCTION_OUTPUT` is reversed, taking the dehusked coconut and husk back out of
   stock — at their original values, **labour included**;
2. the `PRODUCTION_CONSUMPTION` is reversed, putting the whole coconut back.

The run's journal is reversed exactly, so the 6310 credit is cancelled too.

**Reversal is refused if the output has already been consumed** — sold, processed onward or
backflushed — because the stock is no longer there to remove. Reversing the outputs first
means that rejection happens before any coconut is restored, and the whole operation rolls
back regardless.

Double reversal is prohibited, and the original run is never deleted or altered.

---

## 19. Production — husk chipping (backflushed at delivery)

Husk chipping shares the production engine with dehusking and copra — the same order, the
same output transaction, the same reversal and cancellation actions, the same recipe tables.
There is **no second production engine**.

**Chipping takes NO raw husk out of stock.** The factory cannot say, run by run, how many
husks a batch used — it knows that when the goods are sold. The recipe (version 2) therefore
records `input_consumption = AT_DELIVERY`: the raw husk is **backflushed** by the delivery
that sells the outputs. ✅ **CONFIRMED**

### What a chipping run records

The operator records what the batch produced as **flexible rows** — product, quantity, unit:

| Allowed output | Unit |
| --- | --- |
| 7C (`HUSK-7C`) · 9C (`HUSK-9C`) · 10C (`HUSK-10C`) · Cocopeat (`COCOPEAT`) | `KG` |
| 7C (`HUSK-7C-L`) · 9C (`HUSK-9C-L`) · 10C (`HUSK-10C-L`) · Cocopeat (`COCOPEAT-L`) | `L` |

| Rule | Detail |
| --- | --- |
| At least one row | **Required** |
| Each row | A **positive** measured quantity, at its unit's precision |
| Zero rows | **Refused** — a product not produced is simply left out |
| Same product twice | **Refused** |
| A product outside the recipe | **Refused** |
| A husk lot or husk quantity | **Refused** — chipping takes no husk |

**KG and litres are separate stock items** (§32). 50 KG of 7C and 100 L of 7C are two
balances that never merge or net off. **There is no conversion from husks to KG or litres,
and none between KG and litres — and there must never be one.** The recipe's `output_ratio`
is `NULL` for every chipping output, so no number exists to derive one from.

### Inventory posting — outputs only, at zero value

Posting writes **one** `PRODUCTION_OUTPUT` transaction: every output **IN** at **zero cost**
(`allocated_cost` 0, `unit_cost` 0, share 0%). No `PRODUCTION_CONSUMPTION` is written and raw
husk stock does not change. Nothing of value moved, so **no journal is written**, even after
the accounting cutover.

The screens call this **"Raw husk material cost pending backflush"**: the chips and cocopeat
are in stock, but their husk cost is still sitting in Raw Husk inventory until a delivery
takes it.

Outputs are pooled products (§32), so no lot is created for them.

### Backflush at delivery ✅ **CONFIRMED**

A delivery line for a chipping output must state **"Raw Husks Used"** — how many raw husks
went into the goods on that line: **greater than zero, whole `NOS`**. Any other product must
state none. Which products need it is read from the active recipe (its outputs), **never
from a product code**.

Posting the delivery, **inside the same database transaction**:

1. issues the goods (`SALES_ISSUE`) at their own weighted average — zero for chips made
   under this method;
2. issues the stated raw husks from the **pooled raw husk in the delivery's warehouse**, in a
   separate `BACKFLUSH_ISSUE` transaction, valued by the engine at the raw husk **weighted
   average** in force;
3. stores on the line `backflush_product_id`, `backflush_quantity` and `backflush_cost` (read
   back from the ledger, never recomputed), and

```
cost_of_goods_sold = goods issue cost + backflush_cost
```

**If the warehouse does not hold enough raw husk, the whole delivery is refused** — no goods
leave, no husk leaves, no journal, the delivery stays a draft. The error appears beside "Raw
Husks Used".

**Journal** (once accounting applies): the husk cost lands in the **delivered product's** cost
of sales, not raw husk's:

```
Dr 5130 Husk Chip COGS  (or 5170 Cocopeat COGS)     backflush_cost
    Cr 1330 Raw Husk Inventory                           backflush_cost
```

**Reversing the delivery returns the husks** at their **original** cost (not the current
average), alongside the goods, and the journal is reversed exactly.

### Worked example

```
Raw husk pool:  500 bought @ Rs.12 = Rs.6,000
              + 100 from dehusking (labour Rs.3) = Rs.300
              = 600 husks, Rs.6,300, average Rs.10.50

Chipping run:   7C 40 KG · 7C 100 L · Cocopeat 100 L    ← zero value; husk pool still 600

Delivery:       20 KG of 7C @ Rs.300, Raw Husks Used 180
                sales value      Rs.6,000
                husk cost        180 × 10.50 = Rs.1,890  → cost of goods sold
                gross profit     Rs.4,110
Husk pool:      600 − 180 = 420 husks, Rs.4,410, still Rs.10.50
```

### Consequences worth knowing

- **Chip and cocopeat stock shows zero value until it is sold.** Their husk value stays in Raw
  Husk inventory meanwhile. This is inherent to backflushing, not an error.
  > `TBD` — whether the owner wants an *estimated* value shown on unsold chips.
- **Chips costed under the old method (version 1) already carry husk cost.** Stating husks on
  a delivery of such stock would count the husk twice. None exists any more: the development
  test data was cleared on 2026-10-04, so every chip in stock from now on is version 2.

### Waste is information only

Unchanged: `waste_quantity` + `waste_uom_id` on the order, both optional. **No inventory line,
no lot, no `DUST` product, no cost.**

> `TBD-1` — the measurement basis for chipping waste (weight, husk count, or estimate).

### Version 1 — superseded, kept for history

Version 1 consumed the husk at production (with a chosen husk lot) and split its value
**30 / 30 / 40** across 7C / 9C / 10C. It is **deactivated, never deleted**, and runs posted
under it keep their consumption, allocation and lots. A **draft** entered under the old
method still carries a husk input line; posting it now is **refused** ("cancel it and record
the run again") rather than silently reinterpreted.

### Reversal

Reversal uses the same `ReverseProduction` action. A version 2 run has **only an output** to
reverse; raw husk is untouched. **If any output has been issued, the entire reversal is
refused.** Double reversal is prohibited, and the run is never deleted or altered.

## 20. Production — copra and raw coconut shell

Copra production shares the whole production engine with dehusking and husk chipping — the
same order, the same two ledger transactions, the same reversal and cancellation actions,
the same recipe tables and the same cost allocator. There is **no second production
engine**.

What is new is that its two outputs behave **differently from each other**. Dehusking
derives every output; chipping measures every output. Copra does both at once:

```
Input:   1,000 Dehusked Coconut  (NOS)   ← pooled stock (no lot), chosen warehouse

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

Dehusked coconut is **pooled** (§32), so **no input lot is chosen** (one submitted is
refused). Copra and raw shell **still track lots**: posting creates a **new lot for each
output**, each recording the copra run as its source:

```
Supplier
   └─▶ Whole Coconut GRN
          └─▶ (pooled coconut) ─▶ Dehusking run
                                     └─▶ (pooled dehusked coconut)
                                            └─▶ Copra run ─┬─▶ Copra Lot
                                                           └─▶ Raw Shell Lot
```

Copra and shell lots trace forward to the deliveries that sold them; backward, the copra run
and the dehusking run link to the receipts through their ledger transactions.

> `TBD` — whether copra and raw shell should also become pooled stock. The owner listed only
> the coconut and husk products for lot-free operation, so copra and shell keep their lots.

### Inventory posting

Two transactions, one database transaction:

| Transaction | Lines |
| --- | --- |
| `PRODUCTION_CONSUMPTION` | Dehusked Coconut **OUT** — selected quantity and warehouse |
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
| Production | Dr output inventory / Cr input inventory (+ Cr 6310 for dehusking labour) |
| Husk chipping | **none** — zero-value outputs, nothing consumed |
| Delivery backflushing raw husk | Dr the delivered product's cost of sales / Cr 1330 Raw Husk Inventory |
| Lot consolidation | **none** — same product, same value, same account |
| Warehouse transfer | **none** — same product, same value, same account |
| Any reversal | the original journal reversed exactly |

- **Every amount is the inventory ledger's authoritative `total_cost`.** Finance recalculates
  nothing — not the landed-cost allocation, not the production splits (100/0 + labour,
  75/25), not the backflushed husk. It records what the ledger already holds, which is why debits and credits agree
  exactly and a control account reconciles to stock.
- **A delivery recognises no revenue.** It is a physical issue. Revenue and the receivable
  belong to the sales invoice — see §24. Until a delivery is invoiced its cost of sales has
  no matching revenue, which is why the unbilled-deliveries report exists.
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

---

## 24. Accounts receivable

```
Delivery           Dr Cost of Sales          Cr Inventory          (no revenue)
Sales invoice      Dr Trade Receivables      Cr Sales Revenue
Customer receipt   Dr Cash / Bank            Cr Trade Receivables
```

- **A delivery is a physical issue; the invoice is the sale.** Stock and cost leave when the
  delivery posts. Revenue and the debt are recognised only when the invoice posts.
- **A sales invoice bills delivered goods only.** Every line points at a delivery line. There
  are no service lines, ad-hoc charges or arbitrary product lines in Version 1.
- **The billed amount comes from the delivery line's `sales_value`**, never recalculated from
  quantity × price. For raw shell sold by weight this matters: re-deriving the figure would
  need a NOS↔KG conversion, and **no such conversion exists or may ever be added**.
- **The revenue account is resolved from the product's mapping, never chosen by the user.** No
  screen offers a revenue selector. An unmapped product cannot be invoiced.
- **A delivery from before the accounting cutover cannot be invoiced.** It raised no cost of
  sales in the ledger, so billing it would show revenue with no matching cost. Historic
  deliveries are not backfilled.
- **Partial billing works both ways.** One delivery line may be billed across several invoices;
  one invoice may bill several deliveries — all for the same customer. A line can never exceed
  what the delivery has left uninvoiced.
- **An invoice cannot be reversed while a posted receipt is allocated to it**, and **a delivery
  cannot be reversed once a posted invoice has billed it**. Each error names what has to be
  reversed first.
- **Receipts are always allocated to invoices** — no customer advances or deposits in Version 1
  — and the allocations must total the receipt exactly. One receipt may settle several
  invoices; one invoice may take several receipts.
- **Balances are derived, never stored.** A customer has no receivable column; an invoice's
  outstanding and its PARTIALLY_PAID / PAID status follow from the receipts allocated to it.
  `credit_limit` and `credit_days` are agreed terms — the credit days suggest a due date, which
  the user may change.
- **Reversal is an audit correction, not a credit note.** Reversing says the invoice should
  never have existed. Returns, discounts agreed after the fact and quality allowances are
  commercial events needing credit notes, which are **not built** — do not use a reversal in
  their place.
- **No VAT and no foreign currency.** Amounts are tax-unmodelled and in LKR.

Full detail in `docs/ACCOUNTING_RULES.md` §19-§21.

---

## 25. Cash, bank and immediate expenses

```
Immediate expense   Dr Expense                Cr Cash / Bank        (expense voucher)
Credit expense      Dr Expense                Cr Trade Payables     (supplier invoice)
  then paid         Dr Trade Payables         Cr Cash / Bank        (supplier payment)
Customer receipt    Dr Cash / Bank            Cr Trade Receivables
Fund transfer       Dr Destination Cash/Bank  Cr Source Cash/Bank
```

- **Cash and bank balances are derived from posted journals**, never stored. There is no
  balance column on a cash/bank configuration and there never will be.
- **Which accounts hold money is configured, not assumed.** `1110 Cash on Hand` and `1120 Bank`
  are set up as CASH and BANK. More bank accounts are added by an accountant; the software does
  not invent which banks the factory uses.
- **Being an asset account is no longer enough to pay from or receive into.** It must be a
  configured cash or bank account. This applies to supplier payments and customer receipts as
  well, which previously accepted any asset account.
- **An expense voucher is for an expense paid immediately** — electricity settled at the
  counter, fuel bought and paid on the spot. It raises **no payable**, because nothing is owed
  afterwards.
- **A supplier invoice is for an expense paid later.** It raises a payable that a supplier
  payment settles. Do not use one document for the other: a voucher for a credit purchase
  loses the debt, and an invoice for an immediate payment invents one.
- **A voucher may name a supplier for reporting only.** It still creates no Trade Payables.
- **A voucher may charge ordinary operating expenses only.** Cost of sales is excluded because
  it is derived from stock movements; inventory, revenue, equity and control accounts are
  excluded outright.
- **A fund transfer moves money between the factory's own accounts.** Cash→Bank, Bank→Cash and
  Bank→Bank. No expense, no revenue, no payable, no receivable, no inventory — the combined
  cash-and-bank total is identical before and after. The same account on both sides is refused.
- **Neither document touches inventory.** No quantity, no ledger row, no weighted average, no
  lot.
- **Both are DRAFT / POSTED / REVERSED / CANCELLED**, immutable once posted, corrected by
  reversal, never deleted. Numbers are `EXP-YYYYMMDD-000001` and `TRF-YYYYMMDD-000001`.
- **The cash/bank book shows everything that moved through an account** — payments, receipts,
  vouchers, transfers and manual journals alike — because each posted a journal line. Money in
  is a debit, money out a credit, and the closing balance always equals the general ledger.
- **Whether cash may go negative, and whether banks may overdraw, is `TBD`** — see
  `docs/ACCOUNTING_RULES.md` §23. Nothing currently prevents it, and no policy was invented.
- **Every supplier payment and customer receipt says how the money moved** — cash, cheque or
  bank transfer (§32). Cash must go through a cash account; a cheque or a transfer through a
  bank account. A cheque records its number, date and bank.
- **No bank reconciliation, no cheque lifecycle (deposited / cleared / bounced), no VAT, no
  foreign currency.**

Full detail in `docs/ACCOUNTING_RULES.md` §22-§23.

## 26. Financial statements and management reports

```
Profit and loss   Revenue − Cost of Sales = Gross Profit
                  Gross Profit − Operating Expenses = Net Profit
Balance sheet     Assets = Liabilities + Equity + Current Earnings
```

- **Every report derives from its source ledger.** Financial statements read posted journal
  lines; inventory valuation reads the inventory ledger; product profitability reads posted
  deliveries. **No statement balance is ever stored** — no profit, asset, liability, cash, AR
  or AP column exists anywhere.
- **Accounts are classified by account type, never by account number.** Revenue, cost of
  sales and expenses come from the account's type; the chart's parent/child structure decides
  how they are grouped on the page.
- **Figures print the way people read them.** Revenue, liabilities and equity show as positive
  numbers; an account with a genuinely abnormal balance (an overdrawn bank, revenue in debit)
  prints negative.
- **Reversals net.** A reversed journal and its reversal both count, so together they show
  nothing.
- **The P&L shows recognised revenue** — invoiced sales. A delivery that has not been invoiced
  shows its cost with no revenue until the invoice posts.
- **Current Earnings is computed, not posted.** Until year-end closing exists, unclosed profit
  appears as a computed line under equity. Retained Earnings shows only what the ledger
  actually holds; nothing writes profit into it automatically. Because nothing records when a
  financial year begins, Current Earnings accumulates from the start of recorded history.
- **Operational product profitability is not the P&L.** It answers "was this physical sale
  profitable?" from each delivery's own sales value and cost of goods sold, and is labelled
  accordingly. The P&L answers "what revenue has been recognised?". They may differ while
  deliveries are unbilled, and both are correct. Raw shell sold by the KG uses the delivery's
  stored value — no count-to-weight conversion.
- **Inventory valuation is the current position**, from the inventory ledger. Valuing stock
  as at a past date is not supported yet.
- **Inventory vs GL differences are shown honestly.** Stock that existed before the
  accounting cutover has no journal, so it legitimately differs from the general ledger until
  an opening-balance process is built. Nothing is hidden and no journal is backfilled.
- **The dashboard never assumes "this month" as an accounting rule.** It takes a chosen fiscal
  period or explicit dates, and every figure comes from the report that owns it.
- **Not built:** cash flow statement, year-end closing, PDF/Excel export, comparative periods.

Full detail in `docs/ACCOUNTING_RULES.md` §24-§28.

## 27. Who may do what — roles and permissions

- **Every action is checked by the server.** If someone lacks the permission, the system refuses
  — even if they type the address directly. Hidden buttons are only a convenience.
- **A role is a named set of permissions**, and a person may hold several roles. What they can
  do is everything their active roles allow, combined.
- **Preparing, approving, posting, reversing and cancelling are separate permissions.** A
  purchasing clerk can raise an order that only a manager can approve; a storekeeper posts the
  goods receipt; an accountant reverses financial postings. Nobody automatically holds every step.
- **The seven starting roles are starting points.** An administrator can change what each role
  allows, create new roles, and deactivate a role (which withdraws its permissions at once).
- **Permissions never override the document rules.** Even an administrator cannot edit a posted
  goods receipt, delivery, production run or journal.
- **People who leave are deactivated, not deleted.** They can no longer sign in, and every
  document they recorded still shows their name.
- **There is always at least one administrator.** The system refuses any change — including an
  administrator's change to themselves — that would leave nobody able to manage access.
- **Anyone may register, but a new account can do nothing** until an administrator gives it a role.
- **Not yet:** limiting a storekeeper to one warehouse, approval money limits, or hiding
  particular figures such as cost from particular people.

Full detail in `docs/AUTHORIZATION_RULES.md`.

## 28. The mobile app

- **The phone talks to the ERP, never to the database.** It uses the same rules, the same
  permissions and the same posting logic as the website — posting a goods receipt from a phone
  is exactly the same event as posting it at a desk.
- **Each phone signs in separately** and stays signed in for 90 days. Signing out on one phone
  leaves the others signed in; "sign out everywhere" ends them all.
- **Deactivating a person, or changing their password, signs them out of every phone at once.**
- **A person can do on the phone exactly what their roles allow on the website** — no more.
- **The same document cannot be posted twice**, even if two phones press "post" at the same
  moment: the second is told it is already posted.
- **Accounts with two-factor authentication need their code on the phone too.**
- **Not yet:** photos and attachments, working offline, or creating users from the phone.

Full detail in `docs/API_V1.md`.

## 29. The factory's clock — Sri Lanka time

- **The factory runs on Sri Lanka time (Asia/Colombo, UTC+05:30).** "Today", the date inside
  every document number, overdue invoices, aging and every date a form suggests all follow the
  Sri Lankan calendar — so work done between midnight and 05:30 is dated today, not yesterday.
- **Times shown on screens are Sri Lankan times.** A receipt posted at 2:00 PM shows 14:00.
- **Stored moments stay in UTC** (the international standard). Nothing already recorded changed.
  The mobile API sends UTC with a `Z`, and the app shows local time.
- **Dates people type are kept exactly as typed.**
- The timezone is configuration (`APP_BUSINESS_TIMEZONE`), used only through
  `App\Support\BusinessClock`.

## 30. Simple mode for factory staff

- **Big-button screens** for receiving, dispatch, dehusking, husk chipping, copra and checking
  stock, in **Sinhala and English**.
- **"Done" posts immediately** — stock moves at once. So a job is offered only to people whose
  role may post it; anyone else does not see the tile.
- **Staff type the prices** when receiving and dispatching; the cost of goods leaving is always
  calculated by the system.
- **The batch must be chosen** only for lot-tracked stock (copra, shell). Pooled stock —
  coconut, dehusked coconut, husk, chips, cocopeat — has no batch to choose.
- **Dehusking asks for the labour per coconut**, pre-filled from the recipe's default.
- **Husk chipping asks what was made** (tap the products, then measure each). It takes no husk.
- **Dispatching chips or cocopeat asks "Raw husks used"**; they are taken from husk stock then.
- **Everything is dated today.** Back-dated work is done in the full ERP.
- **If something is wrong (not enough stock, wrong batch) nothing is saved at all.**

Full detail in `docs/KIOSK.md`.

## 31. Sinhala and English

- **Everyone chooses their own screen language** with one tap; it follows them to any device.
  Nobody's screens change until they choose.
- **Records are always kept in English**, whoever enters them and in whatever language — so
  reports, searches and audits always agree.
- **Accounting words are shown in Sinhala with the English term in brackets.**
- **Finance screens are translated too.** Accounting terms keep the English in brackets.

Full detail in `docs/LOCALISATION.md`.

## 32. Pooled stock, units and payment methods — owner's decisions (Phase 19)

### Pooled (lot-free) stock ✅ **CONFIRMED**

`products.tracks_lots` decides whether a product keeps lots. **False** makes it **one pooled
stock** per warehouse: movements carry no lot and no screen asks for one.

| Pooled (no lots) | Lot-tracked |
| --- | --- |
| Whole Coconut, Dehusked Coconut, Raw Husk, 7C / 9C / 10C (KG and L), Cocopeat (KG and L) | Copra, Raw Shell, and new products by default |

- The flag is **master data** (set by `ProductSeeder`), never a product code in logic.
- The inventory engine refuses **new stock into a lot** of a pooled product. Old lots may still
  be drawn down, and a reversal may put stock back exactly where it came from.
- **Historic lots are never edited or deleted.** Their balances are moved into the pool **once,
  on purpose**, by a recorded, value-neutral `LOT_CONSOLIDATION` transaction — quantity, value
  and weighted average unchanged, no journal:

  ```
  php artisan inventory:consolidate-pooled-lots --dry-run          # show what would move
  php artisan inventory:consolidate-pooled-lots --user=owner@example.com
  ```

  It is safe to re-run: with nothing left in lots it writes nothing.

### Units — NOS, KG and L ✅ **CONFIRMED**

`L` (Litre, 3 decimals) joins `NOS` and `KG`. **No conversion exists between any two units.**
A husk product measured both by weight and by volume is **two stock items**, one per unit:

| Product | KG item | Litre item | Accounts (inventory / COGS / revenue) |
| --- | --- | --- | --- |
| 7C | `HUSK-7C` | `HUSK-7C-L` | 1340 / 5130 / 4130 |
| 9C | `HUSK-9C` | `HUSK-9C-L` | 1340 / 5130 / 4130 |
| 10C | `HUSK-10C` | `HUSK-10C-L` | 1340 / 5130 / 4130 |
| Cocopeat | `COCOPEAT` | `COCOPEAT-L` | **1370 / 5170 / 4170** (its own accounts) |

Each item has its own stock and its own weighted average, so 50 KG of 7C and 100 L of 7C
never merge, net off or share a cost.

### Payment methods ✅ **CONFIRMED**

| Method | Goes through | Records |
| --- | --- | --- |
| Cash | a **cash** account | — |
| Cheque | a **bank** account | cheque number, date, bank |
| Bank transfer | a **bank** account | — |

- **"Credit" is not a payment method.** Buying or selling on credit is an invoice left
  outstanding; payments and receipts settle it later, **in full or in parts**.
- Invoice screens show **Total / Paid / Outstanding** and offer **Record payment**, which opens a
  payment or receipt pre-filled for that invoice.
- Cheque details are **recorded only**: the money posts straight to the chosen bank account.
  > `TBD` — cheque status tracking (received / deposited / cleared / bounced) with a clearing
  > account. Until then a bounced cheque is handled by reversing the receipt.
- Documents recorded before payment methods existed have none; nothing is invented for them.

### Open items

| # | Item |
| --- | --- |
| 1 | The factory's standard dehusking labour rate (recipe default is NULL) |
| 2 | Cheque lifecycle and clearing account |
| 3 | Whether unsold chips / cocopeat should show an estimated husk value |
| 4 | Whether copra and raw shell should also become pooled stock |
