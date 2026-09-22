# End-to-End Factory Validation

> **Status:** ✅ **IMPLEMENTED.** The scenario below is executed and asserted by
> `tests/Feature/Integration/FactoryWorkflowIntegrationTest.php`.

Every other test suite proves one module in isolation. This one proves the modules **agree
with each other**: that a coconut bought from a supplier can be followed through dehusking,
chipping, drying and out to a customer, with the money arriving intact at every hop.

It builds no new business logic. It is a validation harness, and it is the document to read
when you want to know what the system actually does end to end.

---

## 1. Where it runs

| | |
| --- | --- |
| Database | The isolated PHPUnit database — SQLite `:memory:`, created and dropped per test |
| Development database | **Never touched.** No seeding, no migration, no writes to `coconut_erp_dev` |
| Manual QA data | `QA-COCO-260913`, its goods receipts, lots and stock are **not read and not modified** |
| Fixtures | Built in `setUp()` from factories; no fake seed data is added to the repository |

Each test re-runs the whole flow in its own transaction, so no test depends on another's
state.

---

## 2. Master data

Eight products, matching the seeded Product Master:

| Code | Primary | Secondary |
| --- | --- | --- |
| `COCONUT` | NOS | — |
| `DEHUSKED-COCONUT` | NOS | — |
| `RAW-HUSK` | NOS | — |
| `COPRA` | KG | — |
| `HUSK-7C` / `HUSK-9C` / `HUSK-10C` | KG | — |
| `RAW-SHELL` | **NOS** | **KG** |

Plus `TEST-SUPPLIER`, `TEST-CUSTOMER`, and **two** warehouses: `MAIN` (default) and
`STORE`. Two warehouses exist on purpose — the weighted average is company-wide, so a
purchase into `STORE` must blend with production output in `MAIN`, and warehouse totals
must still reconcile to company totals.

---

## 3. The scenario

```
Supplier ──▶ buy 10,000 coconut ──┬──▶ sell 2,000 whole
                                  │
                                  └──▶ dehusk 3,000 ──┬──▶ dehusked 3,000 ──┬──▶ sell 1,000
                                                      │                     │
                                                      │                     └──▶ copra run 1,000
                                                      │                            ├─▶ copra 190.500 kg ──▶ sell 90.500 kg
                                                      │                            └─▶ shell 1,000 NOS  ──▶ sell 500 NOS
                                                      │                                / 420.750 kg           / 208.750 kg
                                                      └──▶ raw husk 3,000 ──▶ chip 2,000 ──┬─▶ 7C 150.500 kg ──▶ sell 50 kg
                                                                                           ├─▶ 9C 205.250 kg
Supplier ──▶ buy 5,000 raw husk (into STORE) ──────────────────────────────────────────────└─▶ 10C 281.750 kg
```

### Step 1 — buy whole coconut

10,000 `COCONUT` @ Rs.100, transport Rs.20,000, loading Rs.5,000.

| | |
| --- | --- |
| Material cost | Rs.1,000,000.000000 |
| Landed cost allocated | Rs.25,000.000000 |
| **Final unit cost** | **Rs.102.500000** — the landed cost is what reaches inventory |
| Stock | 10,000.000 NOS, value Rs.1,025,000.000000 |
| Lot | created, `source_type = GoodsReceipt` |

### Step 2 — sell whole coconut

2,000 @ Rs.130.

| | |
| --- | --- |
| Revenue | Rs.260,000.000000 |
| Inventory unit cost | Rs.102.500000 — taken from the ledger, never typed |
| COGS | Rs.205,000.000000 |
| Gross profit | Rs.55,000.000000 |
| Stock after | 8,000.000 NOS @ **Rs.102.500000** — selling at 130 moved the average not at all |

### Step 3 — dehusking (fixed 1:1:1, all outputs derived)

3,000 coconut → 3,000 dehusked + 3,000 raw husk.

| Output | Share | Allocated | Unit cost |
| --- | --- | --- | --- |
| `DEHUSKED-COCONUT` 3,000 NOS | 85% | Rs.261,375.000000 | **Rs.87.125000** |
| `RAW-HUSK` 3,000 NOS | 15% | Rs.46,125.000000 | **Rs.15.375000** |
| | | **Rs.307,500.000000** | = consumed, exactly |

