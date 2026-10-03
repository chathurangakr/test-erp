# Accounting Rules — Coconut Factory ERP

> **Status:** ✅ **IMPLEMENTED** through Finance Phase 6. Phase 1 built the engine — chart of
> accounts, fiscal periods, manual journals, reversal, balances, general ledger, trial
> balance. Phase 2 connected it to inventory behind a cutover date. Phase 3 added accounts
> payable, Phase 4 accounts receivable, Phase 5 cash and bank management. Phase 6 added the
> financial statements (profit and loss, balance sheet) and the management reports — every
> one derived from posted ledgers, none stored. See §13-§28. The husk rework added payment
> methods with cheque details (§29) and production labour plus raw-material backflushing
> (§30).
>
> **Not built:** tax, payroll, bank reconciliation, year-end closing, cash flow statement,
> report export. See §10.

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

- **Control accounts** — Trade Receivables, Trade Payables, the seven inventory accounts
  (1310-1370) and 6310 Production Labour Absorbed (§30).
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

Credit notes · sales returns and allowances · customer advances and deposits · supplier
advances · production expense accounting (beyond dehusking labour, §30) · VAT/SVAT · payroll · bank reconciliation ·
cash flow statement · report export (PDF/Excel) · year-end closing · loans · fixed assets ·
depreciation.

**A delivery still recognises no revenue.** It records cost of goods sold and nothing else;
revenue and the receivable belong to the sales invoice (§20). A delivery that is never
invoiced therefore shows cost with no matching revenue — which is correct, and the
unbilled-deliveries report exists to surface it.

**Amounts are tax-unmodelled.** An invoice amount is what the supplier charged; no input-tax
account exists and none is created. VAT/SVAT is TBD.

**Base currency only.** Every AP amount is in LKR. There are no exchange-rate fields.

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
| **Production** posted | `Dr` output inventory · `Cr` input inventory · `Cr` 6310 Production Labour Absorbed for any labour (§30). A run that moves no value — husk chipping — writes **no journal** |
| **Delivery** backflushing raw material | additionally `Dr` the **delivered product's** cost of sales · `Cr` the raw material's inventory (§30) |
| **Warehouse transfer** | **No journal.** The company owns the same product, valued the same, in the same account, before and after. Quantity, value and the weighted average are all unchanged, so there is nothing to record. |
| Any **reversal** | The original journal reversed exactly, through `ReverseJournalEntry`. |

```
GRN          Dr 1310 Whole Coconut Inventory   1,025,000
                 Cr 2120 GRNI                             1,025,000

Delivery     Dr 5110 Coconut COGS                205,000
                 Cr 1310 Whole Coconut Inventory   205,000

Dehusking    Dr 1320 Dehusked Coconut Inventory  307,500
             Dr 1330 Raw Husk Inventory             7,500     ← labour, 3,000 × 2.50
                 Cr 1310 Whole Coconut Inventory   307,500
                 Cr 6310 Production Labour Absorbed  7,500

Chipping     (no journal — no husk is consumed; chips enter at zero, cost pending)

Chip sale    Dr 5130 Husk Chip COGS                2,418.75  ← 180 husks backflushed
                 Cr 1330 Raw Husk Inventory          2,418.75

Copra        Dr 1350 Copra Inventory              75,000
             Dr 1360 Raw Shell Inventory          25,000
                 Cr 1320 Dehusked Coconut Inventory 100,000
```

**Every amount is the inventory ledger's authoritative `total_cost`** (§8). Finance never
recalculates anything: not the landed-cost allocation, not the production splits, not the
labour, not a backflushed cost. Those decided the inventory valuation upstream; the books record what the
ledger already holds, which is why debits and credits agree to the last decimal.

Where several products map to one account — the three chip grades and their litre items all
use 1340 — the journal
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

The thirteen seeded products are mapped by `ProductAccountMappingSeeder`:

| Products | Inventory | COGS | Revenue |
| --- | --- | --- | --- |
| `COCONUT` | 1310 | 5110 | 4110 |
| `DEHUSKED-COCONUT` | 1320 | 5120 | 4120 |
| `RAW-HUSK` | 1330 | 5160 | 4160 |
| `HUSK-7C`, `HUSK-9C`, `HUSK-10C` and `HUSK-7C-L`, `HUSK-9C-L`, `HUSK-10C-L` | 1340 | 5130 | 4130 |
| `COCOPEAT`, `COCOPEAT-L` | **1370 Cocopeat Inventory** | **5170 Cocopeat COGS** | **4170 Cocopeat Sales** |
| `COPRA` | 1350 | 5140 | 4140 |
| `RAW-SHELL` | 1360 | 5150 | 4150 |

