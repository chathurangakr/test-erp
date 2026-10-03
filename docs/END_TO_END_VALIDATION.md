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

Thirteen products, matching the seeded Product Master — with their real units **and their
lot policy** (`tracks_lots`):

| Code | Primary | Secondary | Lots |
| --- | --- | --- | --- |
| `COCONUT` | NOS | — | pooled (none) |
| `DEHUSKED-COCONUT` | NOS | — | pooled (none) |
| `RAW-HUSK` | NOS | — | pooled (none) |
| `COPRA` | KG | — | **tracked** |
| `HUSK-7C` / `HUSK-9C` / `HUSK-10C` / `COCOPEAT` | KG | — | pooled (none) |
| `HUSK-7C-L` / `HUSK-9C-L` / `HUSK-10C-L` / `COCOPEAT-L` | **L** | — | pooled (none) |
| `RAW-SHELL` | **NOS** | **KG** | **tracked** |

Plus `TEST-SUPPLIER`, `TEST-CUSTOMER`, and **two** warehouses: `MAIN` (default) and
`STORE`. Two warehouses exist on purpose — the weighted average is company-wide, so a
purchase into `STORE` must blend with production output in `MAIN`, and warehouse totals
must still reconcile to company totals.

---

## 3. The scenario

```
Supplier ──▶ buy 10,000 coconut ──┬──▶ sell 2,000 whole
                                  │
                                  └──▶ dehusk 3,000 (labour Rs.2.50) ──┬──▶ dehusked 3,000 ──┬──▶ sell 1,000
                                                                       │                     │
                                                                       │                     └──▶ copra run 1,000
                                                                       │                            ├─▶ copra 190.500 kg ──▶ sell 90.500 kg
                                                                       │                            └─▶ shell 1,000 NOS  ──▶ sell 500 NOS
                                                                       │                                / 420.750 kg           / 208.750 kg
                                                                       └──▶ raw husk 3,000 ──┐
Supplier ──▶ buy 5,000 raw husk (into STORE) ────────────────────────────────────────────────┴──▶ ONE husk pool, 8,000
                                                                                                    │
chipping run (takes NO husk): 7C 150.500 kg · 9C 205.250 kg · 10C 281.750 kg · cocopeat 100 L      │
                              └─▶ sell 50 kg 7C, "Raw Husks Used" 180 ◀─────── backflushed ───────┘
```

> **Why these figures changed.** This scenario was first written under the version 1 recipes
> (dehusking 85 / 15, chipping consuming husk and splitting 30 / 30 / 40, lots everywhere).
> The owner then changed the rules (Phase 19): dehusked coconut carries the whole coconut
> value and raw husk carries the dehusking labour; chipping takes no husk and the husk is
> backflushed when the outputs are delivered; coconut, husk and chip products are pooled
> stock. The new figures follow from **those rule changes** — not from modules disagreeing.
> Every figure below was worked by hand before the test was run, and the engine matched.

### Step 1 — buy whole coconut

10,000 `COCONUT` @ Rs.100, transport Rs.20,000, loading Rs.5,000.

| | |
| --- | --- |
| Material cost | Rs.1,000,000.000000 |
| Landed cost allocated | Rs.25,000.000000 |
| **Final unit cost** | **Rs.102.500000** — the landed cost is what reaches inventory |
| Stock | 10,000.000 NOS, value Rs.1,025,000.000000 |
| Lot | **none** — coconut is pooled; the ledger transaction traces to the receipt |

### Step 2 — sell whole coconut

2,000 @ Rs.130.

| | |
| --- | --- |
| Revenue | Rs.260,000.000000 |
| Inventory unit cost | Rs.102.500000 — taken from the ledger, never typed |
| COGS | Rs.205,000.000000 |
| Gross profit | Rs.55,000.000000 |
| Stock after | 8,000.000 NOS @ **Rs.102.500000** — selling at 130 moved the average not at all |

### Step 3 — dehusking (fixed 1:1:1, all outputs derived; labour Rs.2.50 per coconut)

