# Fixed assets — what the factory owns, its maintenance and its sale

Added 2026-10-25 (owner's request).

## Confirmed rules (from the owner, 24-25 October 2026)

| Rule | Decision |
| --- | --- |
| Value | **Purchase price only — no depreciation.** An asset stays at what it cost until sold. |
| Paying for it | Cash or bank in full, **on a loan** (optionally with a down payment), or already owned. |
| Selling | Profit or loss = **sale price − purchase price**. Scrapping is a sale at 0. |
| Maintenance | A log per asset: date, work done, who did it, cost paid from cash or bank. |

## The records

- **Asset** (`fixed_assets`, `AST-YYYYMMDD-000001`) — name, kind (`AssetCategory`), description,
  purchase date and price, how it was paid for (`AssetFunding`), status `IN_USE` / `SOLD` /
  `REVERSED`. The purchase figures are immutable; name, kind and description (`FixedAsset::EDITABLE`)
  may be corrected. The sale is written on the asset once and cleared if the sale is reversed.
- **An asset bought on a loan opens that loan** (`loans.funding = ASSET`, `fixed_asset_id`) for the
  price less any down payment, with its lender, installment and due day — so its payments and
  reminders work like any other loan (`docs/LOANS.md`). The asset's purchase journal is the loan's
  journal; such a loan is undone by reversing the asset, never on its own.
- **Maintenance** (`asset_maintenances`, `MNT-YYYYMMDD-000001`) — work done and its cost; a free job
  (cost 0) is history only, with no journal. Immutable; corrected by reversal.

## Journals

| Event | Journal |
| --- | --- |
| Paid in full | Dr fixed assets / Cr the cash or bank account |
| On a loan | Dr fixed assets / Cr cash or bank (down payment) / Cr loans payable (the rest) |
| Already owned | Dr fixed assets / Cr opening balance equity |
| Sale | Dr cash or bank (price) / Cr fixed assets (cost) / Cr gain on asset sales, or Dr loss on asset sales |
| Maintenance | Dr repairs and maintenance / Cr the cash or bank account |
| Reversal | The exact mirror |

Accounts are `accounting_settings` configuration — `fixed_assets_account_id` (**1500 Property,
Plant and Equipment**), `asset_gain_account_id` (**4900 Gain on Sale of Assets**),
`asset_loss_account_id` (**6960 Loss on Sale of Assets**), `maintenance_account_id` (**6600 Repairs
and Maintenance**) — seeded on a fresh install and added by `masters:add-new` on an update.

**Invariant:** the fixed assets balance equals the cost of the assets in use.

## Guards

- An asset can be reversed only while in use, with no standing maintenance and no standing payment
  on its loan; its loan is reversed with it.
- Only an asset in use is sold or maintained; a sale cannot predate the purchase.

## Permissions

`assets.view`, `assets.manage`, `assets.reverse`, `assets.sell` (also reverses a sale),
`assets.maintenance_record`, `assets.maintenance_reverse`. A new install gives them to Accountant.

## Not built (TBD)

Depreciation (declined by the owner for now), revaluation, part disposals, maintenance planning or
service reminders, assets in the mobile API.

## Older assets (1.24.0)

An asset **already owned** may be entered with its real purchase date even if that is before the
books began: its opening entry (`Dr fixed assets / Cr opening balance equity`) is then booked on the
day it is entered. For an older asset still being paid off, enter the asset as **already owned** and
the loan as **already running** — never "bought on a loan", which would open a second loan.
