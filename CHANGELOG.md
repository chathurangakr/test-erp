# What's new — Coconut Factory ERP

Each release has a version number, shown at the bottom of the menu and on the sign-in page.
The middle number goes up for new features, the last number for fixes (1.0.0 → 1.0.1 → 1.1.0).
To install a release, run `bash ~/coconut-erp/deploy/coconut-erp.sh` on the server computer.

## 1.30.2 — 25 October 2026

- **Build tools brought fully up to date** (vite-plus 1.3, vue-tsc 3): the package check now finds
  no known problems at all. This changes nothing on screen.
- **Fix for the installer:** on a computer whose Node.js came with Ubuntu (npm 9), the installer
  now switches to the official Node.js first, so building the screens cannot fail there.

## 1.30.1 — 25 October 2026

- **Fix: fail2ban now also blocks bots that try SSH keys.** With password sign-in off, most bots
  offer a key, are turned away and try again; those attempts now count towards a block too.

## 1.30.0 — 25 October 2026

A security check of the server, the database and the application, and everything it found fixed.

- **Signing in is harder to attack.** On top of 5 tries per email address, one internet address
  may now try at most 30 sign-ins a minute, so nobody can try a password against many accounts.
  The same applies to the phone app's sign-in.
- **A changed password signs you out everywhere else.** If someone had your session on another
  computer, it ends the moment you change (or reset) your password. The browser you changed it in
  stays signed in.
- **Changing your email address asks for your password.** An email address can be used to reset
  the password, so changing it now needs the current password (Settings → Profile).
- **The saved email password goes only to the server it was typed for.** On Administration →
  Email settings, changing the mail server, port, encryption or account now needs the app password
  typed again.
- **Pages run only the ERP's own code.** Browsers are told to refuse any script that is not part of
  the ERP, as a second wall behind the escaping every screen already does.
- **New installations start with sign-up closed.** Only administrators add people (Administration →
  Users), unless `SELF_REGISTRATION=yes` is set. The cloud server already had it closed.
- **The server protects itself.** An install or update now:
  - blocks addresses that keep failing to sign in over SSH (fail2ban) — the cloud server saw
    about 5,700 such attempts in a week;
  - switches off SSH sign-in as root and X11 forwarding;
  - switches off an unused network service (rpcbind);
  - stops the database from loading files from disk;
  - installs Ubuntu's security updates automatically every day;
  - uses only modern encryption for HTTPS and sends extra protective headers on every file.
- **Build tools updated** (development only, not part of the running site): fixed versions of
  tinypool, vitest and vite.

## 1.29.1 — 25 October 2026

- **Fix: the bigger database cache now always takes effect.** This MariaDB version can't enlarge
  its cache while running, so an install or update now restarts the database once (a few seconds)
  when the cache is smaller than it should be. The cloud server already has its 4 GB.

## 1.29.0 — 25 October 2026

- **Ready for many years of data.** Tested with about ten years of a busy factory's records
  (3 million accounting lines, 1.6 million stock movements, 200,000 deliveries):
  - a month's profit & loss now takes 0.06 seconds (was 4.4), the cash flow 0.6 (was 20),
    the balance sheet 2.5 (was 11.7), one account's balance 0.13 (was 3.6);
  - working out the cost of stock — done on every delivery, receipt and production run — takes
    0.03 seconds (was 2.3), so saving documents stays quick as the history grows;
  - lists stay instant (a page of 200,000 deliveries in 0.02 seconds).
- **The database server uses the computer's memory properly.** It was using only 128 MB as its
  working cache; an install or update now gives it about a third of the computer's memory (4 GB on
  the cloud server), without restarting anything. Very slow requests are now written to a log.

## 1.28.1 — 25 October 2026

- **Fix: updates no longer stop when the first admin account is switched off.** Once you have your
  own administrator login and switch off `admin@example.com`, an update now leaves that account
  switched off and carries on, instead of stopping halfway.

## 1.28.0 — 25 October 2026

- **Back button.** Every page under a list — a user, an invoice, a delivery, a form — has a
  **Back** button at the top left, beside the page path. It returns to where you came from, and a
  list comes back with the same filters and page you left it on. If you opened the page directly
  (from a link or after a reload), Back goes to its list instead. No more scrolling down the menu.