3,000 coconut → 3,000 dehusked + 3,000 raw husk. Consumed `3,000 × 102.50 = Rs.307,500`;
labour `3,000 × 2.50 = Rs.7,500`.

| Output | Share | Allocated | Of which labour | Unit cost |
| --- | --- | --- | --- | --- |
| `DEHUSKED-COCONUT` 3,000 NOS | 100% | Rs.307,500.000000 | — | **Rs.102.500000** |
| `RAW-HUSK` 3,000 NOS | 0% | Rs.7,500.000000 | Rs.7,500.000000 | **Rs.2.500000** |
| | | **Rs.315,000.000000** | | = consumed + labour, exactly |

Coconut falls to 5,000.000 NOS @ Rs.102.500000. Both outputs are pooled: no lots.

> A dehusking draft carries **no** output rows: every quantity is derived from the recipe
> and the posting action writes the rows itself. A draft that did carry them is
> **refused** — see §7.

### Step 4 — sell dehusked coconut

1,000 @ Rs.150.

| | |
| --- | --- |
| Revenue | Rs.150,000.000000 |
| Inventory unit cost | **Rs.102.500000** — the whole coconut cost travels with the dehusked coconut |
| COGS | Rs.102,500.000000 |
| Gross profit | Rs.47,500.000000 |

### Step 5 — buy raw husk at a different cost, into a different warehouse

5,000 `RAW-HUSK` @ Rs.20, no landed cost, received into **`STORE`** (supplier lot
`SUP-HUSK-01` kept on the receipt line as information).

| | |
| --- | --- |
| From dehusking | 3,000 NOS @ Rs.2.500000 = Rs.7,500 (labour), in `MAIN` |
| Purchased | 5,000 NOS @ Rs.20.000000 = Rs.100,000, in `STORE` |
| Company total | **one pool**: 8,000 NOS, Rs.107,500 |
| **Pool WAC** | **Rs.13.437500** — one average across both warehouses and both origins |

No raw husk lot exists at all.

### Step 6 — husk chipping (measured outputs, takes NO husk)

| Output | Measured | Value |
| --- | --- | --- |
| `HUSK-7C` | 150.500 KG | Rs.0.000000 |
| `HUSK-9C` | 205.250 KG | Rs.0.000000 |
| `HUSK-10C` | 281.750 KG | Rs.0.000000 |
| `COCOPEAT-L` | 100.000 **L** | Rs.0.000000 |

No `PRODUCTION_CONSUMPTION` is written and the husk pool stays at 8,000. The outputs carry
their raw husk cost **pending backflush**. Cocopeat in litres is its own stock item:
`COCOPEAT` (KG) stays at zero. No husk-to-KG, husk-to-litre or KG-to-litre ratio exists
anywhere.

### Step 7 — copra production (one derived count, two measured weights)

1,000 dehusked coconut (pooled, no lot). Consumed `1,000 × 102.50 = Rs.102,500.000000`.

| Output | Quantity | Source | Share | Allocated | Unit cost |
| --- | --- | --- | --- | --- | --- |
| `COPRA` | 190.500 KG | **measured** | 75% | Rs.76,875.000000 | Rs.403.543307 / KG |
| `RAW-SHELL` | 1,000 NOS | **derived 1:1** | 25% | Rs.25,625.000000 | Rs.25.625000 / NOS |
| | 420.750 KG | **measured** | | | *(informational per-KG only)* |
| | | | | **Rs.102,500.000000** | = consumed, exactly |

Copra and shell still get lots, each recording the copra run as its source. Shell is valued
against its **primary NOS quantity**.

### Step 8 — sell finished goods

| Sale | Basis | Revenue | Unit cost | Husk backflushed | COGS |
| --- | --- | --- | --- | --- | --- |
| 50.000 KG `HUSK-7C` @ 200, **Raw Husks Used 180** | primary | Rs.10,000.000000 | Rs.0.000000 | 180 × 13.4375 = **Rs.2,418.750000** | **Rs.2,418.750000** |
| 90.500 KG `COPRA` @ 500 | primary | Rs.45,250.000000 | Rs.403.543307 | — | Rs.36,520.669284 |
| 500 NOS `RAW-SHELL` / **208.750 KG** @ 80 | **secondary** | **Rs.16,700.000000** | Rs.25.625000 | — | **Rs.12,812.500000** |

