# Languages — Sinhala and English

> **Status:** Step 1 ✅ **IMPLEMENTED** — every screen except Finance is bilingual. Step 2 (the
> Finance screens) is **not started**. Simple mode has been bilingual since Phase 17
> (`docs/KIOSK.md`).

---

## 1. How a person chooses

- A **සිංහල / English** button in the top bar (and on the sign-in screens) switches at once.
- The choice is **saved on the person** (`users.locale`), so it follows them to every device.
  Before signing in, a cookie remembers it. `NULL` means the system default
  (`config('app.locale')`, English) — nobody's screens change until they choose.
- Simple mode (`/kiosk`) keeps its own **per-device** choice, because kiosk tablets are shared.

## 2. One translation file

- **`lang/si.json`** serves both the server (Laravel's `__()`) and the screens (`useT()` from
  `@/lib/i18n`, bundled at build time). Keys are the English text itself, so anything not yet
  translated simply shows in English — nothing breaks.
- Placeholders use Laravel's `:name` style: `t('Showing :from–:to of :total', { … })`. Never build
  a key by joining strings.
- Page headings, breadcrumbs and sidebar items translate inside their components (`Heading`,
  `Breadcrumbs`, `NavMain`); pages pass English.
- Status and type names come from the enums' `label()` and are translated by the server.
- Form errors: `lang/si/validation.php`; sign-in: `lang/si/auth.php`, `passwords.php`;
  page numbers: `pagination.php`.
- Simple mode's words live separately in `resources/js/kiosk/text.ts`.

## 3. Saved records are ALWAYS English

**The screen language never reaches the database.** Journal and ledger descriptions, lot notes
and every other stored text are written through **`App\Support\RecordText::of()`**, which renders
in English (and renders an enum name passed to it in English too). Otherwise one person would
save "Reversal of delivery DN-…" and another the Sinhala, and reports, searches and audits would
stop agreeing.

- Never use `__()` for text that is saved. A test fails if a `'description'` / `'notes'` value is
  built with `__()` in `app/Actions` or `app/Services`.
- Never add a `RecordText` key to `lang/si.json`. A test fails if one appears.
- The permission names stored in the `permissions` table stay English; the Roles screen
  translates them on display.

## 4. Terminology

Plain everyday Sinhala. Accounting and technical terms are **Sinhala with the English term in
brackets** — e.g. "ගෙවිය යුතු ගිණුම් (Accounts Payable)" — so staff read Sinhala and the accountant
still recognises the standard term (owner's decision). Codes, document numbers, NOS / KG and
amounts are never translated.

**The Sinhala should be reviewed by a native speaker at the factory.** Wording is corrected by
editing the values in `lang/si.json` only.

## 5. Tests

`tests/Feature/Localisation/LocalisationTest.php`: the choice is saved per person; guests can
switch; only supported languages are accepted; statuses translate; saved records stay English
even when the poster uses Sinhala; no `RecordText` key is translated; **every `t('…')` on a
Step-1 screen has a Sinhala entry**; every status/type name has one.

## 6. Not done yet (`TBD`)

| # | Item |
| --- | --- |
| 1 | **Step 2 — the Finance screens** (`resources/js/pages/finance/**`, finance form components, finance server messages). They still show English. |
| 2 | Relative times from the server ("2 days ago" on passkeys) stay English. |
| 3 | The Laravel promotional text on the public Welcome page stays English. |
| 4 | A native speaker's review of all Sinhala wording. |
