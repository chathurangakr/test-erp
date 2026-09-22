# Accounting Rules — Coconut Factory ERP

> **Status:** ✅ **IMPLEMENTED** through Finance Phase 2. Phase 1 built the engine — chart of
> accounts, fiscal periods, manual journals, reversal, balances, general ledger, trial
> balance. Phase 2 connected it to inventory: goods receipts, deliveries and all three
> production types now generate journals, governed by a cutover date. See §13-§15.
>
> **Not built:** supplier invoices and payables, sales invoices and receivables, payments,
> tax, payroll, bank reconciliation, year-end closing. See §10.

---

## 1. Double-entry accounting

The system uses **double-entry accounting**. Every financial effect is recorded as balanced
debits and credits.

A journal line is a **debit OR a credit** — never both, never neither — and both columns
hold **positive** amounts. Direction is expressed by which column the amount sits in, not by
a sign. That makes an unbalanced entry a total that does not match, rather than a sign
convention someone has to remember, and it is what the trial balance reads directly.

## 2. The balance rule

**Every posted journal must satisfy:**

```
Total Debit = Total Credit
```

Enforced by `App\Actions\Accounting\PostJournalEntry`, inside one database transaction,
**before** the status changes — so an unbalanced journal can never be persisted. The full
list of rules checked at posting:

| # | Rule |
| --- | --- |
| 1 | The entry is a DRAFT and has not been posted before |
| 2 | At least **two** lines |
| 3 | Every account exists and is **active** |
| 4 | For a **manual** entry, every account accepts manual posting (§6) |
| 5 | Every line is a debit **or** a credit — never both |
| 6 | Every line carries an amount — never zero on both sides |
| 7 | No negative debit or credit |
| 8 | Total debits > 0 and total credits > 0 |
| 9 | **Total debits == total credits, exactly** |
| 10 | The fiscal period is **OPEN** |
| 11 | The journal date falls **inside** that period |

A DRAFT may legitimately be out of balance while someone is working on it. The rule applies
at posting, because posting is what puts the entry into the books.

## 3. Balances are derived, never stored

**Never directly modify account balances.** There is no balance column on `accounts`, and
there must never be one — a stored balance has no history, and when it drifts there is no
way to discover when or why. This is the same rule as inventory's ledger-derived stock.

`App\Services\Accounting\AccountingBalanceService` computes every figure from
`journal_entry_lines` on request: debit and credit totals, net balance, balance on the
account's normal side, balance as of a date, balance for a fiscal period, balances by
account type, the general ledger and the trial balance.

Only entries that reached the books count: **POSTED**, and **REVERSED** (whose own lines
remain posted and are cancelled by the reversing entry). A DRAFT or CANCELLED entry
contributes nothing.

**The general ledger's running balance is calculated per request and never persisted.** It
is a presentation of the lines, not a fact about the account.

## 4. Precision

- All monetary columns are **`decimal(20,6)`**.
- **Never float or double.** All arithmetic runs through `App\Support\DecimalMath` (BCMath).
  Floating point would eventually produce a journal whose two sides differ by a fraction of
  a cent — unrecoverable in a ledger.
- The scale matches the inventory ledger's cost scale **on purpose** — see §8.

## 5. Immutability and reversal

- A **posted** journal entry is immutable. The model refuses any update beyond its reversal
  audit pointers, and refuses deletion of anything but a draft. Its **lines** are equally
  frozen, so history cannot be edited from underneath the header.
- Corrections are made by **posting a reversing entry**, never by editing.
- `ReverseJournalEntry` creates a **new** entry mirroring every line with debit and credit
  **swapped, at the exact original amounts**. Nothing is recalculated, so the reversal
  cancels the original to the last decimal.
- The original is marked **REVERSED**, and the pair link both ways
  (`reversal_of_id` / `reversed_by_id`). A unique index makes a second reversal impossible
  at the database level, not only in code.
- **A reversal cannot itself be reversed.**
- A reversal posts into the open period containing **its own** date — by default today, not
  the original's. A closed period has already been reported on, and reaching back into one
  would change a published figure.

```
Original:   Dr Electricity 10,000   Cr Cash        10,000
Reversal:   Dr Cash        10,000   Cr Electricity 10,000
Net effect: nothing — and both sides stay on the record.
```

**CANCELLED and REVERSED are different states**, as everywhere in this system: a cancelled
entry never reached the books; a reversed one did and was undone. Neither deletes anything.

## 6. Accounts

| Field | Meaning |
| --- | --- |
| `account_type` | ASSET / LIABILITY / EQUITY / REVENUE / COST_OF_SALES / EXPENSE |
| `normal_balance` | DEBIT or CREDIT — stored explicitly, defaulted by type |
| `is_control_account` | Reconciled from a subsidiary ledger |
| `allow_manual_posting` | Whether a person may put it on a manual journal |