A litre stock item is the same grade measured another way, so it shares its grade's
accounts. Cocopeat is its own product with its own accounts, so its profit reports
separately. The seeder also points `accounting_settings.production_labour_account_id` at
6310. **A product created by
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

## 16. Accounts payable

```
Goods receipt      Dr Inventory              Cr Goods Received Not Invoiced   ← Phase 2
Supplier invoice   Dr GRNI  or  Dr Expense   Cr Trade Payables                ← this phase
Supplier payment   Dr Trade Payables         Cr Cash / Bank                   ← this phase
```

A supplier invoice has two kinds of line, and only two:

| Line type | Debits | Meaning |
| --- | --- | --- |
| `GRNI_MATCH` | Goods Received Not Invoiced | Clears part of a goods receipt's accrual |
| `DIRECT_EXPENSE` | The chosen expense account | An invoice with no goods behind it — electricity, repairs |

Both credit **Trade Payables**, which comes from `accounting_settings` and never appears on a
line: a user says what is being charged, never where the credit lands. A direct-expense line
is restricted to ordinary expense accounts — it must never become a way to reach an inventory
or payables control account by hand.

### Posting an invoice changes no inventory

Not the quantity, not the ledger, not the weighted average, not a production cost. **The
goods receipt established what the stock is worth**; the invoice records who is owed for it.
This separation is what lets a receipt be invoiced by someone other than the party who
supplied the goods.

### No purchase-price variance

A `GRNI_MATCH` line clears the amount the user states, capped at what the receipt has left.
If the invoices never fully clear a receipt, **the remainder stays visible on the GRNI
reconciliation** rather than being written off to an expense account nobody asked for.

> `TBD` — the permanent purchase price variance / revaluation policy: whether a persistent
> difference should be expensed, capitalised into inventory, or left for manual judgement.

## 17. GRNI matching and reconciliation

**One goods receipt may be cleared by several suppliers.** A receipt carrying goods,
transport and loading accrues one figure but is invoiced by three different companies, so
matching is **per-amount and partial**, never one-invoice-per-receipt:

```
Accrued on receipt                    1,025,000
  Goods supplier invoice              1,000,000   →  remaining 25,000
  Transport supplier invoice             20,000   →  remaining  5,000
  Loading contractor invoice              5,000   →  remaining      0
```

**The original accrual is read from the receipt's own posted journal** — the credit to GRNI —
never re-derived from quantity × unit cost. The journal is what the books say; anything
re-derived would eventually disagree with them.

A receipt is matchable only when it is POSTED and raised an accrual that still stands. So a
**pre-cutover receipt cannot be matched** (it never raised one) and neither can a **reversed
receipt** (its accrual has been given back). Neither is backfilled.

Concurrent invoices cannot overdraw a receipt: the receipt rows are locked `FOR UPDATE` while
their remaining balance is checked.

### The critical invariant

```
GRNI general-ledger balance  ==  total accrual not yet invoiced
```

Because GRNI is a control account that refuses manual posting, a difference cannot come from
a stray journal — it would mean a genuine problem, and the reconciliation screen says so
rather than showing two numbers quietly.

### Two mutual blocking rules

- **A supplier invoice cannot be reversed while a posted payment is allocated to it.** Undoing
  the payable while money has been allocated would leave the payment settling something that
  no longer exists. Reverse the payments first.
- **A goods receipt cannot be reversed once a posted invoice has matched against it.**
  Reversing would remove an accrual accounts payable has already cleared, leaving Trade
  Payables owing money against a receipt the books say never happened. Reverse the invoice
  first — the error names it.

Reversing an invoice **releases its GRNI match automatically**: the reconciliation counts only
invoices standing in the books, so the accrual becomes uninvoiced again with nothing unwound
by hand.

## 18. Supplier payments and AP balances

A payment is **always allocated to invoices** — Version 1 has no prepayments or on-account
credits — so the allocations must total the payment exactly. One payment may settle several
invoices, and one invoice may be settled by several payments.

A payment **never touches GRNI** (the invoice already cleared it) and never touches inventory.
Its account must be an ordinary asset account — cash or bank — not a control account.

