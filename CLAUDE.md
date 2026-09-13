# Coconut Factory ERP

This repository is an **ERP system for a coconut processing factory**. It manages the
purchase, processing, inventory and sale of coconut products — whole coconuts, dehusked
coconuts, husk, husk chips (7C / 9C / 10C), copra and coconut shell.

The rules in this file are **mandatory**. They exist because this is an accounting and
inventory system: mistakes here corrupt real financial and stock records, and corrupted
records are often impossible to reconstruct.

---

## Technology stack

| Layer | Technology |
| --- | --- |
| Framework | Laravel 13 |
| Language | PHP 8.5 |
| Frontend | Vue 3 + Inertia.js + TypeScript |
| Styling | Tailwind CSS |
| Database | MariaDB |
| Web server | Nginx |
| Mobile (future) | Flutter app consuming a REST API |

Because a Flutter mobile application will later consume this system, business logic must
live where **both** Inertia controllers and REST API controllers can reuse it — i.e. in
action/service classes, never inside a controller or a Vue component.

---

## General development rules

1. **Follow Laravel conventions.** Use `php artisan make:*` generators. Match the structure
   and naming of existing sibling files.
2. **Keep business logic out of controllers** where practical. Controllers validate input,
   call an action, and return a response.
3. **Use service/action classes for important business operations.** Anything that moves
   stock, posts a journal, or changes a business document belongs in an action class under
   `app/Actions/`.
4. **Use database transactions** for inventory, production and accounting postings. A
   partially-written stock movement or journal is a corrupt record.
5. **Use foreign keys and database constraints.** The database is the last line of defence;
   do not rely on application validation alone.
6. **Use `decimal` columns for money and measurable quantities. Never use float or double
   for accounting values.** Floating-point arithmetic loses precision and will silently
   produce unbalanced journals.
7. **Never hard-code business values that should be configurable** — rates, thresholds,
   allocation methods, product codes and UOM codes belong in the database or config.
8. **Every significant business feature must include automated tests.**
9. **Never silently modify posted business transactions.**
10. **Posted transactions must be reversed or cancelled according to business rules**,
    never edited in place.
11. **Never delete audit history.**
12. **Do not invent business rules.** If a requirement is unclear, implement what is
    confirmed, mark the gap `TBD` in `docs/`, and raise it with the user.

---

## Critical inventory rules

**Stock must be derived from inventory transactions.**

**Never directly maintain inventory by simply changing a product stock field.** There must
be no `current_stock` (or equivalent) column on `products`. On-hand quantity is always
calculated from the inventory ledger.

Every stock movement must record at minimum:

- product
- warehouse — **required; multiple warehouses must be supported**
- quantity
- UOM
- movement direction / type
- transaction / source type
- source record ID
- date / time
- user
- cost where relevant
- lot / batch — **nullable, but the architecture must support traceability**

**Negative inventory is NOT allowed by default.** A transaction that would take available
stock below zero must **fail atomically** — the whole business event rolls back, leaving no
partial movements. A future configuration setting may relax this; the default does not.

**Units of measure: exactly two.** `NOS` for countable physical items, `KG` for weight.
There is no separate "Qty" unit — "Qty" is UI wording for a `NOS` count.

---

## Inventory engine rules (Phase 3 — implemented)

The inventory engine exists and is tested. These rules are permanent:

- **All inventory is written through `App\Actions\Inventory\PostInventoryTransaction`.**
  Never insert `inventory_transaction_lines` directly, and never put inventory arithmetic in
  a controller. Purchasing, sales and production must all post through this action so the
  locking, negative-stock and costing guarantees apply uniformly.
- **Posted transactions and lines are immutable.** The models throw on update and delete.
  The only permitted post-write is the `reversed_by_id` audit pointer. Correct mistakes with
  `ReverseInventoryTransaction`, never by editing.
- **Use `App\Support\DecimalMath` (BCMath) for every quantity and cost calculation.**
  PHP floats must never appear in the costing path. Quantities are `decimal(20,3)`, costs
  `decimal(20,6)`.
- **Quantities are always stored positive.** `direction` (`IN`/`OUT`) alone decides the sign.
- **Weighted average cost is company-wide per product, not per warehouse.** This is what
  makes transfers value-neutral. Lock the *product* row when posting.
- **Outgoing stock is never priced by the caller** — it is valued at the weighted average in
  force at posting time. The one exception is an internal system cost used by transfers and
  reversals.
