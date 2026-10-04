# Simple Mode (Kiosk) — Coconut Factory ERP

> **Status:** ✅ **IMPLEMENTED.** Big-button screens at `/kiosk` for factory staff, in Sinhala
> and English, on phones, tablets and PCs.

---

## 1. Who it is for

Factory staff who do one or two jobs and should not face a full ERP. Managers and accountants
keep the full ERP; anyone can open simple mode from the "Simple mode" sidebar link, and an
administrator can make a user **start in simple mode** after signing in (Users → Edit access).
That setting only chooses the start page — it grants nothing.

## 2. The jobs

| Tile | What staff do | What the system does |
| --- | --- | --- |
| Receive goods | Supplier → store → product → count (+ weight if weighed too) → price each → optional transport / loading cost | Saves **and posts** the goods receipt: landed cost, final unit cost, lot, stock |
| Dispatch | Customer → store → product → **batch** (lot-tracked products only) → count (+ weight) → by count / by weight (dual-unit products only) → **raw husks used** (chips and cocopeat only) → price → optional vehicle no. | Saves **and posts** the delivery: stock out at weighted-average cost, COGS; for chips/cocopeat the raw husks are taken from the pool and their cost added to COGS |
| Dehusking | Store → how many → **labour per coconut** (pre-filled from the recipe's default, editable) | Derives the 1:1:1 outputs from the recipe, gives the raw husk the labour as its cost, and posts |
| Husk chipping | Store → **tap what was made** (7C / 9C / 10C / Cocopeat, KG and litre items listed separately) → the amount of each one tapped | Keeps the measured amounts exactly and posts. **No husk is taken** — the screen says it is deducted at dispatch |
| Copra | Store → how many → copra KG → shell KG | Derives the shell count, keeps both weights, posts |
| Check stock | — | Quantities per product and store, from the inventory ledger. No money. |

After Done, a green result screen shows the **posted** document's number and what the system
actually recorded — for production, the outputs it calculated.

## 3. Rules

- **Done = save and post, in ONE transaction** (`App\Actions\Kiosk\RecordKioskEntry`). A post
  that fails — not enough stock, the wrong batch — leaves **nothing** behind: no half-made draft.
- **Only people allowed to post see a job.** A tile appears only when the user holds BOTH the
  create and the post permission for its document (`KioskTasks`); the routes require the post
  permission and the store actions also check create. This is the owner's decision: simple mode
  never produces drafts for someone else to finish.
- **No business rule lives in simple mode.** It calls the same `Save*` and `Post*` actions as the
  full ERP and the mobile API, validated by the same FormRequests (`app/Http/Requests/Kiosk/*`
  extend them). Costing, lots, negative stock, derived outputs and journals are unchanged.
- **Staff type prices** on receipts and dispatches (owner's decision). The cost of goods leaving
  is never typed — the inventory engine values it.
- **A batch must always be tapped** where the product is held in batches; it is never assumed.
  Pooled stock (coconut, dehusked coconut, raw husk, chips, cocopeat — `products.tracks_lots`
  false) has no batch, so that step is skipped (`INVENTORY_RULES.md` §15).
- **Raw husks used** is asked only for a product whose recipe defers its raw material to
  delivery — read from the recipe, never a product code — and must be more than zero. Not
  enough husk in the store refuses the whole dispatch.
- **Touch screen or keyboard — both work.** Every button is a large touch target. With a
  keyboard: digits, `.` (or `,`) type into the number pad, Backspace deletes one digit, Esc or
  Delete clears it, and **Enter is Next**. Enter is deliberately **not** Done — on the final
  check screen the person presses Done itself (tap it, or Tab to it and press Enter), so a quick
  double Enter can never post an entry nobody has checked.
- **What is typed vs calculated comes from the recipe**, never from a product code: derived
  outputs are shown as "calculated by the system" and are never sent.
- **The date is always today in Sri Lanka** (`BusinessClock`). Whatever date a client sends is
  overwritten. Back-dated entries belong in the full ERP.
- **Numbers travel as typed strings** from the keypad; the screen never does money arithmetic.
- **Idle sign-out** after `config('kiosk.idle_minutes')` (5) without a touch, with a 30-second
  warning — for shared tablets. `0` disables it.

## 4. Language

- One tap switches **සිංහල ↔ English**; the choice is a cookie, so each device remembers its own
  (`config('kiosk.default_locale')` = `si`).
- Every screen word is in **`resources/js/kiosk/text.ts`** — one file, both languages, and a test
  fails if a word exists in only one of them.
- Server messages use the same locale on kiosk routes: `lang/si.json` (business-rule errors) and
  `lang/si/validation.php` (form errors). Anything untranslated falls back to English.
- **Never add a translation for text that is SAVED** — journal and ledger descriptions such as
  "Received :product" must stay in one language whoever posted them.
- **The Sinhala wording should be reviewed by a native speaker at the factory** before real use.

## 5. Not built yet (`TBD`)

Barcode / QR scanning · printing a receipt or dispatch note · photos · offline use · waste entry
for husk chipping (its unit is still unconfirmed — use the full ERP) · Sinhala for the full ERP ·
receiving against a purchase order from the kiosk.
