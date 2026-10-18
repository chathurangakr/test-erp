# What's new — Coconut Factory ERP

Each release has a version number, shown at the bottom of the menu and on the sign-in page.
The middle number goes up for new features, the last number for fixes (1.0.0 → 1.0.1 → 1.1.0).
To install a release, run `bash ~/coconut-erp/deploy/coconut-erp.sh` on the server computer.

## 1.5.1 — 18 October 2026

- **Employee codes are numbered automatically** — EMP-001, EMP-002, EMP-003 … — when an employee
  is added. Nobody types a code, and a code never changes.

## 1.5.0 — 18 October 2026

- **Payroll (new section in the menu).**
  - **Employees** — each with a daily salary rate. Changing a rate never changes days already
    recorded.
  - **Attendance** — a daily register: mark each person **full day**, **half day** or **leave**.
    A full day pays the day's rate, a half day half of it, leave nothing.
  - **Salary advances** — paid from cash or the bank and recorded in the accounts at once.
  - **Monthly salaries** — pick a month to see each person's days, pay, advances taken back and
    the amount to hand over, then pay everyone in one step. Advances are taken back in full; what
    a salary cannot cover is taken from the next month. Wages appear in profit and loss.
  - Mistakes are corrected by **reversing**, which also unlocks that month's attendance.
- **Accounting Setup** has two new choices: the **wages** account and the **employee advances**
  account. New installations get 6300 Wages and Salaries and a new 1220 Employee Advances account.
- Rules and limits: `docs/PAYROLL.md`.

## 1.4.2 — 18 October 2026

- **Customer and supplier names shown in Finance.** The customer filter on Sales invoices and
  Customer receipts, the supplier filter on Supplier invoices and Supplier payments, and the top
  of each of those documents now show the name beside the code (e.g. "CUS-001 — Mr. Chaminda"),
  not the code alone.

## 1.4.1 — 17 October 2026

- **Fixed:** updating an installed computer to 1.4.0 stopped at "Building the screens" with
  `"store" is not exported`, leaving the ERP showing an error page. The installer now clears the
  previous version's saved list of pages before building, so updates that add a page work.
  If an update already stopped there, run
  `sudo -u coconuterp php /var/www/coconut-erp/artisan route:clear` and then the update again.

## 1.4.0 — 17 October 2026

- **Add user.** Administrators can now add a person on **Administration → Users → Add user**:
  name, email, a starting password and their roles in one step. The person changes the password
  in their own settings.
- **Sign-up can be turned off.** A new installer setting, `SELF_REGISTRATION=no`, removes the
  "Sign up" link so that only administrators can add accounts. Recommended for a server on the
  internet. It stays on unless you change it.
- **Free trusted certificate.** When the server has a real name (a bought domain or a free
  DuckDNS name such as `factory-erp.duckdns.org`) the installer gets a free Let's Encrypt
  certificate. Browsers then open the ERP without a security warning, and the certificate renews
  itself.
- **Restore a backup, or move data from another computer.** `bash deploy/restore.sh <backup file>`
  replaces the ERP's data with a nightly backup — this computer's own, or one copied from another
  computer. It saves the current data first and puts it back by itself if anything goes wrong.
  See `docs/CLOUD_SERVER.md`.
- **Fixed:** opening the ERP by a longer name (such as a DuckDNS address) showed "502 Bad
  Gateway" on every page.

## 1.3.1 — 16 October 2026

- The **user guide** and the **detailed manual** in `docs/manuals/` are updated for version 1.3:
  the phone app works from what it last downloaded, and its messages come in your language.

## 1.3.0 — 16 October 2026

- **More for the phone app:**
  - one download that gives the phone everything simple mode's screens need — suppliers,
    customers, stores, products with their last known stock and batches, and what the recipes
    say — so the app works offline without guessing;
  - messages from the system (for example "not enough stock") now come back in the person's
    language, Sinhala or English, and the app starts in the language the person chose.

## 1.2.1 — 16 October 2026

- The **user guide** and the **detailed manual** in `docs/manuals/` are updated for this version:
  a "What's new by version" table, and a section on the coming phone app (manual section 7.6) —
  how entries made without internet are sent later and never recorded twice.

## 1.2.0 — 16 October 2026

- **Ready for the offline phone app.** The ERP can now take work recorded on a phone without
  internet — goods received, goods dispatched, dehusking, husk chipping and copra — and record it
  when the phone is back on wifi:
  - each entry is saved and posted in one step, on the date the work was really done;
  - **nothing is ever recorded twice**, even if the phone sends the same entry again after a
    dropped connection;
  - the ERP keeps who sent each entry, from which phone, when it was made and when it arrived;
  - an entry the ERP cannot accept (for example, more than is in stock) is refused whole and can
    be corrected on the phone and sent again.
- The phone app itself is being built separately (`docs/ANDROID_APP_HANDOFF.md`).

## 1.1.0 — 15 October 2026

- **Runs on a cloud server** (tested for Oracle Cloud's free servers). The installer:
  - finds the server's public internet address by itself, so it can be opened at
    `https://<IP address>` from anywhere;
  - opens the web ports in Oracle's own firewall (and leaves it working after a restart);
  - asks no password where the server's user needs none.
- New step-by-step guide: `docs/CLOUD_SERVER.md` — creating the free Oracle server, opening its
  ports, installing, updating and copying backups off the server.
- Factory installations are unchanged.

## 1.0.1 — 15 October 2026

- The **user guide** and the **detailed manual** (English and Sinhala, with screenshots) are now
  included as PDFs in `docs/manuals/`, so every installation has a copy in
  `~/coconut-erp/docs/manuals/`.
- Both explain where to see which version is running.

## 1.0.0 — 15 October 2026

The first numbered release. It contains everything built so far:

- **Buying:** purchase orders, goods receipts with transport and loading costs.
- **Production:** dehusking (labour becomes the husk's cost), husk chipping by KG or litre,
  copra with shell count and weights.
- **Selling:** sales orders and deliveries; raw husks used are taken from stock when chips are
  delivered.
- **Stock:** stock on hand, stock ledger and lots, with weighted-average cost.
- **Accounting:** chart of accounts, fiscal periods, journals, supplier invoices and payments,
  sales invoices and customer receipts, expense vouchers, cash and bank transfers.
- **Reports:** profit and loss, balance sheet, trial balance, stock value, aging, finance
  dashboard.
- **People:** roles and permissions, simple mode for factory workers, Sinhala and English.
- **Installing:** one file, `coconut-erp.sh`, installs and updates; an app icon in Ubuntu's
  Show Apps; nightly backups.
- **This release also:** a start page with shortcuts, the version shown on screen, readable
  document names in the ledgers, and the remaining English words on Sinhala screens translated.
