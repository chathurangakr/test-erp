# Telegram

Added in 1.15.0 (loan reminders), 1.16.0 (transaction alerts, monthly report) and 1.17.0
(commands). Set up on
**Administration → Telegram settings** (permission `telegram.manage`).

## Two bots

`telegram_settings` holds one row per bot, named by `purpose`:

| Bot | What it sends |
| --- | --- |
| `reminders` | Loan installment reminders (docs/LOANS.md) |
| `transactions` | A message for every posting and reversal (below) |

Each has its own token (stored **encrypted with APP_KEY**, hidden from serialisation, never sent
back to a page or repeated in an error — Telegram carries it in the URL), chats, on/off switch and
"Also send the monthly report here" (`sends_monthly_report`, see docs/WEEKLY_UPDATE.md). Chats are
found with getUpdates after someone messages the bot; nothing is received by webhook.

## Transaction alerts

**What is reported** — `App\Services\Telegram\TransactionAlertCatalog::MODELS`: goods receipts,
deliveries, production (all three types), sales invoices, customer receipts, sales returns,
customer bag movements, supplier invoices, supplier payments, expense vouchers, fund transfers,
manual journals, damaged stock, salary advances, payrolls, loans, loan payments, fixed assets and
asset maintenance. `alert_types` on the transactions bot chooses which (NULL = all).

**Events are read from status changes**: created already POSTED / IN_USE, or DRAFT → POSTED =
`posted`; → REVERSED = `reversed`; a fixed asset IN_USE → SOLD = `sold`, SOLD → IN_USE =
`sale_reversed`. Nothing else (drafts, cancellations, an invoice becoming PAID) is an alert. Journals
the system writes for a document are not reported — the document is; a manual journal is. A loan
opened by buying an asset on credit is reported as the asset.

**Outbox, not a call from the posting.** `App\Observers\TransactionAlertObserver` writes a
`telegram_alerts` row inside the posting's own database transaction, so an alert exists exactly
when the document does and a failed posting leaves none. `telegram:send-alerts` runs every minute
(`withoutOverlapping`) and sends up to 15 waiting alerts, oldest first, to every chosen chat:

- each chat is sent once (`delivered_to`), so a retry repeats nothing;
- Telegram unreachable (`TelegramUnreachable`) → the run stops and everything waits;
- Telegram refuses → retried next minute, FAILED after `TelegramAlert::MAX_ATTEMPTS` (5);
- a message that cannot be written, or whose document is gone, is FAILED and does not block others;
- while the bot is off, alerts are not queued; ones already waiting stay waiting.

**The message** (`TransactionAlertMessage`) is written when sent, from the document itself: posted
figures never change, so it reads as it would have at posting. Only what an event erases is kept in
the alert's `context` (an asset sale, which undoing clears). It shows the number and date, the
party, accounts and lines (up to 15), the total, who posted or reversed it, and a link to open it.
Messages are in the server's language (English).

## Commands (1.17.0)

People type `/` in a **private chat** with the transactions bot and tap a command. Telegram shows
the list because "Switch commands on" set it (`setMyCommands`, English and Sinhala).

| Command | Shows | Needs (any of) |
| --- | --- | --- |
| /stock | Stock on hand per product, buttons per product → per warehouse (values with `reports.inventory`) | `inventory.view` |
| /cash | Each cash and bank account and the totals | `finance.cash.view` |
| /today | Deliveries (`sales.view`), invoiced (`finance.ar.view`), goods received (`purchasing.view`), production (`production.view`), money in and out (`finance.cash.view`) — each part only with its permission | any of those |
| /owed | Customers owe us (`finance.ar.view`), we owe suppliers (`finance.ap.view`), loans still owed (`loans.view`) | any of those |
| /help, anything else | The commands this person may use | — |

**How Telegram reaches the ERP.** Switching commands on makes a 48-character secret and calls
`setWebhook` with `route('telegram.webhook')` (`POST /telegram/webhook`) — so the server must be
reachable from the internet over HTTPS (the cloud server is; a computer on a LAN is not, and
Telegram says so). The route sits outside the web group (no session, cookies or CSRF), is limited
to 120 requests a minute, and answers 403 unless the `X-Telegram-Bot-Api-Secret-Token` header
matches the stored secret (constant-time). It always answers 200 otherwise, so Telegram never
retries a message that failed (the failure is logged). While the webhook is on, Telegram will not
list chats through getUpdates, so "Find chats" uses the chats the webhook has seen (`seen_chats`).
Saving a new token switches commands off; switch them on again for the new bot.

**Who gets answers** (`TelegramCommandHandler`):

- **Linked, active ERP users only.** Settings → Profile → "Link my Telegram" stores a random
  32-character code in the cache for 15 minutes and opens `t.me/<bot>?start=<code>`; pressing Start
  sends `/start <code>`, which links that Telegram account (`users.telegram_user_id`, unique — a
  re-link moves it) and is used up. Strangers are told how to link and learn nothing.
- **Permissions are checked on every message and button press**, so a removed role or a
  deactivated account stops answers immediately.
- **Private chats only**: in a group the bot asks to be messaged privately.
- Answers are in the person's ERP language. Nothing can be posted or changed from Telegram.
