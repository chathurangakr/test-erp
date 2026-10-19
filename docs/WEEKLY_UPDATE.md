# Weekly update email

Added in 1.10.0. Every **Sunday at 8 PM Sri Lanka time** the ERP emails a summary of the week
(Monday–Sunday) to every **active** user holding the permission `reports.weekly_email`.
Administrators always hold it; any other role can be given it on the Roles screen.

## What it contains

| Section | Source (never recalculated) |
| --- | --- |
| Net profit headline, revenue, cost of sales, gross profit, operating expenses | Profit & loss for the week, via `FinanceDashboardService` |
| Top products delivered | Operational product profitability for the week |
| Cash & bank (each account, cash / bank / total) | `CashBankBookService::summary()` at the week's end |
| Money owed (customers / suppliers, overdue) | AR and AP aging at the week's end |
| Production (runs, used, made per type) | POSTED production orders dated in the week, each product in its own unit |
| Stock value now | Inventory valuation (current only — no historical valuation exists) |

Profit counts **invoiced** sales (recognised revenue); the top-products list counts **deliveries**
(operational). The email says so.

## How it works

- `App\Services\Reporting\WeeklyUpdateReport` gathers the figures; `App\Mail\WeeklyUpdate` +
  `resources/views/mail/weekly-update.blade.php` (HTML, inline styles for email clients) and
  `weekly-update-text.blade.php` (plain text) present them. The company logo is embedded in the
  email itself. Numbers are formatted from the decimal strings by `App\Support\DisplayNumber`.
- `php artisan reports:weekly-update` sends it; scheduled in `routes/console.php`
  (`weeklyOn(0, '20:00')` in the business timezone). Run on any other day it reports the last
  complete week. `--to=address` sends one test copy to that address only.
- Each email is in the recipient's language (`users.locale`). Nothing is stored.
- One failing address does not stop the others; the failure is logged.

## Mail settings (Gmail)

Gmail needs an **app password** (Google Account → Security → 2-Step Verification → App
passwords) — never the normal password.

**On screen (preferred): Administration → Email settings** (permission `mail.manage`). The
Gmail address, app password, sender name/address, and — folded away — server, port and TLS/SSL
(preset for Gmail). The row lives in `mail_settings`:

- The password is stored **encrypted with APP_KEY** (`encrypted` cast), hidden from
  serialisation and **never sent back to the page** — the page only learns `has_password`. A
  blank password on save keeps the saved one; spaces are removed.
- `AppServiceProvider::configureMail()` applies the row when the mailer is first resolved, so
  pages that send nothing read nothing. While **Send email** is off, the `.env` settings apply.
- **Send test email** sends last week's update to the signed-in user (throttled 5 a minute).

**On the server (alternative):** `bash ~/coconut-erp/deploy/set-mail.sh` saves `SMTP_USERNAME`
/ `SMTP_PASSWORD` in `deploy/provision.env` (git-ignored, mode 600) and re-runs the installer,
which writes `MAIL_*` into `.env`. The screen's settings win whenever they are switched on.

Gmail limits a personal account to about 500 messages a day — far more than this needs.

Recipients must have a **real email address** on their user account; the installer's first
administrator starts as `admin@example.com`, which receives nothing.