**AP balances are derived, never stored.** An invoice's outstanding balance is its total less
the allocations of payments that are still **posted**; a supplier has no payable column and
must never grow one. That is also what makes reversal clean: a reversed payment's allocations
stay on the record and simply stop counting, and each affected invoice's status
(POSTED / PARTIALLY_PAID / PAID) is recalculated from the restored balance.

**The second critical invariant:**

```
Trade Payables general-ledger balance  ==  total invoice outstanding
```

The aging report buckets outstanding amounts by how overdue they are — Current, 1-30, 31-60,
61-90, 90+ — as of any date. A reversed invoice appears nowhere in it; a reversed payment
stops reducing what is owed.

---

## 19. Reversal is an audit correction, not a credit note

This distinction matters commercially and is easy to get wrong, so it is stated once here and
enforced everywhere.

**Reversing a sales invoice says the invoice should never have existed.** It is an audit
correction — a keying error, the wrong customer, the wrong delivery, the wrong amount. It
mirrors the original journal exactly, at the original amounts, and leaves both entries on the
record for ever.

**A credit note says the invoice was correct but the commercial position has changed** — goods
came back, a discount was agreed after the fact, a quality allowance was granted. It is a new
commercial document with its own date, its own reason and its own journal, and the original
invoice remains valid.

Credit notes, sales returns and allowances are **not built**. Do not use a reversal to stand in
for one: it would remove revenue from the period the sale actually happened in and destroy the
audit trail of the concession. When the factory needs them they will be a separate feature with
their own documents.

Practical consequence: **an invoice cannot be reversed once a posted receipt is allocated to
it.** If money has been taken against an invoice, the invoice was real. Reverse the receipt
first, or — when the commercial position has genuinely changed — wait for credit notes.

---

## 20. Accounts receivable — invoicing a delivery

```
Delivery           Dr Cost of Sales          Cr Inventory        ← Phase 2, no revenue
Sales invoice      Dr Trade Receivables      Cr Sales Revenue    ← this phase
Customer receipt   Dr Cash / Bank            Cr Trade Receivables ← this phase
```

A sales invoice line **always points at a delivery line**. Version 1 invoices delivered goods
and nothing else — no service lines, no ad-hoc charges, no arbitrary product lines. If it was
not delivered, it cannot be invoiced.

### The revenue account is resolved, never chosen

The user states the amount being billed. Where the credit lands is resolved by the system:

```
delivery line → product → product_account_mappings.revenue_account_id
```

No screen offers a revenue selector, and no request field can carry one. An unmapped product
cannot be invoiced, exactly as it cannot move stock. **Trade Receivables** comes from
`accounting_settings.trade_receivables_account_id` (seeded to 1210) and never appears on a
line — it is the system's account, a control account that refuses manual posting.

### Which deliveries are billable

A delivery line may be invoiced only when **all** of these hold:

1. the delivery is **POSTED** (not draft, cancelled or reversed);
2. it belongs to the customer being invoiced;
3. it carries a **posted operational journal** from Phase 2, not itself reversed — or it was
   posted under accounting with a **total cost of zero**, so there was nothing to journal
   (`SalesBillingReconciliationService::isBillable()`);
4. it has a **remaining** uninvoiced amount.

Condition 3 rules out a delivery from **before the accounting cutover**. Such a delivery moved
real stock but raised no cost of sales in the general ledger, so invoicing it would recognise
revenue with no matching cost — an incomplete picture of the period. Historic deliveries are
**not backfilled**; they stay as operational history and are simply not billable.

### The billable amount comes from the delivery line, not a recalculation

This is the one place AR is deliberately **asymmetric with AP**. A goods receipt's accrual is
read back from its own journal, because the journal holds it. A delivery's journal holds only
cost of sales — it deliberately recognises no revenue — so the authoritative sales figure is
`delivery_lines.sales_value`, computed once when the delivery was posted.

Nothing in AR recomputes `quantity × unit_price`. For a line priced per KG on a dual-unit
product (raw shell), re-deriving the value would need a NOS↔KG conversion, and **no such
conversion exists or may ever be introduced**. The delivery already decided the number; the
invoice bills it.

### Partial billing

One delivery line may be billed across several invoices, and one invoice may bill lines from
several deliveries — all belonging to the same customer.

```
remaining  =  delivery_lines.sales_value  −  sum of posted, non-reversed invoice lines against it
```