Coconut falls to 5,000.000 NOS @ Rs.102.500000 — consuming stock at the average leaves the
average untouched.

> A dehusking draft carries **no** output rows: every quantity is derived from the recipe
> and the posting action writes the rows itself. A draft that did carry them is now
> **refused** — see §7.

### Step 4 — sell dehusked coconut

1,000 @ Rs.150.

| | |
| --- | --- |
| Revenue | Rs.150,000.000000 |
| Inventory unit cost | **Rs.87.125000** — dehusking's allocated cost, not the coconut price |
| COGS | Rs.87,125.000000 |
| Gross profit | Rs.62,875.000000 |

### Step 5 — buy raw husk at a different cost, into a different warehouse

5,000 `RAW-HUSK` @ Rs.20, no landed cost, received into **`STORE`**.

| | |
| --- | --- |
| Internally produced | 3,000 NOS @ Rs.15.375000 = Rs.46,125 (lot sourced from the dehusking run, in `MAIN`) |
| Purchased | 5,000 NOS @ Rs.20.000000 = Rs.100,000 (lot sourced from the GRN, in `STORE`, supplier lot `SUP-HUSK-01`) |
| Company total | 8,000 NOS, Rs.146,125 |
| **Blended WAC** | **Rs.18.265625** — one average across both warehouses and both origins |

The two lots stay **separately traceable**: different ids, different `source_type`, different
warehouses, and independent balances.

### Step 6 — husk chipping (operator-measured weights)

2,000 husk from the **internally produced** lot. Consumed
`2,000 × 18.265625 = Rs.36,531.250000`.

| Output | Measured | Share | Allocated | Unit cost |
| --- | --- | --- | --- | --- |
| `HUSK-7C` | 150.500 KG | 30% | Rs.10,959.375000 | Rs.72.819767 |
| `HUSK-9C` | 205.250 KG | 30% | Rs.10,959.375000 | Rs.53.395250 |
| `HUSK-10C` | 281.750 KG | 40% | Rs.14,612.500000 | Rs.51.863354 |
| | **637.500 KG** | | **Rs.36,531.250000** | = consumed, exactly |

2,000 husks produced 637.500 KG — a ratio that exists **nowhere** in the system. Husk is
counted, chips are weighed, and nothing converts between them.

### Step 7 — copra production (one derived count, two measured weights)

1,000 dehusked coconut. Consumed `1,000 × 87.125 = Rs.87,125.000000`.

| Output | Quantity | Source | Share | Allocated | Unit cost |
| --- | --- | --- | --- | --- | --- |
| `COPRA` | 190.500 KG | **measured** | 75% | Rs.65,343.750000 | Rs.343.011811 / KG |
| `RAW-SHELL` | 1,000 NOS | **derived 1:1** | 25% | Rs.21,781.250000 | Rs.21.781250 / NOS |
| | 420.750 KG | **measured** | | | *(Rs.51.767677 / KG — informational only)* |
| | | | | **Rs.87,125.000000** | = consumed, exactly |

Shell is valued against its **primary NOS quantity**. The measured KG rides along as the
ledger's secondary quantity.

### Step 8 — sell finished goods

| Sale | Basis | Revenue | Unit cost | COGS |
| --- | --- | --- | --- | --- |
| 50.000 KG `HUSK-7C` @ 200 | primary | Rs.10,000.000000 | Rs.72.819767 | Rs.3,640.988350 |
| 90.500 KG `COPRA` @ 500 | primary | Rs.45,250.000000 | Rs.343.011811 | Rs.31,042.568896 |
| 500 NOS `RAW-SHELL` / **208.750 KG** @ 80 | **secondary** | **Rs.16,700.000000** | Rs.21.781250 | **Rs.10,890.625000** |

The shell sale is the sharpest test in the scenario:

- **Revenue follows the weight** — `208.750 × 80 = Rs.16,700`.
- **Cost follows the count** — `500 × Rs.21.781250`, because the count is what left stock.
- **The weight is measured, not apportioned.** Half the shells by count would have been
  `210.375 KG` pro rata. The actual figure is `208.750 KG`, and stock records exactly that.
  A test asserts the two differ.

---

## 4. Final balances

