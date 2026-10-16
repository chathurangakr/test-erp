# What's new — Coconut Factory ERP

Each release has a version number, shown at the bottom of the menu and on the sign-in page.
The middle number goes up for new features, the last number for fixes (1.0.0 → 1.0.1 → 1.1.0).
To install a release, run `bash ~/coconut-erp/deploy/coconut-erp.sh` on the server computer.

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
