# Authorization Rules — Coconut Factory ERP

> **Status:** ✅ **IMPLEMENTED** in Phase 15. Role-based access control: permissions, roles,
> multiple roles per user, server-side enforcement on every route, user activation, and
> lockout protection.
>
> **Not built:** warehouse-scoped access, monetary approval limits, field-level security and
> invitations. See §11. The mobile API (Phase 16) uses this same system — see §13.

---

## 1. The server decides — always

Every protected operation is enforced **on the server**, by Laravel's Gate, before the
controller runs. A signed-in user without the permission receives **403** and an in-app
"access denied" page; a guest is still redirected to the login screen.

**Hiding a button in Vue is not security.** The screens receive the user's permission list
(`auth.permissions`) and hide what the user cannot use — purely as a courtesy. Every hidden
action is still refused if called directly, and a test proves it for every route (§10).

## 2. Permissions are capabilities; roles are bundles of them

- A **permission** is one application capability — "post a goods receipt". The catalogue is
  the enum `App\Enums\Permission` (105 entries). It is the only source of permission codes.
- The `permissions` table mirrors the enum so roles can reference it with foreign keys.
  `RolesAndPermissionsSeeder` keeps it in step. **No screen can create, rename or delete a
  permission**: a code that no route checks would be a checkbox that does nothing.
- A **role** is a named bundle of permissions (`roles`, `permission_role`). Roles are data,
  created and edited on the Roles & Permissions screen.
- **Authorization never asks which role someone holds** — only whether their roles grant a
  permission. There is no `if ($user->role === 'ACCOUNTANT')` anywhere, and there must never be.

### Naming

`module.document_action`, for example `purchasing.grn_post`, `finance.ar.invoice_reverse`.
Wherever a module has them, these are **separate** permissions, so one person need not hold
every step of a document's life:

| Action | Suffix | Notes |
| --- | --- | --- |
| View | `.view` | Lists, detail pages, module reports |
| Create / edit draft | `_create` (documents), `.manage` (master data) | Editing a draft is part of creating it |
| Approve | `_approve` | Purchase and sales orders |
| Post | `_post` | Moves stock or writes a journal |
| Reverse | `_reverse` | Audit correction of a posted document |
| Cancel | `_cancel` | Abandons a draft (or an approved order) |

Master data status changes and "set default warehouse" count as `.manage`. One production
permission set covers all three production types, because they share one engine.

## 3. How a route declares its permission

```php
Route::patch('goods-receipts/{goodsReceipt}/post', ...)->can(Permission::PurchasingGrnPost);

Route::resource('products', ProductController::class)
    ->middlewareFor(['index', 'show'], Permission::ProductsView->middleware())
    ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::ProductsManage->middleware());
```

Each enum case is registered as a Gate ability in `AppServiceProvider::configureAuthorization()`,
answered by `User::hasPermission()`. **There is no `Gate::before()` super-user bypass**: ADMIN
passes because it holds every permission, not because it skips the check.

Every signed-in route declares exactly one permission, except a short list open to every active
user: the home dashboard and the user's own profile, password, security and appearance settings.
None of those reads or changes business data. A test fails if any other route lacks a permission.

## 4. Multiple roles and effective permissions

A user may hold several roles (`role_user`). Their **effective permissions** are the union of
the permissions of their **active** roles. An **inactive role grants nothing** to anyone, but
stays assigned so it can be switched back on. An **inactive user holds nothing**, whatever
their roles. Effective permissions are computed once per request and remembered on the model.

## 5. Permissions never override document state

Authorization answers *"may this user perform this kind of action?"*. The business rules
answer *"is this document eligible for it right now?"*. **Both must pass.** Even ADMIN cannot
edit a posted goods receipt, delivery, production run or journal — the actions refuse it
exactly as before. Authorization lives at the route; it was not mixed into inventory costing,
production allocation or accounting arithmetic.

## 6. Users are deactivated, never deleted once they have history