A line may not exceed its remaining amount. The check runs under a row lock at posting, and
several lines on one invoice pointing at the same delivery line are counted together, so two
concurrent invoices cannot together over-bill a delivery.

### A delivery cannot be reversed once invoiced

The mirror of §17's goods-receipt rule. If revenue has been recognised against a delivery,
reversing the delivery would leave the ledger describing a sale whose goods never left. The
error names the invoices that must be reversed first.

---

## 21. Customer receipts and AR balances

A receipt is **always allocated to invoices** — Version 1 has no customer advances, deposits or
on-account credits — so the allocations must total the receipt exactly. One receipt may settle
several invoices, and one invoice may be settled by several receipts.

A receipt recognises **no revenue** (the invoice already did) and touches **no inventory or cost
of sales**. Its account must be an ordinary asset account — cash or bank — not a control
account.

**AR balances are derived, never stored.** An invoice's outstanding balance is its total less
the allocations of receipts that are still **posted**; a customer has no receivable column and
must never grow one. `credit_limit` and `credit_days` are agreed terms, not balances — the
credit days merely suggest a due date, which stays editable.

That is also what makes reversal clean: a reversed receipt's allocations stay on the record and
simply stop counting, and each affected invoice's status
(POSTED / PARTIALLY_PAID / PAID) is recalculated from the restored balance.

**The third critical invariant:**

```
Trade Receivables general-ledger balance  ==  total sales invoice outstanding
```

It is asserted after posting an invoice, after a partial receipt, after a final receipt, after
reversing a receipt and after reversing an invoice.

**Revenue reconciles per journal, not globally.** Each system-generated invoice journal's
revenue credits equal that invoice's lines. The *global* revenue balance is not claimed to equal
sales invoices alone — a manual journal may legitimately credit a revenue account for something
that is not a sales invoice.

The aging report buckets outstanding amounts by how overdue they are — Current, 1-30, 31-60,
61-90, 90+ — as of any date. A reversed invoice appears nowhere in it; a reversed receipt stops
reducing what is owed. A separate unbilled-deliveries report shows the other side: posted
deliveries whose value has not yet been invoiced, each UNBILLED, PARTIALLY_BILLED or
FULLY_BILLED.

---

## 22. Cash and bank

### Which accounts hold money is configuration, not a guess

`cash_bank_accounts` records which general-ledger accounts the factory treats as a real till
or a real bank account. **It holds no balance and never will** — what is in the till or the
bank is derived from posted journal lines, exactly as stock is derived from the inventory
ledger and an account balance from its lines.

Every module that moves money asks one question of one table, through
`AccountingPolicy::assertCashBankAccount()`. Two conditions, and both matter:

| The **account** must be | Which excludes |
| --- | --- |
| active | a deactivated account |
| an ASSET | revenue, expenses, liabilities, equity |
| not a control account | Trade Receivables, Trade Payables, GRNI, every inventory account |
| hand-postable | group headers like `1100` |
| | |
| The **configuration** must exist and be active | everything else |

The last row is the stricter half and the point of the table. An ordinary asset account such
as `1400 Other Current Assets` passes every account test above and is still **not** somewhere
cash may be paid into or out of. Requiring a configuration row means an accountant decides
which accounts are real tills and real bank accounts, rather than the shape of the chart
deciding it by accident.

This is a **change to existing behaviour**: supplier payments and customer receipts previously
accepted any active non-control asset account and now require a configured one.

Seeded configuration is `1110 Cash on Hand` (CASH) and `1120 Bank` (BANK). More bank accounts
are added by an accountant through the UI — the software must not invent which banks a factory
uses. A configuration money has moved through is deactivated, never deleted, and its
underlying ledger account can never be changed: repointing it would silently rewrite the
meaning of every document already recorded against it.

### Four ways money moves, and they are not interchangeable

```
Immediate expense   Dr Expense                Cr Cash / Bank        ← expense voucher
Credit expense      Dr Expense                Cr Trade Payables     ← supplier invoice
  then              Dr Trade Payables         Cr Cash / Bank        ← supplier payment
Customer receipt    Dr Cash / Bank            Cr Trade Receivables
Fund transfer       Dr Destination Cash/Bank  Cr Source Cash/Bank
```

**An expense voucher is for money that has already gone.** Electricity paid at the counter,
fuel bought and settled on the spot. It debits the expense and credits cash or the bank in one
journal, and **raises no payable**, because nothing is owed afterwards.

