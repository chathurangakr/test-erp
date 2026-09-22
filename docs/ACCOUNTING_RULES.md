# Accounting Rules — Coconut Factory ERP

> **Status:** ✅ **IMPLEMENTED** (Finance Phase 1 — the accounting foundation). Chart of
> accounts, fiscal periods, manual journal entries, reversal, balances, general ledger and
> trial balance all exist and are tested.
>
> **Not built:** payables, receivables, payments, invoices, tax, payroll, and any automatic
> journal from an operational document. See §10.

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

## 8. Inventory value is authoritative when Finance integrates

When purchasing, sales and production begin generating journals, the amount posted must be
the inventory ledger's **authoritative `total_cost`** — never a value rebuilt from a rounded
per-unit figure.

```
Inventory line:   total_cost = Rs.16,000.000000   (280 KG at Rs.57.142857)
Finance posts:    Rs.16,000.000000
NOT:              280 × 57.142857 = Rs.15,999.999960
```

This is why the accounting scale is `decimal(20,6)`, matching the ledger exactly: the figure
must transfer without rounding. See `INVENTORY_RULES.md` §8.1 and `DATA_MODEL.md` **I-4**.

## 9. Currency

**Version 1 keeps every book in one currency: LKR.**

It lives in `config/accounting.php` as `base_currency`, not as a literal in business logic,
so introducing a second currency is a schema and configuration change rather than a hunt
through the codebase.

> `TBD` — multi-currency: which rates, revalued when, and gains/losses posted where. Not in
> scope for Version 1.

## 10. Not implemented — do not begin without instruction

Accounts Payable · Accounts Receivable · supplier payments · customer receipts · purchase
invoices · sales invoices · **automatic journals from GRN, delivery or any production run**
· production expense accounting · VAT/SVAT · payroll · bank reconciliation · financial
dashboards · P&L · balance sheet · year-end closing.

`NoOperationalAccountingTest` asserts that posting a goods receipt, a delivery, or a
dehusking, chipping or copra run creates **no** journal entry. The account mappings that
would decide which account each movement touches do not exist yet, so a journal posted now
would be a guess written permanently into the books.

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
| 1 | Which business events generate journals, and with what account mappings? | AP / AR / inventory accounting |
| 2 | Single currency or multi-currency, and on what revaluation basis? | Multi-currency |
| 3 | Is tax (VAT/SVAT) in scope, and at what rates? | Tax |
| 4 | Which joint-product allocation method — market value, NRV, or other? | Costing |
| 5 | What are the year-end closing rules, and which retained-earnings account? | Year-end |
| 6 | Should periods ever be reopenable, and under what authority? | Fiscal periods |
| 7 | What roles may post, reverse and close periods? | Permissions |