- `users.is_active` (default true). An inactive user **cannot sign in**
  (`Fortify::authenticateUsing`), and a user deactivated mid-session is **signed out on their
  next request** (`EnsureUserIsActive`). Every document keeps naming them.
- There is **no route to delete a user** from administration.
- A user may still delete **their own** account from Settings — but only when it is harmless:
  `DeleteOwnAccount` refuses if the user appears anywhere in business history (found from the
  database's own foreign keys by `UserHistory`, so new tables are covered automatically), or if
  they are the last active administrator.
- **The database backs this up.** The finance tables originally blanked their audit columns
  when a user was deleted (`ON DELETE SET NULL`), which would have silently erased who posted a
  journal. Migration `restrict_deleting_users_with_history` changed them to `RESTRICT`, matching
  purchasing, sales, inventory and production. No data was touched.

## 7. The system can never lose its last administrator

"Administrator" means an **active** user holding the **active** ADMIN role.

- `AdministratorGuard::protect()` makes a change, then compares the administrator count before
  and after, inside one transaction with the ADMIN role row locked. If there was an
  administrator before and none after, the change is refused and rolled back. It applies to
  changing a user's roles, deactivating a user and deleting an account — including an
  administrator editing themselves.
- **The ADMIN role always holds every permission and cannot be deactivated or deleted.** Only
  its name and description can change. The seeder also re-grants it any permission added later.
- The ADMIN code is the only role code the application knows by name, and only for these
  safety rules — never to authorize a business action.

## 8. Bootstrap — introducing access control without locking anyone out

- **First introduction:** when `RolesAndPermissionsSeeder` runs and **no user holds any role at
  all**, every existing user is given ADMIN. On the development database that was the one
  existing account. The rule never fires again once any assignment exists.
- **New users never become administrators automatically.** Self-registration stays open (a
  decision confirmed by the owner), but a registered account holds **no role and no
  permission** until an administrator grants one. It can sign in and see only the dashboard
  and its own settings.
- **Self-registration can be closed** (`config('fortify.self_registration')`, env
  `AUTH_SELF_REGISTRATION`, installer setting `SELF_REGISTRATION=yes|no`; default open). The two
  names differ on purpose: the installer exports its settings, and an exported variable
  overrides `.env`. Closed, the sign-in
  page shows no "Sign up" link and both `/register` routes answer 404 — the routes stay
  registered so the screens build the same. Recommended for a server on the internet.
- **Administrators add accounts** on Administration → Users → Add user (`users.create` /
  `users.store`, `users.manage`, `CreateUser`): name, email, a starting password under the
  sign-up password rules, and the roles it starts with. The person changes the password in their
  own settings; there is no forced change at first sign-in.
- **Fresh install with no users:** register the first account, then run
  `php artisan authorization:grant-admin {email}` on the server. It needs shell access to the
  machine, which is the right level of trust for granting administrator rights.
- **Unattended install:** `php artisan authorization:create-admin {email} --name=…` creates the
  first administrator directly — verified, holding ADMIN — with the password taken from the
  `COCONUT_ADMIN_PASSWORD` environment variable (never a command argument, which other users
  could read in the process list) or typed when run by hand. It applies the registration
  screen's own name, email and password rules. Re-running it only promotes an existing active
  account and leaves its password alone; a deactivated account is refused.
  `deploy/provision.sh` uses it.
- Seeded role defaults are applied **only when a role is first created**. Once an
  administrator edits a role, re-running the seeder leaves it alone. The seeder creates no user
  and no password.

## 9. Starting roles (editable — these are data, not rules)

| Role | Starting permissions |
| --- | --- |
| ADMIN | Every permission, always |
| MANAGER | Every `.view`; all reports; approve/cancel purchase and sales orders; reverse goods receipts, deliveries and production; view users and roles |
| STOREKEEPER | View master data and inventory; inventory reports; create/post/cancel goods receipts and deliveries; view purchasing, sales, production |
| PURCHASING | View master data and inventory; manage suppliers; create/cancel purchase orders; create/cancel goods receipts. No approve, no post |
| SALES | View master data and inventory; manage customers; create/cancel sales orders; create/cancel deliveries. No approve, no post |
| PRODUCTION | View products, warehouses, inventory; create/post/cancel production. Reversal is MANAGER's |
| ACCOUNTANT | Every `finance.*` and `reports.*` permission, including reversals and setup; read-only operational views |

## 10. Tests that hold this in place

`tests/Feature/Authorization/`:

- **RouteAuthorizationTest** — every signed-in route declares exactly one catalogue permission
  or is on the open list; the critical actions are pinned to the intended permission; and
  **every protected route is called twice**: by a user with every permission except its own
  (must be 403) and by a user with only its own (must not be 403 or 500).
- **RolesAndPermissionsTest** — seeding, uniqueness, idempotence, bootstrap, union of roles,
  inactive roles and users, seeded defaults, read-only access, the console command.
- **UserAdministrationTest** — last-administrator protection, deactivation, sign-in refusal,
  audit attribution surviving deactivation, account-deletion rules, role deletion, state rules
  still applying to ADMIN, and the permissions the screens receive.

Business-feature tests use `User::factory()->admin()` so they test the feature rather than the
access rules. A plain factory user holds no role.

## 11. Not built — future work (`TBD`)

| # | Item |
| --- | --- |
| 1 | **Warehouse-scoped access** — e.g. a storekeeper limited to MAIN. Permissions apply company-wide today. |
| 2 | **Monetary approval limits** — e.g. a manager approving up to Rs. 500,000. |
| 3 | **Field-level security** — e.g. seeing quantities but not cost or selling price. |
| 4 | **Invitations** (an emailed link instead of a starting password). Admin-created accounts and closing sign-up were added in 1.4.0. |
| 5 | ~~API authentication~~ — **done in Phase 16**, see §13. |
| 6 | **An audit log of access changes** (who granted which role, when). Not requested. |

## 12. The authorization boundary for future callers

Domain actions (`app/Actions/**`) **assume the caller has already been authorized**. They do
not check permissions themselves, so posting logic, costing and accounting stay free of access
concerns. Every entry point — web routes today, API routes and queued jobs later — must perform
the permission check before calling an action. A console command or job acting on its own
authority must say so in its docblock.

## 13. The mobile API uses exactly this system

- API routes (`/api/v1`) declare the **same** `Permission` abilities as their web twins, one per
  route; `ApiRouteAuthorizationTest` proves each API route and its web twin agree.
- A Sanctum token carries a single `mobile-api` ability that proves the token was issued for the
  API. **The 105 permissions are never copied into token abilities** — what the user may do is
  read from their roles on every request, so changing a role takes effect on the phone at once.
- No mobile-only roles or permissions exist, and none may be added.
- Deactivating a user or changing their password deletes all their API tokens; the `api.active`
  middleware refuses any token whose user is inactive.
- User and role administration remain **web-only**. `GET /api/v1/auth/me` returns the user's
  effective permissions for the app's UI; the server still enforces every one.


## 14. Husk production rework — no new permissions

The rework added no permission, role or route. Each new field rides on the permission its
document already had:

| What | Covered by |
| --- | --- |
| Dehusking labour rate; flexible chipping outputs | `production.create` (draft), `production.post` (posting) |
| "Raw husks used" on a delivery, and its backflush at posting | `sales.delivery_create`, `sales.delivery_post`; reversal `sales.delivery_reverse` |
| Payment method and cheque details | `finance.ap.payment_create` / `_post`, `finance.ar.receipt_create` / `_post` |
| "Record payment" button on an invoice | shown only with `finance.ap.payment_create` / `finance.ar.receipt_create`; the payment screen it opens enforces the same |
| `inventory:consolidate-pooled-lots` | **server console only** — no route, so it needs shell access, like `authorization:grant-admin`. It records the move under the named active user |
