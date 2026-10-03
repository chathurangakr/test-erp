# REST API v1 — Coconut Factory ERP

> **Status:** ✅ **IMPLEMENTED** in Phase 16. A versioned, token-authenticated JSON API for
> the future Flutter mobile app. The Flutter app itself is **not built yet**.

---

## 1. Architecture — the mobile app never touches the database

```
Flutter app ──HTTPS──▶ /api/v1/...  ──▶ Laravel ──▶ the SAME domain actions ──▶ MariaDB
Web browser ──HTTPS──▶ Inertia screens ──▶ Laravel ──┘
```

- The website and the API are **two front doors over one domain layer**. Posting a goods
  receipt from the phone runs `PostGoodsReceipt` — the same landed-cost allocation, inventory
  engine, locks and journal as the website. Nothing is implemented twice.
- Draft creation and editing live in shared `Save*` actions (`SavePurchaseOrder`,
  `SaveGoodsReceipt`, `SaveSalesOrder`, `SaveDelivery`, the three production `Save*Order`
  actions, the finance `Save*` actions), called by both the web controllers and the API.
- API controllers are thin: validate (with the **same** FormRequest classes as the web), call
  the action, return a Resource. They contain no costing, allocation, journal or AP/AR logic.
- The phone holds **no database credentials** and MariaDB is never exposed to the network.

## 2. Versioning

Every endpoint lives under **`/api/v1`**. There are no unversioned business endpoints; a test
fails if one appears. A breaking change will be released as `/api/v2` beside v1, so an
installed app keeps working until it is updated.

## 3. Authentication — bearer tokens per device