## 1.27.0 — 25 October 2026

- **Every list can be filtered, sorted and sized.** Above each list: its own filters (search,
  status, customer and so on), a **From / To** period on the list's date, and **Rows** — 25, 50,
  100 or **All**. Click any column heading to sort by it; click again to turn the order round.
  **Clear filters** puts the list back as it opened.
- **"All" shows everything.** With no dates chosen it shows every record ever entered; choose a
  From / To period to see all the records of just that period. Very long lists (more than 5,000
  records) still go on to a second page so the screen stays quick.
- Under each list: how many records match and which are shown.
- **Fix: dropdowns show every choice when opened.** Before, opening a dropdown that already had
  a choice (for example "All customers") showed only that choice until its text was erased.

## 1.26.0 — 25 October 2026

- **Cash flow statement.** Reports → Cash Flow shows where money came from and where it went for any
  dates: from customers, to suppliers, expenses, salaries, loans, assets and so on, grouped into
  day-to-day business, assets and loans. It starts from the cash and bank you had and ends at what
  you have, and checks itself against the books. Download it as PDF or Excel.
- **Compare profit and loss.** On Profit & loss, choose "Compare with" — the period before (for
  example October beside September) or the same period last year. Each account shows both figures
  and how much it went up or down.
- **Give an unused advance back.** On a customer advance, **Give money back to the customer** records
  money returned from cash or bank. On a supplier advance, **Money returned by the supplier** records
  the supplier giving back what was not used. Both can be reversed.
- **Money back for a discount.** A discount or quality allowance can now be paid back in cash or by
  bank when the invoice is already paid — choose "Money back" on the credit note.
- **Bank charges on a bounced cheque.** When marking a cheque bounced, enter what the bank charged.
  It is taken from that bank account and shown as a Bank Charges expense.

## 1.25.0 — 25 October 2026

- **Loan interest rate.** Give a loan its interest rate (% a year). Each time you record a payment,
  this month's interest is filled in for you — what is still owed × the rate ÷ 12 — and you can
  change it to match the bank slip. The rate can be changed later if the bank changes it.

## 1.24.0 — 25 October 2026

- **Payslips.** Open a salary payment and click **Payslips** (everyone in it) or **Payslip** beside one
  person. Each slip shows the days paid with their day rate, what was earned, the advance taken back,
  what was handed over and the advance still owed, with a line for the employee to sign. Print it,
  or download a PDF (one slip per A5 page).
- **Loans and fixed assets on the Finance Dashboard.** Two new figures: loans still owed and what
  the assets in use cost, each opening its own page.
- **Fix: assets and loans from before the books began.** An asset you already owned, or a loan
  already running, can now be entered with its real (older) date — for example a tractor bought
  before July. Its opening entry goes on the day you enter it; the real date stays on the record.

## 1.23.1 — 25 October 2026

- **Pay salaries to each person separately.** On Pay salaries every employee now has a tick box:
  untick anyone you are not paying today, and only the ticked people are paid. The others keep
  their unpaid days for another day.

## 1.23.0 — 25 October 2026

- **Cheques** (Cash & bank → Cheques). Every cheque you received or issued, with where it stands:
  waiting (and "not yet due" for a post-dated one), cleared or bounced. Mark one cleared when the
  bank has it. Mark one **bounced** and its receipt, payment or advance is reversed for you — the
  customer owes the invoice again.
- **Bank reconciliation** (Cash & bank → Bank reconciliation). Enter your bank statement's date and
  closing balance, tick the lines the statement shows, and finish when the difference is zero. A
  ticked cheque counts as cleared. The latest statement can be undone.

## 1.22.0 — 25 October 2026

- **Customer advances** (Finance → Customer advances). Record money a customer pays before there is
  an invoice — it is held as owed to them, then applied to their invoices as you raise them.
- **Supplier advances** (Finance → Supplier advances). Record money paid to a supplier before their
  invoice — it is held as owed by them, then applied to their invoice when it arrives.
