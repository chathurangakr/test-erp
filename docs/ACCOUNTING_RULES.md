# Accounting Rules — Coconut Factory ERP

> **Status:** Rules confirmed. Implementation **not started**. Detailed finance and cost
> allocation must **not** be built yet. This document defines the contract for later work.

## 1. Double-entry accounting

The system uses **double-entry accounting**. Every financial effect is recorded as balanced
debits and credits.

## 2. The balance rule

**Every posted journal must satisfy:**

```
Total Debit = Total Credit
```

This must be enforced:

1. In the posting action, before the journal is written; and
2. Inside a database transaction, so an unbalanced journal can never be persisted.

## 3. Prohibited

**Never directly modify account balances.**

Account balances are **derived** from posted journal lines. There is no authoritative
balance column to update. As with inventory, a stored balance has no history and cannot be
audited.

## 4. Precision

- All monetary columns must be **`decimal`**.
- **Never use float or double for accounting values.** Floating-point arithmetic loses
  precision and will eventually produce journals where debits and credits differ by a
  fraction of a cent.

## 5. Auditability

Financial records must maintain auditability:

- Every journal line traceable to its **source document** and the **user** who posted it.
- **Audit history is never deleted.**
- Posted journals are **never silently modified**.
- Corrections are made by posting a **reversing journal**, per business rules.

## 6. Costing

- Inventory costing method: **Weighted Average Cost** (intended).
- Production / joint-product cost allocation will later support a **configurable** method,
  such as market value / NRV.
- **Not to be implemented yet.**

## 7. Open questions (`TBD`)

| # | Question |
| --- | --- |
| 1 | What is the chart of accounts? |
| 2 | Single currency (LKR) or multi-currency? |
| 3 | What are the fiscal period and year-end rules? |
| 4 | Which joint-product allocation method — market value, NRV, or other? |
| 5 | Is tax (VAT/SVAT) in scope? |
| 6 | Which business events generate journals, and with what account mappings? |