**A supplier invoice is for money that will go later.** It raises a payable, which a supplier
payment then settles. Recording an immediate payment this way would put a liability on the
books that never existed; recording a credit purchase as a voucher would lose the debt
entirely. The two workflows must not be combined.

A voucher may name a **supplier for reporting** — "who did we buy the fuel from". That changes
nothing about the journal and creates no Trade Payables balance.

**A fund transfer is a balance-sheet event and nothing else.** No expense, no revenue, no
payable, no receivable, no inventory. The company's combined cash-and-bank total is identical
before and after, which is the cheapest possible proof it was recorded correctly. The debit and
the credit are the same figure used twice, so the two legs cannot drift apart. Cash→Bank,
Bank→Cash and Bank→Bank are all permitted; the same account on both sides is not.

Neither document touches inventory in any way: not a quantity, not a ledger row, not a weighted
average, not a lot.

Both follow the same lifecycle as every other document here — DRAFT / POSTED / REVERSED /
CANCELLED, immutable once posted, corrected by reversal through `ReverseJournalEntry`, never
deleted. Numbers are `EXP-YYYYMMDD-000001` and `TRF-YYYYMMDD-000001`.

### The expense accounts a voucher may charge

Ordinary **EXPENSE** accounts only, active, not control accounts, not headers.

**COST_OF_SALES is deliberately excluded.** It is derived from stock movements by the inventory
integration, and letting someone charge it by hand would put cost of sales and the inventory
ledger out of step with nothing to explain the difference. Inventory, receivables, payables,
GRNI, revenue, equity and the cash accounts themselves are excluded by the type check alone.

The rule is stated twice on purpose: in the form request, so the screen can show a helpful
error, and again in `AccountingPolicy` when the voucher posts, so an API or a seeder is held to
the same rule. The request is the convenience; the policy is the guarantee.

### The cash and bank book

`CashBankBookService` derives **entirely from posted journal lines**.

```
Money In        a DEBIT to the account   (an asset increasing)
Money Out       a CREDIT to the account  (an asset decreasing)
Running balance calculated per request, never persisted
```

A supplier payment, a customer receipt, an expense voucher, a fund transfer and a journal
written by hand all appear for one reason only — each posted a line to the account. **No module
is special-cased**, and a future module that moves money will appear with no change to this
service at all. Source documents are identified through `source_type` / `source_id`, so a row
can name `PAY-…`, `REC-…`, `EXP-…`, `TRF-…` or simply "Manual journal".

**The fourth critical invariant:**

```
Cash/Bank Book closing balance  ==  that account's general-ledger balance
```

It holds by construction, because both read the same lines — there is no second source of truth
to drift from. The book screen asserts it on every request and says so on the page, because an
invariant nobody verifies is only a belief.

A reversed document stays in the book with its reversal beside it. Nothing is hidden; the two
rows simply sum to nothing.

---

## 23. Negative cash and overdrafts — `TBD`

**No overdraft or negative-balance policy exists, and none was invented.** Posting may take a
cash or bank account below zero, because nothing currently prevents it. This is recorded, not
endorsed.

Three business questions are open, and they are the factory's to answer, not the software's:

| # | Question |
| --- | --- |
| 1 | Should Cash on Hand ever be allowed to go negative? Physically it cannot — a negative till means something was recorded wrongly — so should posting refuse it, warn, or allow it? |
| 2 | Should bank accounts support overdrafts, and if so is an overdraft a liability account or a negative asset? |
| 3 | Should any limit be per account, per kind, or global — and who may override it? |

Until they are answered, a test records the current behaviour deliberately, so that introducing
a policy later is a visible, intentional change rather than a silent one.

Bank reconciliation is **not built**: no statement import, no matching, no reconciled flags, no
uncleared cheques, no statement-derived charges. This phase is general-ledger cash control only.
Amounts remain tax-unmodelled and in the base currency.

---

## 24. Financial statements

Both statements are **read models over posted journal lines**. They use the same inclusion
rule as every other balance — `AccountingBalanceService::postedLines()`: POSTED and REVERSED
entries both count, so a reversed journal and its reversal both appear and net to nothing.
Nothing is excluded to make a reversal "disappear", and no statement figure is ever stored.
There is no profit, asset, liability, equity, cash, AR or AP balance column anywhere.

### Profit and loss