The chip sale writes a separate `BACKFLUSH_ISSUE` transaction (source: the delivery) taking
180 husks out of `MAIN`, inside the same database transaction as the sale. Gross profit on the
chips is Rs.7,581.25.

The shell sale is still the sharpest test of units:

- **Revenue follows the weight** — `208.750 × 80 = Rs.16,700`.
- **Cost follows the count** — `500 × Rs.25.625`, because the count is what left stock.
- **The weight is measured, not apportioned.** Half the shells by count would have been
  `210.375 KG` pro rata. The actual figure is `208.750 KG`, and stock records exactly that.

---

## 4. Final balances

| Product | Quantity | Value | WAC |
| --- | --- | --- | --- |
| `COCONUT` | 5,000.000 NOS | Rs.512,500.000000 | Rs.102.500000 |
| `DEHUSKED-COCONUT` | 1,000.000 NOS | Rs.102,500.000000 | Rs.102.500000 |
| `RAW-HUSK` | 7,820.000 NOS | Rs.105,081.250000 | Rs.13.437500 |
| `HUSK-7C` | 100.500 KG | Rs.0.000000 | — *(husk cost pending)* |
| `HUSK-9C` | 205.250 KG | Rs.0.000000 | — *(husk cost pending)* |
| `HUSK-10C` | 281.750 KG | Rs.0.000000 | — *(husk cost pending)* |
| `COCOPEAT-L` | 100.000 L | Rs.0.000000 | — *(husk cost pending)* |
| `COCOPEAT` | 0.000 KG | Rs.0.000000 | — |
| `COPRA` | 100.000 KG | Rs.40,354.330716 | Rs.403.543307 |
| `RAW-SHELL` | 500.000 NOS **/ 212.000 KG** | Rs.12,812.500000 | Rs.25.625000 |

Raw husk by warehouse: `MAIN` 2,820 (3,000 from dehusking − 180 backflushed) + `STORE`
5,000 = 7,820 company-wide.

---

## 5. Value conservation — exact

Every production run conserves value **exactly**, at both levels, with labour as the only
addition:

```
input total_cost + labour            ==  Σ output allocated_cost         (document)
PRODUCTION_CONSUMPTION total_cost
                         + labour    ==  Σ PRODUCTION_OUTPUT total_cost  (ledger)
```

| Run | Consumed | Labour | Ledger output | Residue |
| --- | --- | --- | --- | --- |
| Dehusking | Rs.307,500.000000 | Rs.7,500.000000 | Rs.315,000.000000 | **0.000000** |
| Husk chipping | — (nothing consumed) | — | Rs.0.000000 | **0.000000** |
| Copra | Rs.102,500.000000 | — | Rs.102,500.000000 | **0.000000** |

The husk value a chipping run does not take stays in Raw Husk inventory until a delivery
backflushes it, at which point it moves straight into cost of goods sold.

> **Historical note.** The first run of this validation recorded residues of 0.000013 and
> 0.000004, because the ledger rebuilt each line's value as `quantity × unit_cost`. That was
> fixed before Finance: **`total_cost` is the authoritative monetary value** of a ledger
> line and `unit_cost` is only its rounded per-unit representation. See
> `INVENTORY_RULES.md` §8.1 and `DATA_MODEL.md` **I-4**.

What the test asserts for every ledger line in the scenario:

```
quantity > 0,  total_cost >= 0,  unit_cost >= 0
unit_cost == round(total_cost / quantity, 6)
|total_cost − quantity × unit_cost| <= quantity × 0.0000005
```

## 6. What else is asserted