- Cash, cheque (with its number, date and bank) or bank transfer; what is left of each advance is
  always shown; applications and advances are undone with Reverse. Money in and out on the monthly
  report and the Telegram /today answer now include advances.

## 1.21.0 — 25 October 2026

- **Discounts and quality allowances** (Finance → Credit notes). Reduce what a customer owes on an
  invoice — a price discount agreed after the sale, or an allowance for poor quality — without any
  goods coming back. The amount goes to a new account, 4190 Sales Discounts and Allowances, so
  your sales figure shows it. Never more than the invoice still owes; a mistake is undone with
  Reverse. Telegram reports each one.

## 1.20.0 — 25 October 2026

- **Year-end closing** (Finance → Year-end closing). Your financial year runs 1 April to 31 March.
  From 1 April, an accountant clicks **Close the year**: the year's profit moves into Retained
  Earnings with one entry dated 31 March, and the new year's profit starts from zero. The profit
  and loss report still shows each year's real figures. Close the year before closing March; a
  mistake can be undone with **Reverse**. Two new permissions control who may close and reverse.

## 1.19.0 — 25 October 2026

- **Download any report as PDF or Excel.** Every report — profit and loss, balance sheet, trial
  balance, general ledger, cash and bank, money owed by customers and to suppliers, stock and
  inventory value, product profitability — has **PDF** and **Excel** buttons next to Print. The file
  shows exactly what is on screen, with your letterhead on the PDF; in Excel the figures are real
  numbers you can add up. Only people who may see a report can download it.

## 1.18.0 — 25 October 2026

- **New accounting months open by themselves.** Every night the system makes sure this month and
  next month are open, so postings never stop on the 1st because nobody opened the new month. It
  only continues the months you already have and never closes one — closing stays your decision.
- **Backups are copied to Google Drive** every night (folder Coconut-ERP-backups, kept 90 days),
  so a copy survives even if the server is lost. The system can only see the files it puts there.

## 1.17.0 — 25 October 2026

- **Ask the Telegram bot for figures.** In a private chat with the transactions bot, type `/` and
  tap a command: **/stock** (stock on hand — tap a product to see it per warehouse), **/cash** (cash
  and bank balances), **/today** (deliveries, invoices, goods received, production and money in and
  out so far today) and **/owed** (who owes us, whom we owe, loans still owed).
- **Only people who link their Telegram** get answers — each person clicks "Link my Telegram" in
  Settings → Profile, then Start in Telegram — and each sees only what their ERP permissions allow.
  The bot answers in their ERP language, never in a group, and can never change anything.
- An administrator switches it on with **Switch commands on** under the Transactions bot
  (Administration → Telegram settings).

## 1.16.0 — 25 October 2026

- **A Telegram message for every transaction** (Administration → Telegram settings → Transactions
  bot). Make a second bot with @BotFather for it. Every goods receipt, delivery, production run,
  invoice, receipt, payment, expense, transfer, return, damage write-off, salary, advance, loan,
  loan payment, asset and maintenance job is reported with its details — who, what, how much, who
  posted it and a link to open it — within a minute of being posted, and again if it is reversed.
  Tick which kinds you want. Telegram being slow or down never holds up your work: messages wait
  and go out when it is back.
- **Monthly report on the 1st** at 8 AM: a full picture of last month by email (to everyone with
  "Receive the monthly report email" — administrators always) and on Telegram (each bot has a
  "send the monthly report here" tick). Profit with a comparison to the month before, sales by
  customer, goods bought by supplier, money in and out, expenses by account, cash and bank, money
  owed, loans still owed, production, stock value, returns, damage, bags with customers and assets.
  "Send last month's report now" on the Telegram page shows you one straight away.
- **Fix:** buttons limited to a few clicks a minute (Telegram's find chats / test / monthly
  report, the test email, changing password or language) shared one count, so a few clicks on one
  could make another say "Too many requests". Each now has its own count. Telegram's own "slow
  down" is now waited out instead of counted as a failure.

## 1.15.0 — 25 October 2026

- **Assets** (Cash & bank → Assets). Record vehicles, machines and everything the factory owns —
  paid in full, bought on a loan (with an optional down payment; the rest becomes a loan with its
  own reminders) or already owned. Sell or scrap an asset and see the profit or loss against what
  it cost. Each asset keeps a maintenance and repair history with its costs.