```
Revenue        − Cost of Sales      = Gross Profit
Gross Profit   − Operating Expenses = Net Profit
```

| Section | Account type |
| --- | --- |
| Revenue | `REVENUE` |
| Cost of sales | `COST_OF_SALES` |
| Operating expenses | `EXPENSE` |

- **Accounts are classified by `AccountType` alone — never by a code prefix.** No `4xxx`,
  `5xxx` or `6xxx` appears in any report. A renumbered chart keeps working.
- The window is a date range (`from` / `to`) or a fiscal period; a chosen period states its
  own window and overrides the dates. `totalsByAccount()` takes the optional `from` date so
  the range is applied inside the one query that defines a posted line.
- **The P&L shows recognised revenue.** Revenue reaches the ledger through sales invoices
  (§20), so a delivered-but-unbilled sale appears as cost with no revenue beside it. That is
  correct, and is what the unbilled-deliveries report exists to surface. Gross profit on the
  P&L is never derived from deliveries.
- No tax, depreciation or finance-cost lines are invented. If such accounts are created later
  as `EXPENSE`, they fall into operating expenses automatically.
- Zero revenue yields a zero margin, never a division error.

### Presentation sign

The ledger stores one number per account: debits minus credits. A statement is read by
people, so `AccountingBalanceService::presentationAmount()` flips the sign for the
credit-natured types (revenue, liability, equity) and leaves the debit-natured ones (asset,
cost of sales, expense) alone. Revenue of 260,000 therefore prints as `260,000`, not
`-260,000`.

The sign follows the account **type**, not each account's stored `normal_balance`, so one
mis-configured account cannot make a section total incoherent. An account carrying a
genuinely **abnormal** balance — revenue in debit, an asset in credit, an overdrawn bank —
still prints negative, which is the signal a reader needs. This is presentation only; no
result of it ever feeds back into ledger arithmetic.

### Balance sheet

As at a date. Assets (`ASSET`), liabilities (`LIABILITY`) and equity (`EQUITY`). P&L accounts
never appear as balance-sheet rows — their net effect appears as **Current Earnings**.

```
Assets  =  Liabilities  +  Equity  +  Current Earnings
```

This holds **exactly, by construction**: every posted journal balances, so the net balances
of all six account types sum to zero, and rearranging that sum is the equation above. The
report still computes and shows the difference, because a non-zero one would mean the trial
balance itself is broken — an invariant nobody checks is only a belief.

### Current Earnings is computed, never posted

- **Retained Earnings (`3200`) is an ordinary ledger account.** The balance sheet shows its
  actual general-ledger balance. Nothing writes profit into it — no closing journal is ever
  posted automatically. Its code comes from `config('accounting.retained_earnings_code')`.
- **Current Earnings** is a computed line under equity: unclosed profit, never written to any
  account.
- **Its accumulation boundary is the beginning of recorded history.** Fiscal periods are
  individual months with no year grouping, and nothing records where a financial year starts,
  so the system has no basis to assume January — or any date. While no closing entry has ever
  been posted, "since inception" is not an approximation: every rupee ever earned is still
  unclosed, so it is the correct figure.
- **Limitation, stated plainly:** once year-end closing exists, Current Earnings must start
  from the last close, or profit already moved into retained earnings would be counted twice.
  Year-end closing is **not implemented** and must not be started without instruction.

### Chart hierarchy and subtotals

`AccountTreeService` nests accounts by `parent_id`, the way the chart organises them, and is
shared by both statements. **A parent is counted exactly once:** its subtotal is its own
direct postings plus its children's subtotals. Group headers refuse posting and so hold
nothing of their own, but the arithmetic does not rely on that — if a parent ever becomes
postable, its own balance is added beside its children, never lost and never double-counted.
Branches with no balance anywhere beneath them are omitted.

### Drill-down

Every non-group account on either statement links to the General Ledger for that account,
carrying the statement's date window, so each figure can be traced to the journals behind it.

## 25. Financial P&L vs operational product profitability

Two different questions, two different sources, deliberately never merged:

| | Financial P&L | Operational product profitability |
| --- | --- | --- |
| Question | What revenue has been **recognised**? | Was this physical sale **profitable**? |
| Source | General ledger (posted journals) | Posted delivery lines |
| Revenue | Sales invoices | `DeliveryLine.sales_value` |
| Cost | Cost-of-sales journals | `DeliveryLine.cost_of_goods_sold` |

