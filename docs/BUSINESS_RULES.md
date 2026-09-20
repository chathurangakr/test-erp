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