**Why `COST_OF_SALES` is its own type, not an EXPENSE subtype.** Gross profit — revenue less
cost of sales, before operating expenses — is already a first-class figure in this system
(`DeliveryLine::grossProfit()`). As a subtype, every future report would have to know a
magic value to draw the line in the right place. Both post on the DEBIT side, so the
separate case costs nothing and prevents a class of misreporting.

**Two kinds of account refuse manual posting:**

- **Control accounts** — Trade Receivables, Trade Payables and the six inventory accounts.
  They are reconciled from a subsidiary ledger, and a hand-written line would put the
  control account and its subsidiary out of step with nothing to explain the difference.
  When AP, AR and inventory accounting arrive, their **system actions** will post here: an
  entry that names a `source_type` is allowed to.
- **Group headers** — 1000, 1100, 1300 and so on. Posting to a header *and* its children
  would double-count in any report that rolls them up.

**Accounts are deactivated, never deleted.** An account with journal history refuses
deletion outright; historic lines must keep referring to something meaningful. There is no
destroy route anywhere in Finance.

## 7. Fiscal periods

- A journal may only be posted into an **OPEN** period whose date range **contains** the
  journal date.
- A period cannot be **closed** while it still holds a DRAFT entry — the draft would become
  permanently unpostable and invisible to every report, a silent loss of work.
- Periods must **not overlap**: a date inside two of them would have no single answer to
  which period it belongs to.
- **Reopening is deliberately not implemented.** If the business ever needs it, it becomes
  an explicit, audited action rather than a status flip.
- Nothing assumes a calendar year. Start and end dates are arbitrary, because a factory's
  reporting year is a business decision.
- **No year-end closing entries yet** — rolling the income statement into retained earnings
  is a later phase.

## 8. Inventory value is authoritative

The amount posted for any stock movement is the inventory ledger's **authoritative
`total_cost`** — never a value rebuilt from a rounded per-unit figure.

```
Inventory line:   total_cost = Rs.16,000.000000   (280 KG at Rs.57.142857)
Finance posts:    Rs.16,000.000000
NOT:              280 × 57.142857 = Rs.15,999.999960
```

This is why the accounting scale is `decimal(20,6)`, matching the ledger exactly: the figure
transfers without rounding, which is what lets an inventory control account reconcile to the
inventory ledger to the last decimal (§14). See `INVENTORY_RULES.md` §8.1 and `DATA_MODEL.md` **I-4**.

## 9. Currency

**Version 1 keeps every book in one currency: LKR.**

It lives in `config/accounting.php` as `base_currency`, not as a literal in business logic,
so introducing a second currency is a schema and configuration change rather than a hunt
through the codebase.

> `TBD` — multi-currency: which rates, revalued when, and gains/losses posted where. Not in
> scope for Version 1.

## 10. Not implemented — do not begin without instruction

Accounts Payable · Accounts Receivable · supplier payments · customer receipts · purchase
invoices · sales invoices · production expense accounting · VAT/SVAT · payroll · bank
reconciliation · financial dashboards · P&L · balance sheet · year-end closing.

**No revenue is recognised anywhere yet.** A delivery records cost of goods sold and nothing
else; revenue and receivables belong to the sales invoice.

Until those arrive, two balances are expected to sit and grow:

- **GRNI** accumulates the purchase accrual, because no supplier invoice clears it;
- **Cost of sales** accumulates without matching revenue.

That is correct for this phase, not a defect.

## 11. Auditability

- Every entry records `created_by`, and on posting `posted_by` / `posted_at`; on reversal
  `reversed_by_user_id` / `reversed_at`.
- `source_type` / `source_id` exist now, unused by manual entries, so a generated journal
  can name its document without a schema change later.
- **Audit history is never deleted.** Posted journals are never silently modified.
- Journal numbers are `JV-YYYYMMDD-000001`, generated by the shared
  `App\Support\DailyDocumentSequence` under a locked read, and encode no business meaning.

## 12. Remaining open questions (`TBD`)

| # | Question | Blocks |
| --- | --- | --- |
| 1 | When are supplier and sales invoices recorded, and how is GRNI cleared? | AP / AR |
| 2 | Single currency or multi-currency, and on what revaluation basis? | Multi-currency |
| 3 | Is tax (VAT/SVAT) in scope, and at what rates? | Tax |
| 4 | Which joint-product allocation method — market value, NRV, or other? | Costing |
| 5 | What are the year-end closing rules, and which retained-earnings account? | Year-end |
| 6 | Should periods ever be reopenable, and under what authority? | Fiscal periods |
| 7 | What roles may post, reverse and close periods? | Permissions |

## 13. Operational accounting — the cutover