Laravel **Sanctum** personal access tokens (Laravel's official first-party package).

| Endpoint | Purpose |
| --- | --- |
| `POST /api/v1/auth/login` | `email`, `password`, `device_name` (required, ≤100 chars); plus `code` or `recovery_code` when the account uses two-factor authentication |
| `POST /api/v1/auth/logout` | Revokes **this device's** token only |
| `POST /api/v1/auth/logout-all` | Revokes **every** token the user holds |
| `GET /api/v1/auth/me` | The signed-in user, roles and effective permissions |

- Send the token on every request: `Authorization: Bearer <token>` and `Accept: application/json`.
- Tokens are **stored hashed**; the plain token is returned exactly once, at login. Tokens are
  prefixed `cerp_` so secret scanners can recognise a leaked one.
- **One token per sign-in**, so each device (phone, tablet) can be signed out on its own.
- Every token carries one ability, **`mobile-api`**. The 73 business permissions are **not**
  copied into tokens: the token proves *who and which device*; the user's roles decide *what
  they may do*, re-checked on every request (§5).
- A browser session cookie **never** authenticates the API (`sanctum.guard` is empty).
- **Two-factor authentication is honoured.** If the account has 2FA switched on, login needs a
  valid authenticator `code` or a one-time `recovery_code` — the API is not a way around 2FA.
- **No registration endpoint.** Mobile users sign in with accounts that already exist in the ERP.

### Expiry and revocation

| Event | Effect on tokens |
| --- | --- |
| 90 days after sign-in | Token stops working (`config('api.token_lifetime_days')`, env `API_TOKEN_LIFETIME_DAYS`) — the owner's choice |
| `logout` / `logout-all` | This token / all tokens deleted at once |
| User deactivated | All tokens deleted (`UpdateUserAccess`); a surviving token is refused anyway (`api.active` → 401) |
| Password changed (settings or reset link) | All tokens deleted — enforced once, on the `User` model |

Expiry is set per token at issue time (`expires_at`); Sanctum's global `expiration` is left
null so there is one mechanism. Expired rows are pruned daily by
`sanctum:prune-expired --hours=24` — **this needs the Laravel scheduler running on the
server** (`* * * * * php artisan schedule:run` in cron). Pruning only tidies the table; expired
tokens are refused whether or not they have been pruned.

## 4. Protection on every request

In order: HTTPS check (production) → bearer token → `mobile-api` ability → **active user** →
rate limit → **permission**.

| Limit | Default | Setting |
| --- | --- | --- |
| Sign-in attempts | 5 per minute per email + IP | `API_LOGIN_PER_MINUTE` |
| Authenticated requests | 120 per minute per user | `API_REQUESTS_PER_MINUTE` |

Exceeding a limit returns **429** with a `Retry-After` header.

## 5. Authorization — the same RBAC as the website

Every API route declares exactly **one** permission from `App\Enums\Permission` — the **same**
permission its web twin checks. There are no mobile-only roles or permissions. Examples:

| Endpoint | Permission |
| --- | --- |
| `GET /api/v1/products` | `products.view` |
| `POST /api/v1/purchase-orders` | `purchasing.order_create` |
| `POST /api/v1/purchase-orders/{id}/approve` | `purchasing.order_approve` |
| `POST /api/v1/goods-receipts/{id}/post` | `purchasing.grn_post` |
| `POST /api/v1/deliveries/{id}/post` | `sales.delivery_post` |
| `POST /api/v1/production/copra/{id}/post` | `production.post` |
| `GET /api/v1/reports/profit-loss` | `reports.finance` |

`/auth/*`, `/meta` and `/dashboard` need no permission; `/dashboard` builds only the sections
the user may see, **on the server** — a storekeeper's response contains no finance figures.

`ApiRouteAuthorizationTest` fails if any `/api/v1` route lacks the protection stack, declares
no permission (outside the list above), or disagrees with its web twin, and it calls every
protected route with and without its permission.

**Permissions never override document state.** Even an administrator cannot edit a posted
document; the action refuses it and the API answers **409**.

## 6. Responses and errors

Successful responses wrap their payload in `data`. Collections add `links` and `meta`.

| Status | Meaning | Body |
| --- | --- | --- |
| 200 / 201 | OK / created | `{"data": {...}}` |
| 401 | No, invalid, expired or revoked token; deactivated user | `{"message": "..."}` |
| 403 | Signed in, but lacks the permission (or HTTP in production) | `{"message": "This action is unauthorized."}` |
| 404 | Record or route not found | `{"message": "The requested record was not found."}` |
| 409 | **Business-state conflict** — already posted, only a draft can be edited, etc. | `{"message": "...", "errors": {"status": ["..."]}}` |
| 422 | Validation or business-rule failure (e.g. insufficient stock) | `{"message": "...", "errors": {"lines.0.quantity": ["..."]}}` |
| 429 | Rate limit | `{"message": "Too Many Attempts."}` |
| 500 | Unexpected server failure only | `{"message": "Server Error"}` |

The **409 rule**: every domain action reports a document-state conflict under the `status`
error key; the API turns exactly those into 409. Two phones posting the same GRN: the first
succeeds, the second receives 409 — the domain lock decides, not the app's button.

With `APP_DEBUG=false` (production) no stack trace, SQL, file path or model class name is ever
returned. The 404 message deliberately names no model.

## 7. Data formats

| Kind | Format | Example |
| --- | --- | --- |
| Quantity | **string**, 3 decimals | `"10000.000"` |
| Money / cost | **string**, 6 decimals | `"102.500000"`, `"1025000.000000"` |
| Percentage | string | `"21.15"` |
| Calendar date | `YYYY-MM-DD` | `"2026-09-15"` |
| Timestamp | ISO 8601, **UTC**, `Z` suffix | `"2026-09-26T16:25:39Z"` |
| Enum | `{"value", "label"}` | `{"value": "POSTED", "label": "Posted"}` |
| Related record | `{"id", "code", "name"}` | `{"id": 3, "code": "SUP-001", "name": "..."}` |
| Audit user | `{"id", "name"}` | `"posted_by": {"id": 1, "name": "..."}` |

**Decimals are strings on purpose.** A JSON number is parsed as a binary float by most clients,
and floats cannot represent accounting values exactly. The app must parse these with a
decimal-safe type (e.g. Dart's `Decimal` package), never `double`. Requests may send numbers
or numeric strings; strings are recommended.

**Timezone:** the server stores and returns UTC. Calendar dates (receipt date, delivery date)
carry no time and no zone. Localisation to the user's timezone and format is the app's job.

**Stored precision is never padded or cut.** A value is sent at the scale the ledger holds it:
quantities 3 dp, costs and document amounts 6 dp. A customer or supplier `credit_limit` is an
agreed term stored at 2 dp and is sent as such (e.g. `"150000.50"`).

**Draft documents report no calculated cost.** Cost fields on a DRAFT goods receipt, delivery
or production run are `null`; the API reports only the authoritative figures stored at posting.

## 8. Pagination, search and filtering

`?page=` and `?per_page=` (default **25**, maximum **100** — larger values are capped).

```json
"links": {"first": "...", "last": "...", "prev": null, "next": "..."},
"meta": {"current_page": 1, "last_page": 4, "per_page": 25, "total": 87, "from": 1, "to": 25}
```

Filters are named query parameters that mirror the web screens — `search`, `status`,
`supplier_id`, `customer_id`, `product_id`, `warehouse_id`, `from`, `to` and similar, per
endpoint. There is no free-form filter language.

## 9. Endpoints

See §13 for the complete generated list. Summary by module:

<!-- ENDPOINT-SUMMARY -->
| Module | Endpoints |
| --- | --- |
| Session & meta | 6 |
| Master data | 21 |
| Inventory | 3 |
| Purchasing | 13 |
| Sales | 13 |
| Production | 21 |
| Accounts payable | 13 |
| Accounts receivable | 13 |
| Cash & bank | 15 |
| Finance reports | 12 |
| **Total** | **130** |

## 10. Examples

### Sign in

```http
POST /api/v1/auth/login
Content-Type: application/json
Accept: application/json

{"email": "storekeeper@example.com", "password": "••••••", "device_name": "Stores iPhone"}
```

```json
201 Created
{
  "token": "4|cerp_Xk2...",
  "token_type": "Bearer",
  "expires_at": "2026-12-25T10:00:00Z",
  "user": {
    "id": 7, "name": "Nimal", "email": "storekeeper@example.com", "is_active": true,
    "roles": [{"code": "STOREKEEPER", "name": "Storekeeper"}],
    "permissions": ["customers.view", "inventory.view", "products.view", "purchasing.grn_post", "..."]
  }
}
```

### Permission denied

```http
POST /api/v1/purchase-orders/12/approve
Authorization: Bearer 4|cerp_Xk2...
```

```json
403 Forbidden
{"message": "This action is unauthorized."}
```

### Posting a goods receipt (decimals as strings)

```http
POST /api/v1/goods-receipts/31/post
```

```json
200 OK
{"data": {
  "id": 31, "grn_number": "GRN-20260915-000004",
  "status": {"value": "POSTED", "label": "Posted"},
  "landed_cost_total": "25000.000000",
  "posted_by": {"id": 7, "name": "Nimal"}, "posted_at": "2026-09-15T08:12:03Z",
  "lines": [{"product": {"id": 1, "code": "COCONUT", "name": "Whole Coconut"},
             "quantity": "10000.000", "unit_price": "100.000000",
             "material_cost": "1000000.000000", "allocated_landed_cost": "25000.000000",
             "final_unit_cost": "102.500000"}]
}}
```

Posting the same receipt again:

```json
409 Conflict
{"message": "Only a draft goods receipt can be posted. GRN-20260915-000004 is Posted.",
 "errors": {"status": ["Only a draft goods receipt can be posted. GRN-20260915-000004 is Posted."]}}
```

## 11. Connecting during development

- The development server is HTTPS on port **8443**, with a **self-signed** certificate.
- A physical phone must use the **ERP PC's LAN address**, not `localhost` — on the phone,
  `localhost` is the phone itself. Find the PC's address with `hostname -I` and use
  `https://<LAN-address>:8443/api/v1`. The address is never written into the application; the
  Flutter app will read its base URL from its own environment configuration.
- The phone must **trust the development certificate** (install it as a user CA on Android /
  a profile on iOS, or use a debug-only trust override in the Flutter dev build). **Do not**
  disable TLS verification in release builds, and do not weaken the server's TLS to make an
  emulator connect.
- The Android emulator reaches the host PC at `10.0.2.2`; the certificate must name that
  address, or the dev build must trust it explicitly.
- The PC's firewall must allow port 8443 from the LAN. Changing firewall rules is a deliberate
  administrator step — it is not done automatically.

## 12. Production

- Base URL like `https://erp.example.com/api/v1` on a real domain with a **trusted TLS
  certificate**. No base URL is written into Laravel business code.
- `API_REQUIRE_HTTPS` defaults to on when `APP_ENV=production`: plain-HTTP API calls are
  refused (403), never redirected — a redirect would already have exposed the token.
- `APP_DEBUG=false`, so errors reveal nothing internal.
- **CORS is closed by default.** The native app does not use browser CORS. A future browser
  client must be listed in `CORS_ALLOWED_ORIGINS`; credentials are never allowed cross-origin.
- Run the scheduler so expired tokens are pruned.

## 13. Complete endpoint list

<!-- ENDPOINT-LIST -->
| Module | Method | Path | Permission |
| --- | --- | --- | --- |
| Session & meta | POST | `/api/v1/auth/login` | public (rate-limited) |
| Session & meta | POST | `/api/v1/auth/logout` | signed in |
| Session & meta | POST | `/api/v1/auth/logout-all` | signed in |
| Session & meta | GET | `/api/v1/auth/me` | signed in |
| Session & meta | GET | `/api/v1/dashboard` | signed in |
| Session & meta | GET | `/api/v1/meta` | signed in |
| Master data | GET | `/api/v1/customers` | `customers.view` |
| Master data | POST | `/api/v1/customers` | `customers.manage` |
| Master data | GET | `/api/v1/customers/{customer}` | `customers.view` |
| Master data | PUT | `/api/v1/customers/{customer}` | `customers.manage` |
| Master data | POST | `/api/v1/customers/{customer}/status` | `customers.manage` |
| Master data | GET | `/api/v1/products` | `products.view` |
| Master data | POST | `/api/v1/products` | `products.manage` |
| Master data | GET | `/api/v1/products/{product}` | `products.view` |
| Master data | PUT | `/api/v1/products/{product}` | `products.manage` |
| Master data | POST | `/api/v1/products/{product}/status` | `products.manage` |
| Master data | GET | `/api/v1/suppliers` | `suppliers.view` |
| Master data | POST | `/api/v1/suppliers` | `suppliers.manage` |
| Master data | GET | `/api/v1/suppliers/{supplier}` | `suppliers.view` |
| Master data | PUT | `/api/v1/suppliers/{supplier}` | `suppliers.manage` |
| Master data | POST | `/api/v1/suppliers/{supplier}/status` | `suppliers.manage` |
| Master data | GET | `/api/v1/warehouses` | `warehouses.view` |
| Master data | POST | `/api/v1/warehouses` | `warehouses.manage` |
| Master data | GET | `/api/v1/warehouses/{warehouse}` | `warehouses.view` |
| Master data | PUT | `/api/v1/warehouses/{warehouse}` | `warehouses.manage` |
| Master data | POST | `/api/v1/warehouses/{warehouse}/default` | `warehouses.manage` |
| Master data | POST | `/api/v1/warehouses/{warehouse}/status` | `warehouses.manage` |
| Inventory | GET | `/api/v1/inventory/ledger` | `inventory.view` |
| Inventory | GET | `/api/v1/inventory/lots` | `inventory.view` |
| Inventory | GET | `/api/v1/inventory/stock` | `inventory.view` |
| Purchasing | GET | `/api/v1/goods-receipts` | `purchasing.view` |
| Purchasing | POST | `/api/v1/goods-receipts` | `purchasing.grn_create` |
| Purchasing | GET | `/api/v1/goods-receipts/{goodsReceipt}` | `purchasing.view` |
| Purchasing | PUT | `/api/v1/goods-receipts/{goodsReceipt}` | `purchasing.grn_create` |
| Purchasing | POST | `/api/v1/goods-receipts/{goodsReceipt}/cancel` | `purchasing.grn_cancel` |
| Purchasing | POST | `/api/v1/goods-receipts/{goodsReceipt}/post` | `purchasing.grn_post` |
| Purchasing | POST | `/api/v1/goods-receipts/{goodsReceipt}/reverse` | `purchasing.grn_reverse` |
| Purchasing | GET | `/api/v1/purchase-orders` | `purchasing.view` |
| Purchasing | POST | `/api/v1/purchase-orders` | `purchasing.order_create` |
| Purchasing | GET | `/api/v1/purchase-orders/{order}` | `purchasing.view` |
| Purchasing | PUT | `/api/v1/purchase-orders/{order}` | `purchasing.order_create` |
| Purchasing | POST | `/api/v1/purchase-orders/{order}/approve` | `purchasing.order_approve` |
| Purchasing | POST | `/api/v1/purchase-orders/{order}/cancel` | `purchasing.order_cancel` |
| Sales | GET | `/api/v1/deliveries` | `sales.view` |
| Sales | POST | `/api/v1/deliveries` | `sales.delivery_create` |
| Sales | GET | `/api/v1/deliveries/{delivery}` | `sales.view` |
| Sales | PUT | `/api/v1/deliveries/{delivery}` | `sales.delivery_create` |
| Sales | POST | `/api/v1/deliveries/{delivery}/cancel` | `sales.delivery_cancel` |
| Sales | POST | `/api/v1/deliveries/{delivery}/post` | `sales.delivery_post` |
| Sales | POST | `/api/v1/deliveries/{delivery}/reverse` | `sales.delivery_reverse` |
| Sales | GET | `/api/v1/sales-orders` | `sales.view` |
| Sales | POST | `/api/v1/sales-orders` | `sales.order_create` |
| Sales | GET | `/api/v1/sales-orders/{order}` | `sales.view` |
| Sales | PUT | `/api/v1/sales-orders/{order}` | `sales.order_create` |
| Sales | POST | `/api/v1/sales-orders/{order}/approve` | `sales.order_approve` |
| Sales | POST | `/api/v1/sales-orders/{order}/cancel` | `sales.order_cancel` |
| Production | GET | `/api/v1/production/copra` | `production.view` |
| Production | POST | `/api/v1/production/copra` | `production.create` |
| Production | GET | `/api/v1/production/copra/{copra}` | `production.view` |
| Production | PUT | `/api/v1/production/copra/{copra}` | `production.create` |
| Production | POST | `/api/v1/production/copra/{copra}/cancel` | `production.cancel` |
| Production | POST | `/api/v1/production/copra/{copra}/post` | `production.post` |
| Production | POST | `/api/v1/production/copra/{copra}/reverse` | `production.reverse` |
| Production | GET | `/api/v1/production/dehusking` | `production.view` |
| Production | POST | `/api/v1/production/dehusking` | `production.create` |
| Production | GET | `/api/v1/production/dehusking/{dehusking}` | `production.view` |
| Production | PUT | `/api/v1/production/dehusking/{dehusking}` | `production.create` |
| Production | POST | `/api/v1/production/dehusking/{dehusking}/cancel` | `production.cancel` |
| Production | POST | `/api/v1/production/dehusking/{dehusking}/post` | `production.post` |
| Production | POST | `/api/v1/production/dehusking/{dehusking}/reverse` | `production.reverse` |
| Production | GET | `/api/v1/production/husk-chipping` | `production.view` |
| Production | POST | `/api/v1/production/husk-chipping` | `production.create` |
| Production | GET | `/api/v1/production/husk-chipping/{huskChipping}` | `production.view` |
| Production | PUT | `/api/v1/production/husk-chipping/{huskChipping}` | `production.create` |
| Production | POST | `/api/v1/production/husk-chipping/{huskChipping}/cancel` | `production.cancel` |
| Production | POST | `/api/v1/production/husk-chipping/{huskChipping}/post` | `production.post` |
| Production | POST | `/api/v1/production/husk-chipping/{huskChipping}/reverse` | `production.reverse` |
| Accounts payable | GET | `/api/v1/supplier-invoices` | `finance.ap.view` |
| Accounts payable | POST | `/api/v1/supplier-invoices` | `finance.ap.invoice_create` |
| Accounts payable | GET | `/api/v1/supplier-invoices/{supplier_invoice}` | `finance.ap.view` |
| Accounts payable | PUT | `/api/v1/supplier-invoices/{supplier_invoice}` | `finance.ap.invoice_create` |
| Accounts payable | POST | `/api/v1/supplier-invoices/{supplier_invoice}/cancel` | `finance.ap.invoice_cancel` |
| Accounts payable | POST | `/api/v1/supplier-invoices/{supplier_invoice}/post` | `finance.ap.invoice_post` |
| Accounts payable | POST | `/api/v1/supplier-invoices/{supplier_invoice}/reverse` | `finance.ap.invoice_reverse` |
| Accounts payable | GET | `/api/v1/supplier-payments` | `finance.ap.view` |
| Accounts payable | POST | `/api/v1/supplier-payments` | `finance.ap.payment_create` |
| Accounts payable | GET | `/api/v1/supplier-payments/{supplier_payment}` | `finance.ap.view` |
| Accounts payable | POST | `/api/v1/supplier-payments/{supplier_payment}/cancel` | `finance.ap.payment_cancel` |
| Accounts payable | POST | `/api/v1/supplier-payments/{supplier_payment}/post` | `finance.ap.payment_post` |
| Accounts payable | POST | `/api/v1/supplier-payments/{supplier_payment}/reverse` | `finance.ap.payment_reverse` |
| Accounts receivable | GET | `/api/v1/customer-receipts` | `finance.ar.view` |
| Accounts receivable | POST | `/api/v1/customer-receipts` | `finance.ar.receipt_create` |
| Accounts receivable | GET | `/api/v1/customer-receipts/{customer_receipt}` | `finance.ar.view` |
| Accounts receivable | POST | `/api/v1/customer-receipts/{customer_receipt}/cancel` | `finance.ar.receipt_cancel` |
| Accounts receivable | POST | `/api/v1/customer-receipts/{customer_receipt}/post` | `finance.ar.receipt_post` |
| Accounts receivable | POST | `/api/v1/customer-receipts/{customer_receipt}/reverse` | `finance.ar.receipt_reverse` |
| Accounts receivable | GET | `/api/v1/sales-invoices` | `finance.ar.view` |
| Accounts receivable | POST | `/api/v1/sales-invoices` | `finance.ar.invoice_create` |
| Accounts receivable | GET | `/api/v1/sales-invoices/{sales_invoice}` | `finance.ar.view` |
| Accounts receivable | PUT | `/api/v1/sales-invoices/{sales_invoice}` | `finance.ar.invoice_create` |
| Accounts receivable | POST | `/api/v1/sales-invoices/{sales_invoice}/cancel` | `finance.ar.invoice_cancel` |
| Accounts receivable | POST | `/api/v1/sales-invoices/{sales_invoice}/post` | `finance.ar.invoice_post` |
| Accounts receivable | POST | `/api/v1/sales-invoices/{sales_invoice}/reverse` | `finance.ar.invoice_reverse` |
| Cash & bank | GET | `/api/v1/expense-vouchers` | `finance.cash.view` |
| Cash & bank | POST | `/api/v1/expense-vouchers` | `finance.cash.expense_create` |
| Cash & bank | GET | `/api/v1/expense-vouchers/options` | `finance.cash.expense_create` |
| Cash & bank | GET | `/api/v1/expense-vouchers/{expenseVoucher}` | `finance.cash.view` |
| Cash & bank | PUT | `/api/v1/expense-vouchers/{expenseVoucher}` | `finance.cash.expense_create` |
| Cash & bank | POST | `/api/v1/expense-vouchers/{expenseVoucher}/cancel` | `finance.cash.expense_cancel` |
| Cash & bank | POST | `/api/v1/expense-vouchers/{expenseVoucher}/post` | `finance.cash.expense_post` |
| Cash & bank | POST | `/api/v1/expense-vouchers/{expenseVoucher}/reverse` | `finance.cash.expense_reverse` |
| Cash & bank | GET | `/api/v1/fund-transfers` | `finance.cash.view` |
| Cash & bank | POST | `/api/v1/fund-transfers` | `finance.cash.transfer_create` |
| Cash & bank | GET | `/api/v1/fund-transfers/options` | `finance.cash.transfer_create` |
| Cash & bank | GET | `/api/v1/fund-transfers/{fundTransfer}` | `finance.cash.view` |
| Cash & bank | POST | `/api/v1/fund-transfers/{fundTransfer}/cancel` | `finance.cash.transfer_cancel` |
| Cash & bank | POST | `/api/v1/fund-transfers/{fundTransfer}/post` | `finance.cash.transfer_post` |
| Cash & bank | POST | `/api/v1/fund-transfers/{fundTransfer}/reverse` | `finance.cash.transfer_reverse` |
| Finance reports | GET | `/api/v1/reports/ap-aging` | `finance.ap.view` |
| Finance reports | GET | `/api/v1/reports/ar-aging` | `finance.ar.view` |
| Finance reports | GET | `/api/v1/reports/balance-sheet` | `reports.finance` |
| Finance reports | GET | `/api/v1/reports/cash-bank-book` | `finance.cash.view` |
| Finance reports | GET | `/api/v1/reports/cash-bank-position` | `reports.finance` |
| Finance reports | GET | `/api/v1/reports/finance-dashboard` | `reports.management` |
| Finance reports | GET | `/api/v1/reports/grni-reconciliation` | `finance.ap.view` |
| Finance reports | GET | `/api/v1/reports/inventory-gl-reconciliation` | `reports.inventory` |
| Finance reports | GET | `/api/v1/reports/inventory-valuation` | `reports.inventory` |
| Finance reports | GET | `/api/v1/reports/product-profitability` | `reports.management` |
| Finance reports | GET | `/api/v1/reports/profit-loss` | `reports.finance` |
| Finance reports | GET | `/api/v1/reports/unbilled-deliveries` | `finance.ar.view` |

## 14. Not built yet (`TBD`)

| # | Item | Notes |
| --- | --- | --- |
| 1 | **Idempotency keys** on create endpoints | Posting twice is already impossible (409). A *create* retried after a lost response could make a duplicate **draft** — harmless (drafts move nothing) and cancellable, but an `Idempotency-Key` header would prevent it. |
| 2 | **Attachments / photos** | Supplier invoice photos, GRN documents, delivery proof. No module stores files yet. |
| 3 | **Offline mode** | V1 is online-first. No sync queue or conflict resolution. |
| 4 | **User and role administration API** | Web-only for now; `/auth/me` exposes effective permissions. |
| 5 | **API registration / invitations** | Deliberately absent. |
| 6 | **OpenAPI specification** | Not generated yet. This document is maintained by hand from the implemented routes; an OpenAPI file will be added only when it can be generated from the code and kept accurate. |
| 7 | **Push notifications** | e.g. "a GRN is waiting for you to post". |
| 8 | Public opaque IDs | V1 uses the existing numeric IDs. |
| 9 | Manual inventory adjustments | Not an approved workflow in the web ERP either. |
| 10 | Cash/bank account configuration API | Web-only; the voucher and transfer `options` endpoints list the configured accounts. |

## 15. Deliberate differences from the website

- **Status endpoints take the target state** (`{"is_active": true}`) rather than toggling, so a
  retried request cannot flip a record back.
- **A production run is only reachable through its own type's endpoint** — a copra run under
  `/production/dehusking/{id}` is a 404. (The web screens do not check the type; noted as a
  small web follow-up.)
- **`/reports/cash-bank-book` requires `cash_bank_account_id`** instead of defaulting to the first
  account.
- **`/expense-vouchers/options` and `/fund-transfers/options`** have no web twin: they return what
  the web create forms offer, guarded by the create permission.

## 16. Husk production rework — field changes (still v1)

The business rules for husk production, lots and payments changed. The endpoints, routes and
permissions did not; the request and response fields below did. They follow the website exactly,
because both doors use the same FormRequests and actions.

| Area | Change |
| --- | --- |
| Products | Every product returns `tracks_lots` (boolean). `false` = pooled stock: send no `lot_id` for it anywhere — a lot is refused (422), and none is created or returned |
| Dehusking create / update | **`labour_rate` is required** (money string, ≥ 0, up to 6 dp): the labour per coconut, which becomes the raw husk's cost. `lot_id` is required only when the input product tracks lots, and refused otherwise |
| Husk chipping create / update | **No input any more.** `lot_id` and `quantity` are **refused (422)** — raw husk is deducted at delivery. `outputs` is a list of rows `{product_id, quantity}`: any recipe output (7C / 9C / 10C / Cocopeat, KG and L items), each product once, every quantity > 0 at its unit's precision |
| Copra create / update | `lot_id` required only when the input (dehusked coconut) tracks lots — with the seeded data it is pooled, so none is sent |
| Production run responses | `labour_rate`, `labour_cost`; each output's `labour_cost`; `raw_material_pending_backflush` (true for a chipping run that took no husk) |
| Delivery create / update | Each line accepts **`backflush_quantity`** — the raw husks used. **Required (> 0, whole NOS)** for a chipping output, refused for any other product |
| Delivery responses | Each line returns `backflush: {product, quantity, cost}` or `null`; `cost_of_goods_sold` includes the backflushed cost |
| Supplier payment / customer receipt create | **`payment_method` is required**: `CASH`, `CHEQUE` or `BANK_TRANSFER`. A cheque also requires `cheque_number`, `cheque_date` (`YYYY-MM-DD`) and `cheque_bank`. Cash must use a CASH-kind account, cheque/transfer a BANK-kind one (422 otherwise) |
| Payment / receipt responses | `payment_method` (enum, `null` on documents recorded before methods existed) and `cheque: {number, date, bank}` or `null` |

**Why this stays v1.** Most of it is additive. The removal of chipping's input and the newly
required `labour_rate`, `backflush_quantity` and `payment_method` would break an installed
client — but no client exists yet (the Flutter app is not built), and the change is a change of
business rule, not of API shape: the old requests describe operations the factory no longer
performs. Once an app is in use, a change like this would be released as `/api/v2` (§2).