| Product | Quantity | Value | WAC |
| --- | --- | --- | --- |
| `COCONUT` | 5,000.000 NOS | Rs.512,500.000000 | Rs.102.500000 |
| `DEHUSKED-COCONUT` | 1,000.000 NOS | Rs.87,125.000000 | Rs.87.125000 |
| `RAW-HUSK` | 6,000.000 NOS | Rs.109,593.750000 | Rs.18.265625 |
| `HUSK-7C` | 100.500 KG | Rs.7,318.386650 | Rs.72.819768 |
| `HUSK-9C` | 205.250 KG | Rs.10,959.375000 | Rs.53.395250 |
| `HUSK-10C` | 281.750 KG | Rs.14,612.500000 | Rs.51.863354 |
| `COPRA` | 100.000 KG | Rs.34,301.181104 | Rs.343.011811 |
| `RAW-SHELL` | 500.000 NOS **/ 212.000 KG** | Rs.10,890.625000 | Rs.21.781250 |

Raw husk by warehouse: `MAIN` 1,000 (internal lot, 2,000 chipped away) + `STORE` 5,000
(purchased lot, untouched) = 6,000 company-wide.

---

## 5. Value conservation — exact

Every production run conserves value **exactly**, at both levels:

```
production input total_cost          ==  Σ output allocated_cost      (document)
PRODUCTION_CONSUMPTION total_cost    ==  Σ PRODUCTION_OUTPUT total_cost (ledger)
```

| Run | Consumed | Ledger output | Residue |
| --- | --- | --- | --- |
| Dehusking | Rs.307,500.000000 | Rs.307,500.000000 | **0.000000** |
| Husk chipping | Rs.36,531.250000 | Rs.36,531.250000 | **0.000000** |
| Copra | Rs.87,125.000000 | Rs.87,125.000000 | **0.000000** |

> **This used to be a caveat.** The first run of this validation recorded residues of
> 0.000013 (chipping) and 0.000004 (copra), because the ledger stored a six-decimal *unit*
> cost and rebuilt each line's value as `quantity × unit_cost`. A rate like Rs.57.142857 per
> KG cannot reproduce the Rs.16,000 it came from.
>
> That was fixed before Finance was started: **`total_cost` is now the authoritative
> monetary value** of a ledger line and `unit_cost` is only its rounded per-unit
> representation. The allocator's exact figures reach the ledger unchanged. See
> `INVENTORY_RULES.md` §8.1 and `DATA_MODEL.md` **I-4**.

Because of that change, `total_cost == quantity × unit_cost` is **no longer asserted** as a
ledger invariant. What the test asserts instead, for every line in the scenario:

```
quantity > 0,  total_cost >= 0,  unit_cost >= 0
unit_cost == round(total_cost / quantity, 6)
|total_cost − quantity × unit_cost| <= quantity × 0.0000005
```

## 6. What else is asserted

| Area | Assertion |
| --- | --- |
| **Traceability — copra** | supplier → GRN → coconut lot → dehusking → dehusked lot → copra run → {copra lot, shell lot} → deliveries. Each output lot names its parent lot in its notes |
| **Traceability — husk** | coconut lot → dehusking → raw husk lot → chipping → 7C / 9C / 10C lots → delivery. The purchased husk lot is proven **not** to be in this chain |
| **Reversal safety** | All three production runs have had output sold or consumed, so **all three reversals are refused**. Status stays `POSTED`, both reversal audit fields stay `NULL`, transaction count is unchanged, and every product's stock is byte-identical before and after. No partial restoration |
| **GRN reversal safety** | The coconut receipt cannot be reversed either — its stock has been sold and dehusked |
| **Negative stock** | No product, no lot and no product+warehouse balance is negative |
| **Warehouse reconciliation** | For every product, Σ(warehouse quantity) == company quantity, and Σ(warehouse value) == company value |
| **WAC reconciliation** | For every product, `round(value ÷ quantity, 6)` equals the reported weighted average |
| **Ledger integrity** | Every transaction has lines and a date; every line has a warehouse, a positive quantity, non-negative costs, a `unit_cost` equal to `round(total_cost ÷ quantity, 6)`, and a `total_cost` differing from `quantity × unit_cost` by no more than that rounding |
| **Exact conservation** | For each production run, `PRODUCTION_CONSUMPTION total_cost == Σ PRODUCTION_OUTPUT total_cost`, residue exactly `0.000000` |
| **No orphans** | Every lot has a product, a source document that exists, and at least one movement. Every production input/output, delivery line, receipt line, sales order line and purchase order line belongs to an existing document. Every ledger transaction points at an existing source |
| **Posted → transaction** | Every posted GRN, delivery and production order points at a real inventory transaction of the right type |
| **Immutability** | A posted GRN, delivery and production order all refuse `update()` and `delete()` |
| **Drafts** | A draft GRN, delivery and production order together create no transaction, no lot and no stock movement |
| **Reversed records** | A reversed GRN stays readable with its lines intact, keeps both transaction links, and returns quantity, value **and** the weighted average to exactly their prior figures |
| **Scope** | No finance, GL, payables, receivables, payments, production-cost or overhead table exists |

