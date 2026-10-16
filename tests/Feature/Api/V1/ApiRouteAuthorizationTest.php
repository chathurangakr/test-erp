<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Delivery;
use App\Models\ExpenseVoucher;
use App\Models\FiscalPeriod;
use App\Models\FundTransfer;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
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
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every /api/v1 route, audited — the API twin of the web RouteAuthorizationTest.
 *
 * A route added without the full protection stack, or without a permission, fails here.
 */
class ApiRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** The only anonymous endpoint. */
    private const PUBLIC = ['api.v1.auth.login'];

    /** Signed-in endpoints about the user's own session — or filtered by their permissions inside — open to any active user. */
    private const OWN_SESSION = [
        'api.v1.auth.logout',
        'api.v1.auth.logout-all',
        'api.v1.auth.me',
        'api.v1.meta',
        'api.v1.dashboard',
        'api.v1.kiosk.options',
    ];

    /** The protection every non-public route must carry. */
    private const REQUIRED_MIDDLEWARE = ['api.https', 'auth:sanctum', 'abilities:mobile-api', 'api.active', 'api.locale', 'throttle:api'];

    /**
     * @return Collection<int, Route>
     */
    private function apiRoutes(): Collection
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
            ->values();
    }

    /**
     * @return list<string>
     */
    private function declaredPermissions(Route $route): array
    {
        return collect($route->gatherMiddleware())
            ->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'can:'))
            ->map(fn (string $m): string => explode(',', substr($m, 4))[0])
            ->values()
            ->all();
    }

    #[Test]
    public function every_api_route_is_versioned(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $this->assertStringStartsWith('api/v1/', $route->uri(), "Unversioned API route: {$route->uri()}");
        }
    }

    #[Test]
    public function every_api_route_makes_an_explicit_security_decision(): void
    {
        $failures = [];

        foreach ($this->apiRoutes() as $route) {
            $name = (string) $route->getName();
            $middleware = $route->gatherMiddleware();
            $declared = $this->declaredPermissions($route);

            if (in_array($name, self::PUBLIC, true)) {
                if (in_array('auth:sanctum', $middleware, true) || $declared !== []) {
                    $failures[] = "{$name} is public but carries auth or a permission";
                }

                continue;
            }

            foreach (self::REQUIRED_MIDDLEWARE as $required) {
                if (! in_array($required, $middleware, true)) {
                    $failures[] = "{$name} lacks {$required}";
                }
            }

            if (in_array($name, self::OWN_SESSION, true)) {
                if ($declared !== []) {
                    $failures[] = "{$name} is an own-session route but declares a permission";
                }

                continue;
            }

            if (count($declared) !== 1) {
                $failures[] = "{$name} must declare exactly one permission, declares ".count($declared);
            }

            foreach ($declared as $code) {
                if (Permission::tryFrom($code) === null) {
                    $failures[] = "{$name} declares unknown permission {$code}";
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    #[Test]
    public function each_api_route_requires_the_same_permission_as_its_web_twin(): void
    {
        $routes = app('router')->getRoutes();
        $compared = 0;

        foreach ($this->apiRoutes() as $route) {
            $webName = substr((string) $route->getName(), strlen('api.v1.'));
            $web = $routes->getByName($webName);

            if ($web === null) {
                continue;
            }

            $compared++;
            $this->assertSame(
                $this->declaredPermissions($web),
                $this->declaredPermissions($route),
                "API route {$route->getName()} and web route {$webName} disagree about the permission.",
            );
        }

        $this->assertGreaterThan(10, $compared, 'Too few API routes matched a web twin to trust this check.');
    }

    #[Test]
    public function every_protected_api_route_refuses_without_its_permission_and_admits_with_it(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->apiRoutes() as $route) {
            $declared = $this->declaredPermissions($route);

            if ($declared === []) {
                continue;
            }

            $checked++;
            $code = $declared[0];
            $method = collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();
            $url = route($route->getName(), $this->parametersFor($route));

            $others = array_values(array_filter(Permission::cases(), fn (Permission $p): bool => $p->value !== $code));

            Sanctum::actingAs(User::factory()->withPermissions(...$others)->create(), ['mobile-api']);
            $refused = $this->json($method, $url);

            Sanctum::actingAs(User::factory()->withPermissions(Permission::from($code))->create(), ['mobile-api']);
            $admitted = $this->json($method, $url);

            if ($refused->getStatusCode() !== 403 || ! $refused->headers->contains('Content-Type', 'application/json')) {
                $failures[] = "{$method} {$route->getName()} without {$code}: expected JSON 403, got {$refused->getStatusCode()}";
            }

            if ($admitted->getStatusCode() === 403 || $admitted->getStatusCode() >= 500) {
                $failures[] = "{$method} {$route->getName()} with only {$code}: got {$admitted->getStatusCode()}";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertGreaterThan(0, $checked);
    }

    #[Test]
    public function an_anonymous_call_to_any_protected_route_gets_a_json_401(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->getName(), self::PUBLIC, true)) {
                continue;
            }

            $method = collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();
            $response = $this->json($method, route($route->getName(), $this->parametersFor($route)));

            $this->assertSame(401, $response->getStatusCode(), "{$route->getName()} answered {$response->getStatusCode()} to an anonymous call.");
            $response->assertJsonStructure(['message']);
        }
    }

    /**
     * @return array<string, Model>
     */
    private function parametersFor(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[$name] = match ($name) {
                'order' => str_contains($route->uri(), 'purchase-orders')
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
                default => $this->fail("No test record for API route parameter '{$name}' on {$route->getName()}."),
            };
        }

        return $parameters;
    }
}