| Area | Assertion |
| --- | --- |
| **Traceability — copra** | supplier → GRN (ledger source) → pooled coconut → dehusking run (consumption and output transactions) → pooled dehusked coconut → copra run → {copra lot, shell lot} → deliveries. Each output lot records the copra run as its source |
| **Traceability — husk** | husk enters the pool from the dehusking run's output and from the GRN; the chipping run's output transaction holds **no** raw husk line; the chip delivery's `BACKFLUSH_ISSUE` (source: the delivery) is the document that took 180 husks out of `MAIN`, with no lot |
| **Reversal safety** | All three production runs have had output sold or consumed, so **all three reversals are refused**. Status stays `POSTED`, both reversal audit fields stay `NULL`, transaction count is unchanged, and every product's stock is identical before and after |
| **GRN reversal safety** | The coconut receipt cannot be reversed either — its stock has been sold and dehusked |
| **Negative stock** | No product, no lot and no product+warehouse balance is negative |
| **Warehouse reconciliation** | For every product, Σ(warehouse quantity) == company quantity, and Σ(warehouse value) == company value |
| **WAC reconciliation** | For every product, `round(value ÷ quantity, 6)` equals the reported weighted average |
| **Ledger integrity** | Every transaction has lines and a date; every line has a warehouse, a positive quantity, non-negative costs, a `unit_cost` equal to `round(total_cost ÷ quantity, 6)`, and a `total_cost` differing from `quantity × unit_cost` by no more than that rounding |
| **Exact conservation** | Dehusking and copra: consumption + labour == output, residue `0.000000`; chipping: no consumption and every output line at `0.000000` |
| **No orphans** | Every lot has a product, a source document that exists, and at least one movement. Every document line belongs to an existing document. Every ledger transaction points at an existing source |
| **Posted → transaction** | Every posted GRN, delivery and production order points at a real inventory transaction of the right type; dehusking and copra have a consumption, chipping has none; only the chip sale has a `BACKFLUSH_ISSUE` |
| **Immutability** | A posted GRN, delivery and production order all refuse `update()` and `delete()` |
| **Drafts** | A draft GRN, delivery, dehusking order and chipping order together create no transaction, no lot and no stock movement |
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

These are **configuration, not rules**, and are expected to change. They live in the recipe
tables (`production_recipes`, `production_recipe_outputs`) and nowhere else.

| Run | Rule (recipe) | Status |
| --- | --- | --- |
| Dehusking (v2) | 100% dehusked / 0% husk + labour to husk; labour rate typed per run (default NULL) | **Confirmed** method; the standard rate is `TBD` |
| Husk chipping (v2) | No consumption; husk backflushed at delivery | **Confirmed** |
| Copra (v1) | 75% copra / 25% shell | **Provisional** |

The labour rate of Rs.2.50 is this test's own figure, not the factory's. The copra split is a
placeholder for a **market-value / NRV** joint-product costing method (`BUSINESS_RULES.md`
§20 TBD-2).

Also still open and deliberately unmodelled: drying / moisture / process loss
(§20 TBD-3), chipping waste measurement basis (§19 TBD-1), and production expenses other than
dehusking labour — no fuel, electricity, machine or overhead cost is added anywhere.

---

## 9. Accounting

This scenario runs with **no accounting cutover set**, so it generates no journals — the
operational behaviour it validates is unchanged by Finance.

The accounting-enabled equivalent lives in
`tests/Feature/Finance/InventoryAccountingTest.php`, which walks the same purchase → sell →
dehusk → chip → dry sequence with a cutover date, an open fiscal period and the product
mappings in place, and asserts:

| Step | Journal |
| --- | --- |
| Buy 10,000 coconut | `Dr 1310` 1,025,000 · `Cr 2120 GRNI` 1,025,000 |
| Sell 2,000 coconut | `Dr 5110` 205,000 · `Cr 1310` 205,000 — **no revenue** |
| Dehusk 3,000 (labour Rs.2) | `Dr 1320` 307,500 · `Dr 1330` 6,000 · `Cr 1310` 307,500 · `Cr 6310` 6,000 |
| Chip (no husk taken) | **no journal** — nothing of value moved |
| Dry 1,000 dehusked @ Rs.100 | `Dr 1350` 75,000 · `Dr 1360` 25,000 · `Cr 1320` 100,000 |
| Deliver chips with backflushed husk | `Dr 5130` (or `5170` for cocopeat) · `Cr 1330`, at the husk's weighted average |

