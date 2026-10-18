# Payroll — employees, attendance, salary advances and monthly salaries

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
| Salary advances | Taken back **in full at month end**. What a salary cannot cover **carries to the next month**. |
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
- **Payroll** (`payroll_runs` + `payroll_lines`, `PAY-YYYYMMDD-000001`) — one calendar month,
  paid in one step (`PayPayroll`). Each line stores what one employee was paid: full / half /
  leave days, gross, advances taken back, net. Immutable; corrected by reversal
  (`ReversePayroll`).

## The calculation (`App\Services\Payroll\PayrollCalculator`)

```
gross    = Σ each unpaid attendance day in the month (full = rate, half = rate ÷ 2, leave = 0)
owed     = paid advances dated up to the month's last day − advances taken back by standing payrolls
deducted = min(owed, gross)
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

- **One payroll per month.** A second is refused while the first stands.
- A month is paid **on or after its last day**.
- **A paid month is frozen:** its attendance cannot be added to or changed (`RecordAttendance`
  refuses, and the model refuses to change a paid day). Reversing the payroll releases the days
  and makes its deducted advances owed again; the month can then be corrected and paid again.
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
- Payslip printing, salary by bank file, payroll in the mobile API / simple mode.
- Interaction with **dehusking labour**: dehusking credits 6310 Production Labour Absorbed (a
  contra-expense) while salaries debit 6300 — by design, so labour sitting in unsold husk offsets
  wages until sold. The accountant should know both lines exist.