- **Stock is always derived from the ledger.** No stock column on any table, and no cache.
  If a cache is ever added it must be documented as non-authoritative and rebuildable.

---

## Critical accounting rules

- Use **double-entry accounting**.
- **Never directly modify account balances.** Balances are derived from posted journals.
- Every posted journal must satisfy **Total Debit = Total Credit**.
- Financial records must remain **auditable** — every posting traceable to its source
  document and user.

---

## Confirmed business rules

Full detail lives in `docs/BUSINESS_RULES.md`. Summary:

### Whole Coconut — primary UOM `NOS`

Can be purchased from suppliers, sold directly to customers, or sent to dehusking.

### Dehusking

`N Whole Coconut` → `N Dehusked Coconut` + `N Raw Coconut Husk`

Example — input 1,000 Whole Coconut produces 1,000 Dehusked Coconut and 1,000 Raw Coconut
Husk. Whole Coconut decreases by 1,000; Dehusked Coconut increases by 1,000; Raw Husk
increases by 1,000. This is a **fixed 1:1:1 relationship**.

### Dehusked Coconut — primary UOM `NOS`

Can be sold directly, or used for copra production.

### Raw Coconut Husk — primary UOM `NOS`

Enters inventory from two sources: internal dehusking, or direct supplier purchase.
Processed through husk chipping.

### Husk Chipping

Input: Raw Husk in `NOS`. Outputs: `7C`, `9C`, `10C` — all in `KG`.

- **Waste / loss is recorded as production waste and creates NO inventory.** It may be
  promoted to a proper inventory product later if the factory begins selling dust or
  another by-product. **Do not create a `DUST` product.**
- **Actual output weights are entered by the operator.**
- **Do not use a fixed conversion from husks to KG.**

### Copra Production

Input: Dehusked Coconut in `NOS`. Outputs: Copra in `KG`, plus Raw Coconut Shell.

- Raw Shell must support **both quantity (`NOS`) and weight (`KG`)**.
- One coconut yields one shell quantity, unless this rule changes later.
- **Do not use a fixed NOS-to-KG conversion** — shell weights vary.

### Raw Coconut Shell

Sellable **by count (`NOS`) or by weight (`KG`)**. Inventory must preserve both actual
quantity and actual weight where available.

### Costing

- Intended inventory costing method: **Weighted Average Cost**.
- Joint-product cost allocation will later support a configurable method such as market
  value / NRV.
- **Do not implement detailed finance or cost allocation yet.**

---

## Master data rules

These apply to every master table (products, units of measure, suppliers, customers,
warehouses) and to every master added later:

- **Codes are unique and normalised to uppercase.** Use the `App\Concerns\HasUppercaseCode`
  trait on the model, never a controller, so seeders and the REST API get the same rule.
- **No destroy route and no hard delete in the UI.** Master records are deactivated with
  `is_active`, because historic transactions must keep referring to something meaningful.
- **No balances, totals or stock columns on master tables.** A supplier has no payable, a
  customer has no receivable, a product has no stock. Those are always derived from posted
  transactions. `credit_limit` is an agreed *term*, not a balance.
- **Exactly one active warehouse is the default.** Never write `is_default` directly; use
  `App\Actions\Warehouses\SetDefaultWarehouse`, which maintains the invariant inside a
  transaction. See `docs/DATA_MODEL.md` D-2.

---

## Current scope

Implemented: project documentation, **Product Master** (products + units of measure),
**Supplier Master**, **Customer Master**, **Warehouse Master**, and the
**Inventory engine** (ledger, lots, weighted-average costing, transfers, reversals) with
read-only Stock on Hand / Stock Ledger / Lots screens.

**Not yet started — do not begin without instruction:** Purchasing, Production, Sales,
Finance, Costing, role-based permissions, REST API.

The inventory engine has no posting UI by design — business documents will drive it.

Authenticated users may currently access all master data and view inventory. Role-based
permissions come later.

---

## Working agreement

- Read `docs/` before implementing a business feature.
- Run `vendor/bin/pint --dirty` after changing PHP files.
- Run `php artisan test` and `npm run build` before reporting work complete.
- Use forward migrations. **Do not use `migrate:fresh`** without explaining why first.
- The development database is `coconut_erp_dev`. Never drop unrelated tables.

---
<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