and that after **every** step the trial balance is exactly balanced, and each inventory
control account equals the inventory ledger value of the products mapped to it. Every amount
is the authoritative `total_cost` (§5) — which is precisely why the two ledgers reconcile to
the last decimal.

A warehouse transfer produces no journal at all: the company owns the same product, valued
the same, in the same account.

---

## 10. The husk workflow with the books switched on

`tests/Feature/Integration/HuskProductionWorkflowTest.php` walks the husk side of the factory
with **accounting enabled** (cutover 2026-09-01, an open September 2026 period, the real
seeded master data, chart and mappings) and proves stock, books, payables and receivables
agree.

```
buy 100 coconut @ Rs.50 ──▶ dehusk 100 @ Rs.3 labour ──┬──▶ 100 dehusked   Rs.5,000
                                                       └──▶ 100 raw husk   Rs.  300 (labour)
buy 500 raw husk @ Rs.12 ──────────────────────────────────▶ pool: 600 husks, Rs.6,300, WAC Rs.10.50
chip (takes NO husk): 7C 40 KG · 7C 100 L · cocopeat 100 L           (zero value, no journal)
deliver 20 KG 7C @ Rs.300, Raw Husks Used 180 ─────────────▶ pool: 420 husks, Rs.4,410
```

| Check | Figure |
| --- | --- |
| Dehusking | input Rs.5,000 + labour Rs.300 = output Rs.5,300 |
| Dehusking journal | `Dr 1320` 5,000 · `Dr 1330` 300 · `Cr 1310` 5,000 · `Cr 6310` 300 — four lines, no cash, no payable |
| `6310` balance | −300 (a credit on the contra-expense) |
| Husk pool | 600 NOS, Rs.6,300.000000, WAC Rs.10.500000; no lots |
| Chipping | no consumption, no journal; 7C 40 KG and 7C-L 100 L are separate balances; `COCOPEAT` (KG) stays 0 |
| Chip delivery | sales Rs.6,000; backflush 180 × 10.50 = **Rs.1,890**; COGS Rs.1,890; gross profit Rs.4,110 |
| Delivery journal | two lines: `Dr 5130` 1,890 · `Cr 1330` 1,890 (the zero-value chip issue writes none) |
| After | pool 420 NOS, Rs.4,410; `1330` == raw husk ledger value; `1340` and `1370` == 0 |
| Not enough husk | a delivery stating 601 husks is refused under `lines.0.backflush_quantity`; no transaction, no journal, still a draft, stocks unchanged |
| Cocopeat | 50 L delivered with 60 husks → `5170` Rs.630 |
| Invoice on credit, part cash | invoice Rs.8,000 (chips 6,000 → `4130`; cocopeat 2,000 → `4170`); receipt **Cash** Rs.2,500 → outstanding Rs.5,500, PARTIALLY_PAID; `1210` == 5,500; `1110` == 2,500 |
| Supplier, part cheque | one invoice clearing both GRNs (5,000 + 6,000 = 11,000); payment **Cheque** 000123 Rs.7,000 from `1120` → outstanding Rs.4,000, PARTIALLY_PAID; `2110` == 4,000; `2120` GRNI == 0; `1120` == −7,000 |

Further rules are proven in focused suites: `tests/Feature/Sales/DeliveryBackflushTest.php`
(averages, KG/L twins, required husks, wrong warehouse, reversal at original cost, the
delivery screen), `tests/Feature/Inventory/PooledStockTest.php` (no lots for pooled products,
the one-time consolidation command) and `tests/Feature/Finance/PaymentMethodTest.php` (cash /
cheque / bank transfer rules).