- **Loan reminders on Telegram** as well as email (Administration → Telegram settings): create a
  bot with @BotFather, paste its token, choose the chats and send a test message.
- **Fix:** the loan reminder email could not be drawn and would have failed to send.

## 1.14.0 — 24 October 2026

- **Customer returns** (Sales → Returns, or "Record a return" on a delivery). The customer is
  credited at the price charged; good goods go back into stock at what they cost and damaged ones
  are written off as a loss. Pay them back by reducing what they owe, with money back, or with
  other goods of the same value (record that delivery and invoice, and the return pays it).
- **Loans** (Cash & bank → Loans). Add loans already running (at what is still owed) or new ones,
  record each installment with its interest from the bank slip — more or less than usual — and see
  what is still owed on every loan.
- **Loan reminders by email** to administrators 7 days before, 2 days before and on the due day,
  unless that month's installment is already recorded.

## 1.13.0 — 24 October 2026

- **Pay staff for any period** (Payroll → Salaries, formerly Monthly salaries). Pay one person
  for a single day after work, the few days someone worked, or everyone for a month. Days already
  paid are shown as paid and are never paid twice; paying the rest of a month later pays only the
  days not yet paid.
- **You choose how much salary advance to take back** from each person, from nothing up to what
  they owe (and no more than they earned). Whatever is not taken back stays owed.
- **Only paid days are locked** on the attendance register: a paid day shows "Paid" and cannot be
  changed, while everyone else's days stay open.

## 1.12.0 — 24 October 2026

- **Coconut shell has no cost.** When copra is made, the whole coconut cost goes to the copra and
  the shell enters stock at Rs. 0, so selling shell is all profit. Copra made before today keeps
  its old 75 / 25 split.
- **Damaged stock** (Inventory → Damaged stock). Write off anything damaged and thrown away —
  torn bags, rotten coconuts, wet copra. The stock leaves at what it cost and the value is
  recorded as a loss. A mistake can be reversed.
- **Bags are a stock item.** Woven polypropylene bags are bought on goods receipts, counted in
  stock and written off when torn.
- **Bags given to customers** (Sales → Customer bags). Bags sent free with the goods are counted
  against the customer until they come back; bags that never come back can be marked lost. The
  screen shows who holds how many.
- **Coconuts by size.** Large, small and immature coconuts — whole and dehusked — are separate
  stock items, each with its own price and cost. Dehusking keeps the size; husk, copra and shell
  stay one stock each. The dehusking and copra screens and simple mode ask which coconuts are
  used. Coconuts already in stock stay as they are until they are used up.
- **Updates now add new accounts, products and recipes automatically**, without changing anything
  you have edited.

## 1.11.2 — 19 October 2026

- **Security updates** to two components the web server relies on (request handling and text
  formatting), closing known weaknesses that could have been used to slow the server down.
- **Browsers are told to use HTTPS only** (an HSTS header), on servers with a trusted certificate.
  Servers with a self-signed certificate are left as they are, so their warning can still be
  accepted.

## 1.11.1 — 19 October 2026

- **The API refuses plain HTTP outright.** A phone app pointed at an `http://` address now gets a
  clear "only available over HTTPS" error instead of being quietly redirected, so a wrongly set
  up app is noticed at once and its sign-in token never keeps crossing the network unprotected.
  Websites typed with `http://` are still sent to `https://` as before.

## 1.11.0 — 19 October 2026

- **Payroll in the mobile API.** The phone app can now work with employees, the daily attendance
  register, salary advances and monthly salaries — with the same permissions and rules as the
  website. Paying an advance or a month's salaries is protected against being paid twice if the
  phone sends the same request again.

## 1.10.0 — 19 October 2026

- **Weekly update email.** Every Sunday at 8 PM the system emails each administrator a colourful
  summary of the week: net profit, sales and costs, top products, cash and bank balances, money
  owed (with what is overdue), production runs and the current stock value. Other roles can be
  given it with the new "Receive the weekly update email" permission.
