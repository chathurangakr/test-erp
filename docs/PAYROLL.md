# Payroll — employees, attendance, salary advances and salaries

Added in 1.5.0. The factory pays its workers **by the day**. Payroll records who worked, works
out each month's pay, takes back salary advances, and posts every payment to the general ledger.

## Confirmed rules (from the owner, 18 October 2026)

| Rule | Decision |
| --- | --- |
| Pay basis | A **daily rate** per employee. |
| Full day | Pays the day's rate. |
| Half day | Pays **half** the day's rate, rounded half up to the cent. |
| Leave | **Unpaid.** A day with no attendance row is not paid either. |
| Paid days | **Only days worked.** Sundays, Poya and holidays are paid only if worked. |
| Salary advances | The payer **chooses each time** how much to take back (2026-10-24), from nothing up to min(owed, earned). Whatever is not taken back stays owed. (Until 1.13.0: taken back in full at month end.) |
| Pay period | **Any period, any people** (2026-10-24): a single day after work, the few days someone worked, or a month — for one person or everyone. |
| Paid days | A day is paid **once**: days already paid are shown as paid and never included again. |
| Accounting | Payroll **posts to the accounts** (cash book, profit and loss). |
| EPF / ETF | **None.** Not modelled. |

## The records

- **Employee** (`employees`) — a master: code (**assigned automatically** by `CreateEmployee` —
  `EMP-001`, `EMP-002`… from `config/payroll.php`; never typed, never changed), name, NIC, phone, daily rate,
  joined date, active flag. Deactivated, never deleted. **No balance column**: what an employee
  earned or owes is always derived.
- **Attendance** (`employee_attendances`) — one row per employee per day: `FULL_DAY`, `HALF_DAY`
  or `LEAVE`, with the **daily rate in force when the day was recorded**. Changing an employee's
  rate never changes a day already recorded. Recorded on the daily register
  (`RecordAttendance`); a blank status clears a day. No future dates; no new day for an
  inactive employee.
- **Salary advance** (`salary_advances`, `ADV-YYYYMMDD-000001`) — paid and journalled in one
  step (`RecordSalaryAdvance`). Immutable; corrected by reversal (`ReverseSalaryAdvance`).
- **Payroll** (`payroll_runs` + `payroll_lines`, `PAY-YYYYMMDD-000001`) — any period
  (`period_start` … `period_end`) for everyone with unpaid days or only the employees chosen, paid
  in one step (`PayPayroll`). Each line stores what one employee was paid: full / half /
  leave days, gross, advances taken back, net. Immutable; corrected by reversal
  (`ReversePayroll`).

## The calculation (`App\Services\Payroll\PayrollCalculator`)

```
gross    = Σ each UNPAID attendance day in the period (full = rate, half = rate ÷ 2, leave = 0)
owed     = paid advances dated up to the pay date − advances taken back by standing payrolls
deducted = the payer's choice, 0 … min(owed, gross)   (none given → the most)
net      = gross − deducted
```

Reversed advances and reversed payrolls count for nothing. The preview on screen is only a
preview: `PayPayroll` works everything out again **under lock** at the moment of paying.

## Journals

| Event | Journal |
| --- | --- |
| Salary advance | Dr Employee Advances / Cr cash or bank |
| Payroll paid | Dr Wages (gross) / Cr Employee Advances (deducted) / Cr cash or bank (net) |
| Reversal | The exact mirror, dated the day of reversing (open period required) |

Both accounts are **configuration** (`accounting_settings.wages_account_id`,
`employee_advances_account_id`), chosen in Accounting Setup; a fresh install seeds **6300 Wages and
Salaries** and **1220 Employee Advances** (a control account — only payroll posts to it). Payroll
journals need an **open fiscal period** containing their date, like expense vouchers; they do not
depend on the operational accounting start date.

**Invariant:** the Employee Advances balance in the general ledger equals the total advances
employees still owe.

## Guards

- **A day is paid once.** Each attendance day records the payroll that paid it; a payroll takes
  only days with none, so periods may overlap and a month can be paid in several goes.
- A period is paid **on or after its last day** (a single day can be paid the same evening).
- **A paid day is frozen:** it cannot be changed (`RecordAttendance` refuses, and the model refuses
  to change a paid day), while other people's days and this person's unpaid days stay open.
  Reversing the payroll releases its days and makes its deducted advances owed again.
- **An advance a salary already took back cannot be reversed** — reverse that payroll first.
- Money leaves only through a **configured** cash or bank account, by **cash** (a cash account)
  or **bank transfer** (a bank account). Cheques are not offered: payroll records no cheque
  details.

## Permissions

`payroll.view`, `payroll.employees_manage`, `payroll.attendance_record`, `payroll.advance_pay`,
`payroll.advance_reverse`, `payroll.salary_pay`, `payroll.salary_reverse`. A new install gives
all of them to Accountant, and view / employees / attendance to Manager. Existing roles are not
changed: an administrator ticks them on the Roles screen.

## Not built (TBD)

- EPF / ETF, overtime, paid holidays, leave balances or leave types.
- Instalment deductions of advances (the owner chose full deduction).
- Payslip printing, salary by bank file, payroll in simple mode. (The mobile API has payroll
  since 1.11.0 — `docs/API_V1.md` §19.)
- Interaction with **dehusking labour**: dehusking credits 6310 Production Labour Absorbed (a
  contra-expense) while salaries debit 6300 — by design, so labour sitting in unsold husk offsets
  wages until sold. The accountant should know both lines exist.

## Payslips (1.24.0)

Every paid payroll has payslips — one per person — at Payroll → Salaries → (a payroll) →
**Payslips**, or **Payslip** beside one person. `GET payroll/salaries/{payroll}/payslips`
(`?employee_id=` for one person, `?format=pdf` for a PDF with one slip per A5 page), permission
`payroll.view`.

`App\Services\Payroll\Payslips` reads, never recalculates: the payroll line's days, earned, advance
taken back and paid; each attendance day it paid with the rate it was recorded at; and **the advance
still owed right after this payment** — standing advances dated up to the pay date, less what
standing payrolls took back up to and including this one. A reversed payroll's slips are marked
REVERSED. The PDF is in English (its font has no Sinhala letters); the page follows the reader's
language and prints from the browser too.
