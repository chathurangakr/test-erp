<?php

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\ExpenseVoucher;
use App\Models\FiscalPeriod;
use App\Models\FundTransfer;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SalaryAdvance;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every route, audited.
 *
 * The audit is structural and exhaustive: every signed-in route either declares exactly one
 * permission or is on a short, explained list of routes every signed-in user may use. Then
 * every protected route is actually called twice — once by a user holding every permission
 * EXCEPT the one it declares (must be 403), once by a user holding ONLY that permission (must
 * not be 403). Together they prove the server, not the screen, decides.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Signed-in routes deliberately open to every active user: the landing page and the
     * user's own account settings. Nothing here reads or changes business data.
     */
    private const OPEN_TO_ANY_SIGNED_IN_USER = [
        'dashboard',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'security.edit',
        'user-password.update',
        'appearance.edit',
        // Simple mode's home draws only the tiles the user's roles allow, and the language
        // switch only sets a cookie. Every job route behind them requires a permission.
        'kiosk.home',
        'kiosk.language',
        // The company logo printed on letterheads — not business data.
        'company.logo',
    ];

    /**
     * The critical actions, pinned to the permission each must require. The sweep below
     * proves each route enforces whatever it declares; this proves it declares the right one.
     */
    private const EXPECTED = [
        'products.index' => Permission::ProductsView,
        'products.store' => Permission::ProductsManage,
        'products.update' => Permission::ProductsManage,
        'products.status.update' => Permission::ProductsManage,
        'suppliers.update' => Permission::SuppliersManage,
        'suppliers.status.update' => Permission::SuppliersManage,
        'customers.update' => Permission::CustomersManage,
        'customers.status.update' => Permission::CustomersManage,
        'warehouses.update' => Permission::WarehousesManage,
        'warehouses.default.update' => Permission::WarehousesManage,
        'inventory.stock.index' => Permission::InventoryView,
        'inventory.ledger.index' => Permission::InventoryView,
        'inventory.lots.index' => Permission::InventoryView,
        'purchase-orders.store' => Permission::PurchasingOrderCreate,
        'purchase-orders.approve' => Permission::PurchasingOrderApprove,
        'purchase-orders.cancel' => Permission::PurchasingOrderCancel,
        'goods-receipts.post' => Permission::PurchasingGrnPost,
        'goods-receipts.reverse' => Permission::PurchasingGrnReverse,
        'goods-receipts.cancel' => Permission::PurchasingGrnCancel,
        'sales-orders.store' => Permission::SalesOrderCreate,
        'sales-orders.approve' => Permission::SalesOrderApprove,
        'deliveries.post' => Permission::SalesDeliveryPost,
        'deliveries.reverse' => Permission::SalesDeliveryReverse,
        'dehusking.post' => Permission::ProductionPost,
        'husk-chipping.post' => Permission::ProductionPost,
        'copra.post' => Permission::ProductionPost,
        'dehusking.reverse' => Permission::ProductionReverse,
        'husk-chipping.reverse' => Permission::ProductionReverse,
        'copra.reverse' => Permission::ProductionReverse,
        'accounts.store' => Permission::FinanceAccountsManage,
        'accounts.update' => Permission::FinanceAccountsManage,
        'journals.store' => Permission::FinanceJournalCreate,
        'journals.post' => Permission::FinanceJournalPost,
        'journals.reverse' => Permission::FinanceJournalReverse,
        'fiscal-periods.store' => Permission::FinancePeriodManage,
        'fiscal-periods.close' => Permission::FinancePeriodManage,
        'accounting-setup.settings' => Permission::FinanceSetupManage,
        'supplier-invoices.store' => Permission::ApInvoiceCreate,
        'supplier-invoices.post' => Permission::ApInvoicePost,
        'supplier-invoices.reverse' => Permission::ApInvoiceReverse,
        'supplier-payments.post' => Permission::ApPaymentPost,
        'sales-invoices.store' => Permission::ArInvoiceCreate,
        'sales-invoices.post' => Permission::ArInvoicePost,
        'sales-invoices.reverse' => Permission::ArInvoiceReverse,
        'customer-receipts.post' => Permission::ArReceiptPost,
        'expense-vouchers.post' => Permission::CashExpensePost,
        'expense-vouchers.reverse' => Permission::CashExpenseReverse,
        'fund-transfers.post' => Permission::CashTransferPost,
        'fund-transfers.reverse' => Permission::CashTransferReverse,
        'reports.profit-loss' => Permission::ReportsFinance,
        'reports.balance-sheet' => Permission::ReportsFinance,
        'finance-dashboard.index' => Permission::ReportsManagement,
        'reports.product-profitability' => Permission::ReportsManagement,
        'reports.inventory-valuation' => Permission::ReportsInventory,
        'employees.store' => Permission::PayrollEmployeesManage,
        'employees.update' => Permission::PayrollEmployeesManage,
        'attendance.update' => Permission::PayrollAttendanceRecord,
        'salary-advances.store' => Permission::PayrollAdvancePay,
        'salary-advances.reverse' => Permission::PayrollAdvanceReverse,
        'payrolls.store' => Permission::PayrollSalaryPay,
        'payrolls.reverse' => Permission::PayrollSalaryReverse,
        'users.index' => Permission::UsersView,
        'users.update' => Permission::UsersManage,
        'roles.index' => Permission::RolesView,
        'roles.update' => Permission::RolesManage,
        'roles.destroy' => Permission::RolesManage,
    ];

    /**
     * Application routes that require sign-in (Fortify's own login routes excluded).
     *
     * @return Collection<int, Route>
     */
    private function signedInRoutes(): Collection
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('auth', $route->gatherMiddleware(), true))
            ->reject(fn (Route $route): bool => str_starts_with((string) $route->getActionName(), 'Laravel\\'))
            ->values();
    }

    /**
     * The permission codes a route declares through `can:` middleware.
     *
     * @return list<string>
     */
    private function declaredPermissions(Route $route): array
    {
        return collect($route->gatherMiddleware())
            ->filter(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'can:'))
            ->map(fn (string $middleware): string => explode(',', substr($middleware, 4))[0])
            ->values()
            ->all();
    }

    #[Test]
    public function every_signed_in_route_makes_an_explicit_authorization_decision(): void
    {
        $undecided = [];
        $ambiguous = [];

        foreach ($this->signedInRoutes() as $route) {
            $name = $route->getName() ?? $route->uri();
            $declared = $this->declaredPermissions($route);

            if (in_array($name, self::OPEN_TO_ANY_SIGNED_IN_USER, true) || $route->uri() === 'settings') {
                $this->assertSame([], $declared, "{$name} is on the open list but also declares a permission.");

                continue;
            }

            if ($declared === []) {
                $undecided[] = $name;
            } elseif (count($declared) > 1) {
                $ambiguous[] = $name;
            }

            foreach ($declared as $code) {
                $this->assertNotNull(Permission::tryFrom($code), "{$name} declares '{$code}', which is not in the catalogue.");
            }
        }

        $this->assertSame([], $undecided, 'Signed-in routes with no permission: '.implode(', ', $undecided));
        $this->assertSame([], $ambiguous, 'Routes declaring more than one permission: '.implode(', ', $ambiguous));
    }

    #[Test]
    public function the_critical_actions_require_the_intended_permission(): void
    {
        $routes = app('router')->getRoutes();

        foreach (self::EXPECTED as $name => $permission) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, "Route {$name} does not exist.");
            $this->assertSame([$permission->value], $this->declaredPermissions($route), "{$name} must require {$permission->value}.");
        }
    }

    #[Test]
    public function every_protected_route_refuses_without_its_permission_and_admits_with_it(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->signedInRoutes() as $route) {
            $declared = $this->declaredPermissions($route);

            if ($declared === []) {
                continue;
            }

            $checked++;

            $code = $declared[0];
            $method = collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();
            $url = route($route->getName(), $this->parametersFor($route));

            // Every permission in the catalogue except the one this route needs.
            $others = array_values(array_filter(Permission::cases(), fn (Permission $p): bool => $p->value !== $code));
            $almostEverything = User::factory()->withPermissions(...$others)->create();
            $onlyThis = User::factory()->withPermissions(Permission::from($code))->create();

            $refused = $this->actingAs($almostEverything)->call($method, $url)->getStatusCode();
            $admitted = $this->actingAs($onlyThis)->call($method, $url)->getStatusCode();

            if ($refused !== 403) {
                $failures[] = "{$method} {$route->getName()} without {$code}: expected 403, got {$refused}";
            }

            if ($admitted === 403 || $admitted >= 500) {
                $failures[] = "{$method} {$route->getName()} with only {$code}: got {$admitted}";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));

        // Guards against the sweep silently checking nothing.
        $this->assertGreaterThanOrEqual(190, $checked, "Only {$checked} protected routes were exercised.");
    }

    #[Test]
    public function a_user_with_no_role_is_refused_everywhere_but_the_open_routes(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get(route('dashboard'))->assertOk();
        $this->actingAs($nobody)->get(route('profile.edit'))->assertOk();

        $this->actingAs($nobody)->get(route('products.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('journals.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('users.index'))->assertForbidden();
    }

    #[Test]
    public function a_refusal_renders_the_error_page_rather_than_redirecting(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('products.index'))
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('ErrorPage')->where('status', 403));
    }

    #[Test]
    public function a_guest_is_still_sent_to_the_login_page(): void
    {
        $this->get(route('products.index'))->assertRedirect(route('login'));
        $this->patch(route('goods-receipts.post', GoodsReceipt::factory()->create()))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * One real record for each route parameter, so route-model binding resolves and the
     * authorization middleware is what answers.
     *
     * @return array<string, Model>
     */
    private function parametersFor(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[$name] = match ($name) {
                'order' => str_starts_with($route->uri(), 'purchase-orders')
                    ? PurchaseOrder::factory()->create()
                    : SalesOrder::factory()->create(),
                'goodsReceipt' => GoodsReceipt::factory()->create(),
                'delivery' => Delivery::factory()->create(),
                'dehusking' => ProductionOrder::factory()->create(),
                'huskChipping' => ProductionOrder::factory()->huskChipping()->create(),
                'copra' => ProductionOrder::factory()->copra()->create(),
                'journal' => JournalEntry::factory()->create(),
                'account' => Account::factory()->create(),
                'fiscalPeriod' => FiscalPeriod::factory()->create(),
                'cashBankAccount' => CashBankAccount::factory()->create(),
                'supplier_invoice' => SupplierInvoice::factory()->create(),
                'supplier_payment' => SupplierPayment::factory()->create(),
                'sales_invoice' => SalesInvoice::factory()->create(),
                'customer_receipt' => CustomerReceipt::factory()->create(),
                'expenseVoucher' => ExpenseVoucher::factory()->create(),
                'fundTransfer' => FundTransfer::factory()->create(),
                'product' => Product::factory()->create(),
                'supplier' => Supplier::factory()->create(),
                'customer' => Customer::factory()->create(),
                'warehouse' => Warehouse::factory()->create(),
                'employee' => Employee::factory()->create(),
                'salaryAdvance' => SalaryAdvance::factory()->create(),
                'payroll' => PayrollRun::factory()->create(),
                'user' => User::factory()->create(),
                'role' => Role::factory()->create(),
                default => $this->fail("No test record for route parameter '{$name}' on {$route->getName()}."),
            };
        }

        return $parameters;
    }
}