```
Gross Profit   = sales_value − cost_of_goods_sold
Gross Margin % = Gross Profit ÷ sales_value × 100      (0 when sales_value is 0)
```

- The report is labelled **Operational Product Profitability** everywhere — never "Financial
  P&L by product".
- **Both figures are read exactly as the delivery stored them, never recomputed.**
  `sales_value` already honours the line's `pricing_basis`, so raw shell priced per KG is
  right with no knowledge of kilograms here — and no NOS↔KG conversion is introduced.
- Only **POSTED** deliveries count. A reversed delivery is excluded outright rather than
  netted, because its reversal restored the stock entirely.
- The two reports can legitimately disagree while deliveries are unbilled or partly billed.
  Both are correct.
- Drill-down: choosing a product lists the posted deliveries behind its figures.

## 26. Inventory valuation and Inventory-to-GL reconciliation

### Inventory valuation comes from the inventory ledger

`InventoryValuationReportService` reads `StockQueryService` — **not the general ledger** — and
shows, per product and warehouse: quantity, secondary quantity where the product has one,
weighted average cost, value, and the mapped inventory GL account. Value always follows the
primary quantity; the secondary quantity is displayed as measured and never derived.

**Current valuation only.** `StockQueryService` has no date filter, so there is no safe way
to value stock as at a past date. Filtering the ledger separately here would create a second
definition of stock that could disagree with every other stock screen. Historical as-of
valuation is **future work**, not faked.

### Reconciliation by GL account

`InventoryGlReconciliationService` groups inventory-ledger value by each product's mapped
inventory account and compares it to that account's general-ledger balance:

```
Difference = Inventory ledger value − GL balance        (shown to six decimals)
Status     = MATCHED if the difference is exactly 0.000000, otherwise DIFFERENCE
```

Several products may share one account — all three husk-chip grades post to Husk Chips
Inventory — which is why the comparison is per **account**, not per product. Stock held by an
unmapped product is reported separately: real value with no ledger account at all.

### A difference is displayed, never hidden or fixed

After accounting-integrated operations the two must agree to the last decimal, because each
stock movement writes its journal inside the same database transaction. But a difference is
**legitimate** when:

- `operational_accounting_start_date` is NULL, so nothing journals at all;
- stock moved **before the cutover date** and raised no journal;
- opening balances have not been established.

The screen states the cutover context so a reader can judge whether a difference is a defect.
**Pre-cutover inventory requires an accounting opening-balance / cutover process later.**
That process is not built. **Journals are never backfilled automatically** — inventing history
to make a report tidy destroys the audit trail.

## 27. Management dashboard and position reports

- **Every dashboard figure is fetched from the service that owns it** — P&L, balance sheet,
  AR and AP aging, cash/bank book, inventory valuation, product profitability. Nothing is
  recalculated, so the dashboard cannot disagree with the detail behind it.
- **The period is always explicit.** The service takes a fiscal period or explicit dates and
  never assumes "this month". The screen *opens* on the open fiscal period containing today,
  falling back to the current calendar month purely as a UI convenience with no accounting
  meaning.
- Performance (revenue, cost of sales, gross profit, expenses, net profit) is for the window;
  position (cash, bank, AR, AP, current earnings) is as at the window's end. Inventory value
  is always current (§26).
- **Cash / bank position** uses `CashBankBookService::summary()` over configured
  `cash_bank_accounts`, so it always agrees with the cash/bank book and the general ledger.
- **AR / AP summaries** condense the existing aging services into Current / 1-30 / 31-60 /
  61-90 / 90+ and an overdue total, plus the top five customers or suppliers. The aging logic
  is not duplicated; the dashboard links to the aging screens for detail.
- Drill-downs: P&L → profit & loss; position → balance sheet; cash → cash/bank position → book;
  AR/AP → aging; inventory → valuation; products → product profitability.
- **All report routes are GET-only.** Nothing in the reporting area writes, and a test asserts
  it.

## 28. Reporting — future work and `TBD`

| # | Item | Why it is not built |
| --- | --- | --- |
| 1 | Year-end closing | Not instructed. When built, Current Earnings (§24) must start from the last close. |
| 2 | Financial-year definition | Fiscal periods have no year grouping; needed by year-end closing. |
| 3 | Historical (as-of) inventory valuation | `StockQueryService` has no date filter; not faked (§26). |
| 4 | Inventory opening balances at cutover | Needed to reconcile pre-cutover stock (§26). |
| 5 | Cash flow statement | Out of scope for this phase. |
| 6 | PDF / Excel export | No established export mechanism exists; screens came first. |
| 7 | Comparative periods (this period vs last) | Not requested. |

