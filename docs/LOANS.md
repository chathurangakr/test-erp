# Loans — what the factory owes, installments and reminders

Added 2026-10-24 (owner's request). The factory has taken loans from finance institutes, paid by
monthly installments, and machinery bought from the seller in installments with no interest.

## Confirmed rules (from the owner, 24 October 2026)

| Rule | Decision |
| --- | --- |
| Interest | Bank loans carry interest; machinery loans from the seller carry **none**. |
| Installments | A usual amount, but what is actually paid **varies** — sometimes more, sometimes less. |
| Interest per payment | Typed from the bank's slip each time; the rest reduces the loan. |
| Reminders | To **all administrators**, **7 days before, 2 days before and on the due day**. Email now; Telegram later. |

## The records

- **Loan** (`loans`, `LOAN-YYYYMMDD-000001`) — lender, purpose, how it came onto the books
  (`funding`), the amount, whether it carries interest, the usual installment and the **due day of
  the month**. **No balance column**: what is still owed = amount − principal of the standing
  payments (`App\Services\Loans\LoanBalances`). Only the description and reminder details
  (`Loan::EDITABLE`) may change; money is corrected by reversal (`ReverseLoan`, refused while a
  payment stands).
- **Loan payment** (`loan_payments`, `LPAY-YYYYMMDD-000001`) — what was actually paid, its interest
  part and the principal (paid − interest), from a configured cash / bank account by cash or bank
  transfer. Immutable; corrected by `ReverseLoanPayment`. The principal can never take a loan below
  zero (the loan row is locked while that is checked); an interest-free loan refuses interest.
- **Loan reminder** (`loan_reminders`) — one row per reminder sent, unique per loan, due date and
  days-before, so the daily run never repeats one.

## Journals

| Event | Journal |
| --- | --- |
| New loan, money received | Dr the cash / bank account / Cr Loans Payable |
| Loan already running (entered at what is still owed) | Dr Opening Balance Equity / Cr Loans Payable |
| Payment | Dr Loans Payable (principal) + Dr Loan Interest (interest) / Cr the cash / bank account |
| Reversal | The exact mirror |

Accounts are **configuration** in `accounting_settings` (`loans_account_id`,
`loan_interest_account_id`, `opening_balance_account_id`), chosen in Accounting Setup; a fresh
install — and `masters:add-new` on an update — seeds **2400 Loans Payable** (a control account),
**6950 Loan Interest** and **3300 Opening Balance Equity**. Opening Balance Equity is the usual
holding place for balances brought in from before the system; the accountant may reclassify it.
Loan journals need an open fiscal period, not the operational cutover.

**Invariant:** the Loans Payable balance equals the total still owed on all loans.

## Reminders

`php artisan loans:remind` runs every day at 08:00 Sri Lanka time (`routes/console.php`). For each
standing loan with a due day and something still owed it finds the next due date (the due day, or
the month's last day in a shorter month); when that is 7, 2 or 0 days away and the installment is
not already paid — a standing payment after the previous due date — it emails every active user
holding `loans.reminders` (administrators always do) one message listing every installment due.
A reminder is remembered only once someone received it, so a failed run tries again.

**Telegram** (2026-10-25): the same reminder also goes to the chats chosen on Administration →
Telegram settings (permission `telegram.manage`) when that is switched on. The owner creates a bot
with @BotFather, pastes its token (stored encrypted with APP_KEY in `telegram_settings`, never sent
back to a page), each administrator — or a group with them in it — messages the bot, and **Find
chats** lists them (`getUpdates`) to choose from. The server only calls out to
`api.telegram.org` (`App\Services\Telegram\TelegramBot`); no webhook. Errors never repeat the
token, which Telegram carries in the URL.

## Permissions

`loans.view`, `loans.manage` (add, edit reminder details), `loans.reverse`,
`loans.payment_record`, `loans.payment_reverse`, `loans.reminders`. A new install gives them all to
Accountant; existing roles are ticked by an administrator.

## Not built (TBD)

- Loans funding an asset directly (with the fixed assets stage).
- Interest schedules / amortisation tables; reminders by SMS; loans in the mobile API.
