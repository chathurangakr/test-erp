# Business Rules — Coconut Factory ERP

> Only **confirmed** rules are stated as rules. Unconfirmed items are marked `TBD`.
> Do not invent business rules; document the uncertainty instead.

## 1. Whole Coconut

- **Primary UOM:** `NOS`
- May be **purchased** from suppliers.
- May be **sold directly** to customers.
- May be **sent to the dehusking process**.

## 2. Dehusking

A processing operation converting Whole Coconut into two outputs.

**Fixed relationship — 1 : 1 : 1**

```
Input:   N  Whole Coconut     (NOS)
Output:  N  Dehusked Coconut  (NOS)
         N  Raw Coconut Husk  (NOS)
```

**Worked example**

| Movement | Product | Quantity |
| --- | --- | --- |
| Decrease | Whole Coconut | 1,000 NOS |
| Increase | Dehusked Coconut | 1,000 NOS |
| Increase | Raw Coconut Husk | 1,000 NOS |

This is the **only** confirmed fixed-ratio conversion in the system.

- `TBD` — Can dehusking produce a partial or failed result (input consumed without full
  output)? Not confirmed; assume 1:1:1 until told otherwise.

## 3. Dehusked Coconut

- **Primary UOM:** `NOS`
- May be **sold directly**.
- May be **used for copra production**.

## 4. Raw Coconut Husk

- **Primary UOM:** `NOS`
- Enters inventory from **two sources**:
  1. Internal coconut dehusking
  2. Direct purchase from a supplier
- Is processed through **husk chipping**.

## 5. Husk Chipping

```
Input:   Raw Husk            (NOS)
Output:  7C                  (KG)
         9C                  (KG)
         10C                 (KG)
         waste / loss        (recorded, NOT stocked)
```

**Confirmed rules**

- **Actual production output weights must be entered by the operator.**
- **There is NO fixed conversion from husks (NOS) to KG.** The system must never
  calculate output weight from input quantity.
- **Waste / loss is recorded as production waste and does NOT create inventory.** ✅ DECIDED

**Waste treatment (Version 1)** — ✅ **DECIDED**

Chipping waste and loss are recorded against the production record as **production waste**.
They do **not** create an inventory item and do **not** appear in stock.

If the factory later begins selling dust or another by-product, that material may be
**promoted to a proper inventory product** at that point. **No `DUST` product is to be
created now.**

`TBD` — whether waste should later carry an allocated cost, and the trigger for promoting a
by-product to a stocked product.

## 6. Copra Production

```
Input:   Dehusked Coconut    (NOS)
Output:  Copra               (KG)
         Raw Coconut Shell   (Qty + KG)
```

**Confirmed rules**

- Raw Shell must record **both quantity (`NOS`) and weight (`KG`)**.
- **One coconut yields one shell quantity** — unless this rule is changed later.
- **There is NO fixed NOS-to-KG conversion for shell**, because shell weights vary.
  Actual weight must be captured.

**`TBD`**

- Copra output weight per coconut — variable, operator-entered. No yield standard confirmed.

## 7. Raw Coconut Shell

- **Primary UOM:** `NOS`, **Secondary UOM:** `KG`
- May be sold **by quantity (`NOS`)** *or* **by weight (`KG`)**. The user interface may
  label the count as "Qty"; the inventory unit is `NOS`.
- Inventory must preserve **both actual quantity and actual weight** where available.

## 8. Costing

- **Intended inventory costing method: Weighted Average Cost.**
- Production / joint-product cost allocation will later support a **configurable**
  allocation method, such as market value / NRV.
- **Do not implement detailed finance or cost allocation yet.**

## 9. Transaction integrity rules

| Rule | Detail |
| --- | --- |
| No silent modification | A posted business transaction is never edited in place |
| Reversal only | Posted transactions are reversed or cancelled per business rules |
| Audit history preserved | Audit history is never deleted |
| Configurable values | Business values that could change are never hard-coded |

## 10. Summary of confirmed conversions

| Process | Input | Output | Ratio |
| --- | --- | --- | --- |
| Dehusking | Whole Coconut (NOS) | Dehusked Coconut (NOS) + Raw Husk (NOS) | **Fixed 1:1:1** |
| Husk chipping | Raw Husk (NOS) | 7C, 9C, 10C (KG) + waste (not stocked) | **Operator-entered, no fixed ratio** |
| Copra production | Dehusked Coconut (NOS) | Copra (KG) + Raw Shell (NOS + KG) | **Shell count 1:1; all weights operator-entered** |