- **Email settings** (Administration → Email settings): enter the Gmail address and its app
  password on screen, switch email on and send a test. The password is stored encrypted and is
  never shown again.
- **Charts on the main dashboard:** revenue and costs for six months, top products sold in the
  last 30 days, stock value by product and cash/bank balances — each shown only to people who may
  open that report.

## 1.9.1 — 19 October 2026

- **The company logo on the sign-in page.** The logo from Administration → Company details now
  shows on the sign-in screens too. Only the logo is shown before signing in; the company's
  other details still need a sign-in.
- **Charts can be hidden.** Screens with charts have a **Hide charts / Show charts** button next to
  Print, and Settings → Appearance has a **Charts** switch. Each computer or phone remembers its
  own choice; the tables always stay.

## 1.9.0 — 19 October 2026

- **Charts.** The finance dashboard shows how revenue becomes net profit, revenue against costs
  for the last six months, and how old the money owed to and by the company is. Profit & loss,
  inventory valuation, product profitability and cash/bank position each gain a chart above
  their table. Every chart is drawn from the same figures as its report.
- **Beauty mode scrollbars** take the chosen style's colours instead of plain grey.

## 1.8.1 — 19 October 2026

- **Beauty mode colours the whole screen.** The centre of the screen and its boxes take a soft
  wash of the chosen style's colours instead of staying white.
- **The company logo in the menu.** Once a logo is uploaded in Administration → Company details,
  it replaces the default coconut mark at the top of the menu.

## 1.8.0 — 19 October 2026

- **Beauty mode** (Settings → Appearance), optional and off by default. It adds colourful screens,
  frosted-glass panels, gradient headings and buttons, iPhone-style animations as screens open,
  and confetti when something is saved. Choose one of four styles: **Coconut Grove**, **Mulberry
  Dusk**, **Cerulean Sky** or **Petal Pastel**. Each computer or phone remembers its own choice.
  With it off nothing changes, and printouts are always plain.

## 1.7.1 — 19 October 2026

- **Clean printouts.** The browser no longer adds its own date, page title and web address at
  the top and bottom of a printed invoice or report. The invoice page also reminds you that
  choosing "Save as PDF" as the printer gives a PDF file to send to a customer.

## 1.7.0 — 19 October 2026

- **Print invoices on A4.** A sales invoice has a **Print invoice** button. It opens the invoice
  as the customer receives it: the company logo, name and address, the customer, each item with
  quantity, unit price and amount, the total and balance due, and signature lines. A draft or
  reversed invoice is clearly marked as not valid.
- **Print reports.** Reports and statements (profit & loss, balance sheet, trial balance, general
  ledger, aging, cash/bank book, stock on hand, stock ledger, salaries, attendance and others)
  have a **Print** button. The printout drops the menu, starts with the company letterhead and
  always prints light, even in dark mode.
- **Company details** (Administration → Company details): the company name, address, phone,
  email, registration number and logo used on every printout. Administrators can change them;
  other roles need the new "company details" permission.

## 1.6.1 — 18 October 2026

- **A list with only one choice fills itself in.** With a single warehouse (MAIN), for example,
  the warehouse is shown already chosen instead of a list to pick from. As soon as a second
  warehouse is added, the searchable list comes back. Lists with an "All" or "None" choice, and
  batch (lot) pickers, still wait for you to choose.

## 1.6.0 — 18 October 2026

- **Every dropdown is searchable.** Click a list and type a few letters: it shows only the entries
  containing them — in the code or the name — so a supplier, product, account or employee is
  found without scrolling.
- **Supplier and customer codes are automatic.** A new supplier gets the next code (SUP-029,
  SUP-030 …) and a new customer the next CUS- code, continuing from the highest number already
  used. Existing codes are kept and can still be corrected on the edit screen.
- **Copra: the raw shell weight is optional**, because the factory has no scale for shell. A run
  can record only the shell count (one per coconut). If a weight is entered it must be above
  zero. Simple mode and the phone app no longer ask for it. Earlier runs keep their weights.
- **Fixed:** pages could answer "502 Bad Gateway" as the screens grew, because the list of files
  sent with each page outgrew the web server's limit. The list is now capped.

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