Stock movements generate journals only when `accounting_settings.operational_accounting_start_date`
is set and the document falls **on or after** it.

| Cutover | Behaviour |
| --- | --- |
| **NULL** (the default) | No operational journal at all. Every module behaves exactly as it did before Finance existed. |
| Set | A document dated on or after it **must** journal. Earlier documents never do. |

**The date is never invented.** No seeder sets it; an accountant does, in Accounting Setup.
That is what lets accounting be switched on against a system already holding months of
operational history: nothing is backfilled, and nothing already posted is reinterpreted.

**Once any operational journal exists the date is frozen.** Moving it later would orphan
documents already accounted for; moving it earlier would leave a gap of documents that
should have been and never will be. Both leave the ledger and the books permanently
disagreeing, in a way no later report could detect — so the change is refused outright
rather than reasoned about.

**A document that requires accounting needs an OPEN fiscal period containing its date**, or
the entire operational posting fails: no stock, no lot, no journal, document still a draft.
Posting stock while its journal silently went missing is the one outcome that cannot be
corrected afterwards.

## 14. Which event posts what

| Event | Journal |
| --- | --- |
| **Goods receipt** posted | `Dr` product inventory · `Cr` Goods Received Not Invoiced |
| **Delivery** posted | `Dr` product cost of sales · `Cr` product inventory |
| **Production** posted | `Dr` output inventory · `Cr` input inventory |
| **Warehouse transfer** | **No journal.** The company owns the same product, valued the same, in the same account, before and after. Quantity, value and the weighted average are all unchanged, so there is nothing to record. |
| Any **reversal** | The original journal reversed exactly, through `ReverseJournalEntry`. |

```
GRN          Dr 1310 Whole Coconut Inventory   1,025,000
                 Cr 2120 GRNI                             1,025,000

Delivery     Dr 5110 Coconut COGS                205,000
                 Cr 1310 Whole Coconut Inventory   205,000

Dehusking    Dr 1320 Dehusked Coconut Inventory  261,375
             Dr 1330 Raw Husk Inventory            46,125
                 Cr 1310 Whole Coconut Inventory   307,500

Chipping     Dr 1340 Husk Chips Inventory         40,000
                 Cr 1330 Raw Husk Inventory        40,000

Copra        Dr 1350 Copra Inventory              75,000
             Dr 1360 Raw Shell Inventory          25,000
                 Cr 1320 Dehusked Coconut Inventory 100,000
```

**Every amount is the inventory ledger's authoritative `total_cost`** (§8). Finance never
recalculates anything: not the landed-cost allocation, and not the 85/15, 30/30/40 or 75/25
production splits. Those decided the inventory valuation upstream; the books record what the
ledger already holds, which is why debits and credits agree to the last decimal.

Where several products map to one account — the three chip grades all use 1340 — the journal
keeps **separate lines with product descriptions** rather than aggregating. The totals are
identical either way; the separate lines are traceable.

**Raw shell is valued by its count.** Its measured weight is inventory information and has no
effect on any accounting amount.

### Operational posting and accounting are one transaction

The journal is written inside the operational action's own database transaction. If the
journal is refused — an unmapped product, a closed period, no open period — **the stock
posting rolls back with it**. There is never posted inventory without its journal, or a
journal without its posting.

### Source linking

Every generated journal carries `source_type` / `source_id` naming its document, which is
both what makes it traceable and what allows it to touch control accounts (§6). A source has
**at most one original journal**; a retry is refused rather than quietly doubling the books.
Reversals carry the same source, so both halves of a correction are findable from the
document.

## 15. Product account mappings

`product_account_mappings` is the only place a product meets the chart of accounts. Nothing
in purchasing, sales, production or inventory knows an account code.

| Mapping | Must be |
| --- | --- |
| `inventory_account_id` | an active **ASSET** account |
| `cogs_account_id` | an active **COST_OF_SALES** account |
| `revenue_account_id` | an active **REVENUE** account (configured now, unused until sales invoicing) |
| GRNI (in settings) | an active **LIABILITY** account |

The eight seeded products are mapped by `ProductAccountMappingSeeder`. **A product created by
hand is deliberately left unmapped.** Once accounting is switched on, moving its stock is
**refused** — a wrong account written permanently into the books is worse than a failed
posting — and it appears as *Unmapped* in Accounting Setup.

### The future flow these accounts are for

```
GRN               Dr Inventory        Cr GRNI              ← implemented
Supplier invoice  Dr GRNI             Cr Trade Payables    ← later
Supplier payment  Dr Trade Payables   Cr Cash / Bank       ← later

Delivery          Dr Cost of sales    Cr Inventory         ← implemented
Sales invoice     Dr Trade Receivables Cr Revenue          ← later
Customer receipt  Dr Cash / Bank      Cr Trade Receivables ← later
```
