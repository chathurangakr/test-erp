# Report exports (PDF and Excel)

Added in 1.19.0. Every report screen has **PDF** and **Excel** buttons beside Print. The file
holds exactly what the screen shows, with the same filters (dates, period, account, product,
warehouse, customer).

| Report | Export route | Permission (same as the report) |
| --- | --- | --- |
| Profit and loss | `reports.profit-loss.export` | `reports.finance` |
| Balance sheet | `reports.balance-sheet.export` | `reports.finance` |
| Cash and bank position | `reports.cash-bank-position.export` | `reports.finance` |
| Inventory valuation | `reports.inventory-valuation.export` | `reports.inventory` |
| Inventory to ledger reconciliation | `reports.inventory-gl-reconciliation.export` | `reports.inventory` |
| Product profitability (operational) | `reports.product-profitability.export` | `reports.management` |
| Trial balance | `trial-balance.export` | `finance.view` |
| General ledger (one account) | `general-ledger.export` | `finance.view` |
| Cash and bank book | `cash-bank-book.export` | `finance.cash.view` |
| AR aging / deliveries not invoiced | `ar-aging.export` / `unbilled-deliveries.export` | `finance.ar.view` |
| AP aging / goods received not invoiced | `ap-aging.export` / `grni-reconciliation.export` | `finance.ap.view` |
| Stock on hand (no values) | `inventory.stock.export` | `inventory.view` |

## Rules

- **One calculation.** `App\Services\Reporting\ReportExports` calls the same services with the
  same filters as each screen's controller and only arranges the result as tables. Figures are
  never recalculated for an export.
- **Same permission as the report**, declared on each export route (`routes/exports.php`); GET
  only, so exports never write. `?format=pdf|xlsx` (PDF by default).
- **PDF** — `resources/views/exports/report.blade.php` rendered by dompdf: the company letterhead
  and logo (inline, nothing fetched remotely), the title and filters, A4 (landscape for wide
  reports), "Printed … by …" and page numbers.
- **Excel** — `App\Support\Exports\XlsxWriter` writes the .xlsx directly with ZipArchive (no
  spreadsheet library). Money and quantities are real numbers rounded from the decimal strings
  (cents; three decimals), so they add up in Excel.
- Exports are in **English** whatever the screen language: they go to accountants and banks, and
  the PDF font has no Sinhala letters.