---

## 7. What this phase found

**One robustness gap, fixed.** `PostDehuskingProduction` writes its own output rows, because
all dehusking quantities are derived. It did not check whether the draft already carried
any — so a draft holding output lines would have ended up with **duplicates**, and
`outputValue()` would have double-counted the run.

No current caller can reach that state: `DehuskingController` never writes output rows on a
draft. But the planned REST API would reuse the same action, and a silently duplicated
output is the kind of corruption that cannot be reconstructed afterwards. The action now
**refuses** such a draft, and `DehuskingTest` covers it.

**One stale document line, corrected.** `INVENTORY_RULES.md` still said copra was not built.

**One precision limitation, since fixed.** The ledger residues recorded in §5 were real, and
were corrected in the pre-Finance hardening phase by making `total_cost` authoritative. The
figures in §4 and §5 are the post-fix ones.

Nothing else. Every expected figure in this document matched the engine on the first run —
purchasing, sales, all three production types, dual-unit shell stock, lot traceability and
reversal behaviour all agree with one another.

---

## 8. Provisional figures used here

These are **configuration, not rules**, and are expected to change. They live in
`production_recipe_outputs.allocation_percent` and nowhere else.

| Run | Split | Status |
| --- | --- | --- |
| Dehusking | 85% dehusked / 15% husk | **Provisional** |
| Husk chipping | 30% 7C / 30% 9C / 40% 10C | **Provisional** |
| Copra | 75% copra / 25% shell | **Provisional** |

All three are placeholders for a **market-value / NRV** joint-product costing method
(`BUSINESS_RULES.md` §20 TBD-2). When that arrives, the numbers in this document change and
the posting actions do not.

Also still open and deliberately unmodelled: drying / moisture / process loss
(§20 TBD-3), chipping waste measurement basis (§19 TBD-1), and production expenses — no
labour, fuel, electricity, machine or overhead cost is added anywhere in this scenario.

---

## 9. Accounting

This scenario runs with **no accounting cutover set**, so it generates no journals — the
operational behaviour it validates is unchanged by Finance.

The accounting-enabled equivalent lives in
`tests/Feature/Finance/InventoryAccountingTest.php`, which walks the same purchase → sell →
dehusk → chip → dry sequence with a cutover date, an open fiscal period and the eight product
mappings in place, and asserts:

| Step | Journal |
| --- | --- |
| Buy 10,000 coconut | `Dr 1310` 1,025,000 · `Cr 2120 GRNI` 1,025,000 |
| Sell 2,000 coconut | `Dr 5110` 205,000 · `Cr 1310` 205,000 — **no revenue** |
| Dehusk 3,000 | `Dr 1320` 261,375 · `Dr 1330` 46,125 · `Cr 1310` 307,500 |
| Chip 2,000 husk | `Dr 1340` 40,000 · `Cr 1330` 40,000 |
| Dry 1,000 dehusked | `Dr 1350` 75,000 · `Dr 1360` 25,000 · `Cr 1320` 100,000 |

and that after **every** step the trial balance is exactly balanced, and each inventory
control account equals the inventory ledger value of the products mapped to it. Every amount
is the authoritative `total_cost` (§5) — which is precisely why the two ledgers reconcile to
the last decimal.

A warehouse transfer produces no journal at all: the company owns the same product, valued
the same, in the same account.