## 29. Payment methods — cash, cheque, bank transfer

Supplier payments and customer receipts record **how** the money moved:
`payment_method` = `CASH`, `CHEQUE` or `BANK_TRANSFER`. A cheque also records
`cheque_number`, `cheque_date` and `cheque_bank`; for any other method those are stored NULL,
because details typed for cash would describe a cheque that does not exist.

| Method | Must go through |
| --- | --- |
| `CASH` | a configured cash/bank account of kind **CASH** (the till) |
| `CHEQUE` | a configured cash/bank account of kind **BANK** |
| `BANK_TRANSFER` | a configured cash/bank account of kind **BANK** |

Checked in the form request for a clear message and **again at posting**
(`AccountingPolicy::assertMethodFitsAccount()` and `assertChequeDetails()`), on top of the
existing configured-account rule (§22). The journal is unchanged: `Dr Trade Payables / Cr
Cash or Bank` for a payment, `Dr Cash or Bank / Cr Trade Receivables` for a receipt.

**Credit is not a payment method.** Buying or selling on credit is an invoice left
outstanding; it is settled later — in full or in parts — by payments or receipts allocated
to it, exactly as before (§18, §21). The invoice screens show **Total / Paid (Received) /
Outstanding**, and a **Record payment** button opens a payment or receipt pre-filled with that
invoice and its outstanding amount.

**Cheques: details only.** There is no cheque status (received / deposited / cleared /
bounced) and no "Cheques in Hand" clearing account: the money posts straight to the chosen
bank account, exactly as a transfer does. A bounced cheque is handled today by **reversing**
the receipt or payment — remembering that a reversal is an audit correction (§19), so the
document is marked as never having happened.

`TBD` — a proper cheque lifecycle: a clearing account, deposit and clearance postings, and a
bounce that re-opens the invoice as a commercial event.

Documents recorded before payment methods existed have a NULL method; nothing is invented
for them. New documents require one.

## 30. Production labour and backflushing

### Dehusking labour

Dehusking gives the dehusked coconut the **whole** coconut value and the raw husk **none** of
it; the husk's cost is the run's labour — quantity × the per-coconut rate the operator
confirms (`BUSINESS_RULES.md` §18). The labour is recorded as:

```
Dr 1330 Raw Husk Inventory               labour
    Cr 6310 Production Labour Absorbed   labour
```

6310 is an **EXPENSE-type contra account**: wages are still paid by expense voucher to 6300
Wages and Salaries, and the labour sitting in unsold husk stock offsets them until that stock
is sold (when it reaches cost of sales through the backflush below). It is a **control
account** — only a sourced system journal reaches it — and is configured as
`accounting_settings.production_labour_account_id`, never written as a code in posting
logic. **Production creates no cash movement and no payable.**

### Zero-value lines

A journal line is a debit or a credit, never neither, so zero-value lines are left out — a
zero labour rate writes no 6310 line. A run that moves no value at all writes **no journal**:
husk chipping (version 2) consumes nothing and its outputs enter at zero.

### Backflushing at delivery

Chips and cocopeat reach stock with their raw husk cost pending. Their delivery states the
raw husks used, and posting issues them from the pool (`BACKFLUSH_ISSUE`) at the weighted
average, inside the delivery's own transaction. The delivery journal gains:

```
Dr <delivered product's> COGS (e.g. 5130 Husk Chip COGS, 5170 Cocopeat COGS)
    Cr <raw material's> inventory (1330 Raw Husk Inventory)
```

The cost lands in the **delivered product's** cost of sales — it is part of what that product
cost to sell — not in raw husk COGS. The delivery line's `cost_of_goods_sold` includes it, so
operational profitability and the P&L agree on it. Reversing the delivery reverses the journal
exactly.

### Reconciliation

Inventory-to-GL stays exact per account: 1330 holds the pooled husk's ledger value (purchases
+ labour − backflushed), and 1340 / 1370 hold the chips' and cocopeat's ledger value — zero
for stock whose husk cost is still pending. A delivery posted under accounting whose total
cost is zero writes no journal and **is still billable** (§20).
