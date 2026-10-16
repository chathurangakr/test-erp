# Android app — handoff for the next Claude session

> Written 16 October 2026 at the end of a long session on the ERP, for a **new Claude session
> that will build the Android app**. It records where the project stands, everything decided in
> that session, and what the owner wants from the app. Read this first, then `CLAUDE.md` and
> `docs/API_V1.md`.

---

## 1. The owner and how to work with them

- **Chathu** owns the project. **Not a developer**: explain in
  plain language, show what will change before doing it, and ask before anything hard to undo.
- Commit and push **only when asked**. GitHub: `chathurangakr/test-erp` (public), branch `main`.
- This PC: Ubuntu. Ask the owner for the sudo password (never write it into the repository — it is public) and say what a sudo command does before running it.
- GitLab runs on this PC and owns ports **80/443**. The ERP dev site is **`https://localhost:8443`**
  (self-signed certificate). The PC's LAN address was `192.168.4.214` on 16 Oct 2026 — check
  with `hostname -I`, it can change.

## 2. What the owner asked for (their words, lightly tidied)

> "I'm planning to make an Android app for this to use **offline**. Once I come home and connect
> to wifi, open the app and **sync with the database via the API**. Later I will deploy this app
> on the internet. So I want to **sync transactions which happened earlier**. Keep some way to
> **track everything to prevent duplicates**. Once you develop the Android app let me know — I
> will connect my phone via **USB or wifi with developer mode** to test the application."

So the app is **offline-first**:

1. Entries (goods received, goods dispatched, production runs…) are recorded on the phone with
   no network, and kept in a queue on the phone.
2. When the phone is on wifi (at home now; over the internet later, once the ERP is on a cloud
   server), the app sends the queue to the ERP's API.
3. **Nothing may ever be recorded twice**, however often a send is retried, lost or interrupted.
4. Everything is traceable: which phone, which person, when it was entered, when it reached the
   ERP, and which ERP document it became.

## 3. The ERP in one page

Coconut Factory ERP — purchasing, production (dehusking, husk chipping, copra), sales, stock and
full double-entry accounting for a coconut factory in Sri Lanka.

| | |
| --- | --- |
| Code | `/home/chathu/Desktop/Thusitha-ERP` |
| Stack | Laravel 13, PHP 8.5, Vue 3 + Inertia v3, MariaDB, Nginx |
| Current version | **1.2.0** (`VERSION`, `CHANGELOG.md`, tags `v1.0.0` … `v1.2.0`) |
| Mobile tech | `CLAUDE.md` names **Flutter** as the mobile client — Flutter builds the Android app |
| API | `/api/v1`, 135 endpoints, fully documented in `docs/API_V1.md` |
| Rules | `CLAUDE.md` (mandatory), `docs/BUSINESS_RULES.md`, `docs/INVENTORY_RULES.md`, `docs/KIOSK.md` |

### Installing and updating the ERP (already done, for context)

- A customer installs or updates with one file:
  `wget https://raw.githubusercontent.com/chathurangakr/test-erp/main/deploy/coconut-erp.sh` then
  `bash coconut-erp.sh`. Later updates: `bash ~/coconut-erp/deploy/coconut-erp.sh`.
- Installed on the owner's Lenovo (shows version 1.0.1).
- Version **1.1.0** added cloud-server support (`docs/CLOUD_SERVER.md`): with `SERVER_NAME`
  blank it uses the public IP on a cloud, opens ports in Oracle's iptables, needs no password
  where sudo has none. Tested on an Oracle-like Docker machine, **not yet on a real Oracle server**.

### Oracle Cloud server (in progress when this was written)

The owner is creating an Oracle **Always Free** VM: Ubuntu 24.04, VM.Standard.A1.Flex (ARM),
2 OCPU / 12 GB, 50 GB disk, their own SSH key, **public IPv4 must be Yes**. They were given an
OCI CLI script for Cloud Shell (VCN, internet gateway, route, security list for 22/80/443,
public subnet, instance `ThusithaERP-VM`). The script takes 2–3 minutes; installing the ERP on
the server afterwards takes 10–20 minutes. When the owner buys a domain, **Let's Encrypt
(certbot) is to be added to `deploy/provision.sh`** — promised, not built. Until then the server
is reached by IP with a self-signed certificate, which matters for the app (§9).

### Guides

- Short user guide: Claude Doc https://claude.ai/artifact/KbDvmMoLpScysQ9tB9PwA5, PDF
  `~/Desktop/Coconut-ERP-User-Guide.pdf`.
- Detailed manual (English + Sinhala screenshots):
  https://claude.ai/code/artifact/a4217e62-f40f-402b-a74e-1f301f74a9d1, PDF
  `~/Desktop/Coconut-ERP-Detailed-Manual.pdf`. Copies of both (v1.0.1 edition) in `docs/manuals/`.
- When the app exists, both guides need an "Using the phone app" section.

## 4. The API the app will use (already built)

Full detail: `docs/API_V1.md`. The essentials:

- **Sign in:** `POST /api/v1/auth/login` with `email`, `password`, `device_name` (plus `code` if
  the account uses two-factor). Returns a Sanctum bearer token **once** (prefix `cerp_`).
  Send `Authorization: Bearer <token>` and `Accept: application/json` on every call.
- **Token life 90 days.** Deactivating the user or changing their password deletes every token.
  `GET /api/v1/auth/me` returns the user, roles and **effective permissions** — use it to decide
  which screens the app shows (the server still checks every call).
- **Same permissions as the website.** No mobile-only roles.
- **Decimals are strings** (quantities 3 dp, money 6 dp). Parse them with Dart's `decimal`
  package — **never `double`**. Send money with at most 2 decimals.
- Dates `YYYY-MM-DD`; timestamps ISO 8601 UTC. The business calendar is **Asia/Colombo**.
- Errors: 401 token problem · 403 no permission · 404 · **409 wrong document state** (under the
  `status` key) · **422 validation or business rule** (e.g. not enough stock) · 429 rate limit
  (120 requests/min per user, 5 sign-ins/min).
- `GET /api/v1/meta` and the master-data lists (`/products`, `/suppliers`, `/customers`,
  `/warehouses`, …) give what the app must cache for offline use. Lists paginate, max 100.

### The jobs that suit an offline phone

These are the **simple-mode (kiosk)** jobs already on the website at `/kiosk`, made for factory
workers with big buttons in Sinhala and English (`docs/KIOSK.md`):

| Job | Online API (two calls; offline: use the `/record` endpoint in §5) | Permissions needed |
| --- | --- | --- |
| Receive goods | `POST /goods-receipts` then `POST /goods-receipts/{id}/post` | `purchasing.grn_create` + `purchasing.grn_post` |
| Dispatch goods | `POST /deliveries` then `POST /deliveries/{id}/post` | `sales.delivery_create` + `sales.delivery_post` |
| Dehusking | `POST /production/dehusking` then `…/{id}/post` | `production.create` + `production.post` |
| Husk chipping | `POST /production/husk-chipping` then `…/{id}/post` | same |
| Copra | `POST /production/copra` then `…/{id}/post` | same |
| Stock on hand | `GET /inventory/stock` (read only) | `inventory.view` |

Suggested scope for the app's first version: exactly these, plus sign-in, language switch and a
sync screen. Finance (invoices, payments, reports) stays on the website at first.

## 5. The server side of offline sync — BUILT (ERP version 1.2.0)

Built in the ERP session on 16 October 2026, at the owner's request. **The full contract is
`docs/API_V1.md` §17 — read it.** In short:

- **Save and post in one call** — `POST /api/v1/goods-receipts/record`, `/deliveries/record`,
  `/production/dehusking/record`, `/production/husk-chipping/record`, `/production/copra/record`.
  The body is the same as the matching create endpoint. They reuse
  `App\Actions\Kiosk\RecordKioskEntry` (simple mode's *Done*), so either the document is posted or
  nothing is written. Each needs the job's **create AND post** permission. **The date is not
  forced to today** — send the date the work was done. Optional `recorded_at` (ISO 8601, the moment
  it was entered on the phone) is stored.
- **`Idempotency-Key: <UUID>` header on every call** (422 without one). The phone makes the UUID
  once when the entry is recorded and sends the same one on every attempt.
  - new key → 201, header `Idempotent-Replayed: false`;
  - same key + same body + same user + same job → **nothing recorded again**, 201 with the
    document it became, `Idempotent-Replayed: true` (checked before validation);
  - same key, different body / user / job → 422 under `idempotency_key`;
  - two copies at the same instant → the loser rolls back completely and gets 409; resend → replay;
  - a refused entry (422/409) stores no key, so it can be corrected and resent with the same key.
- **Tracking:** table `api_submissions` (model `App\Models\ApiSubmission`, action
  `App\Actions\Api\RecordApiSubmission`) — key, user, token id, **device name** (the
  `device_name` sent at sign-in), job, body fingerprint (SHA-256, keys sorted), the document,
  `recorded_at`, arrival time. Written in the same transaction as the document; never changed or
  deleted.
- Tests: `tests/Feature/Api/V1/OfflineEntriesApiTest.php`.
- Not built (add only if the app needs it): a "look up this key" endpoint, offline finance
  entries, a website screen listing submissions.

### 5.1 Rules the sync must respect (they come from the ERP and cannot be bent)

- **No negative stock.** The phone cannot know the true stock offline. If a dispatch or
  production run needs more than is in stock **at the moment it reaches the ERP**, the whole
  entry is refused (422) and nothing is written. The app must show the reason and let the person
  fix or discard the entry. Never "force" it.
- **Send in the order the entries were made**, one at a time. A dispatch may depend on a receipt
  entered earlier on the same phone. Simplest safe rule: **stop the queue at the first refused
  entry**, show it, and continue only after it is fixed or discarded.
- **Posted documents are never edited.** After sync, a mistake is corrected in the ERP by
  reversal, not by changing the phone's copy and resending.
- **Master data can change while the phone is offline** (a product deactivated, a supplier
  added). Refresh the cached lists at every sync; an entry using something now inactive is
  refused with 422.
- **Document numbers are given by the ERP at sync** (`GRN-YYYYMMDD-000001` etc.). Until then
  the phone shows its own temporary label (e.g. "Not sent yet #12"), then the real number.
- **Costs are never sent.** The phone sends quantities and prices; the ERP calculates every cost.
- Derived quantities (dehusking outputs, the copra shell count) are **never sent** — the
  recipe decides (`docs/KIOSK.md`).
- Pooled products (`tracks_lots = false`) take no `lot_id`.
- A chipping product's dispatch line needs `backflush_quantity` (raw husks used) — see
  `docs/API_V1.md` §16.

## 6. What the phone should keep (suggested)

A local database (e.g. `drift` / SQLite, encrypted with SQLCipher) with:

| Table | Holds |
| --- | --- |
| cached master data | products, suppliers, customers, warehouses, recipes, last downloaded stock — each with "as of" time |
| outbox | one row per entry: `uuid`, `operation`, JSON body, `business_date`, `recorded_at`, `user_id`, `status` (`WAITING` → `SENDING` → `SYNCED` / `REFUSED`), `attempts`, `last_error`, server `document_number` + `document_id`, `synced_at` |
| sync log | every attempt: time, entry, HTTP status, message |

- The token goes in `flutter_secure_storage`, not the database.
- Entries belong to the person who recorded them; only that person's token sends them.
- If the token expired (90 days) the app asks to sign in again **and keeps the queue**.
- Never delete a `SYNCED` row automatically for at least a while — it is the phone-side proof.
- Show clearly: how many entries wait, which were refused and why, last successful sync.

## 7. Language and screens

- **Sinhala and English**, chosen per person (`users.locale`). The kiosk's words are in
  `resources/js/kiosk/text.ts` (English + Sinhala) — reuse that wording so workers see the same
  words on phone and kiosk. Terminology: `docs/SINHALA_GLOSSARY.md`.
- Server error messages come back in English by default; check how the API chooses locale
  (`SetLocale` middleware, `Accept-Language`) before relying on Sinhala messages.
- Big buttons, few fields, like the kiosk. Quantities are `NOS`, `KG` or `L` — no conversions
  between them, ever.

## 8. Where the app's code should live

Recommendation: **its own folder and its own GitHub repository** (e.g.
`~/Desktop/Thusitha-ERP-App`, repo `chathurangakr/coconut-erp-app`), so two Claude sessions never
edit the same git repository at the same time. Any further server-side change goes in this ERP repo, as a new ERP release.
Confirm the name with the owner before creating a GitHub repository.

## 9. Testing on the owner's phone

**Nothing for Android is installed on this PC yet** (`flutter`, `adb`, Java and the Android SDK
were not found on 16 Oct 2026). The app session must install them — explain each step first:
Flutter SDK, Android command-line tools + platform-tools (`adb`), a JDK, then `flutter doctor`.

Connecting the phone (owner does the phone part):

1. Phone: **Settings → About phone → tap "Build number" 7 times** → Developer options appear.
2. **USB:** Developer options → **USB debugging** on → plug in → accept "Allow USB debugging?"
   → `adb devices` lists it → `flutter run`.
3. **Wifi (Android 11+):** Developer options → **Wireless debugging** → "Pair device with pairing
   code" → on the PC `adb pair <ip>:<port>` with the code, then `adb connect <ip>:<port>`.

Reaching the dev ERP from the phone:

- Use `https://<PC LAN address>:8443/api/v1` — **not** `localhost` (that is the phone itself).
- The PC firewall must allow port 8443 from the home network (a sudo `ufw` rule — explain it and
  ask first).
- The certificate is self-signed: trust it **only in the debug build** (or install it on the phone
  as a user CA). Never switch off certificate checks in a release build. Same issue on the Oracle
  server until a domain + Let's Encrypt exists.
- The base URL must be a setting in the app (dev PC now, Oracle server later, a domain later).
- The phone and the PC must be on the same wifi for the dev server.

To test duplicate prevention properly: switch on airplane mode, record a few entries, switch it
off, sync; also kill the app mid-sync and sync again — the ERP must show each entry exactly once.

## 10. Checklist for the app session

1. Read `CLAUDE.md`, `docs/API_V1.md`, `docs/KIOSK.md`, this file.
2. Agree with the owner: app name, repo location, first-version scope (§4).
3. ~~Server side (§5)~~ — done in ERP 1.2.0. Read `docs/API_V1.md` §17.
4. Install Flutter + Android tools (§9), explaining each step.
5. Build: sign-in → master-data download → offline entry screens → outbox → sync → sync status.
6. Test on the owner's phone over USB or wifi (§9), including airplane-mode and kill-mid-sync.
7. Tell the owner, in plain language, what to try on the phone.
