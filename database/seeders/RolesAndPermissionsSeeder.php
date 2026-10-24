<?php

namespace Database\Seeders;

use App\Enums\Permission as P;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Roles, permissions and their starting assignments.
 *
 * **Idempotent, and respectful of later edits.** The permission catalogue is re-synced from
 * `App\Enums\Permission` every run, and ADMIN always receives every permission. The other
 * roles receive their defaults below **only when the role is first created** — once an
 * administrator has tailored a role on the Roles screen, re-running this seeder leaves it
 * alone. A permission added to the catalogue later reaches ADMIN automatically and every
 * other role only when someone grants it.
 *
 * The defaults are starting data, not rules: nothing in the application checks a role name
 * to authorize anything.
 *
 * ## Bootstrap
 *
 * When authorization is introduced for the first time — no user holds any role at all — every
 * existing user is given ADMIN, so introducing access control cannot lock out the people
 * already using the system. The rule never fires again once any assignment exists, so users
 * who register later are never made administrators. On a fresh install with no users, use
 * `php artisan authorization:grant-admin {email}` from the server console instead.
 * See docs/AUTHORIZATION_RULES.md §8.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(PermissionCatalogue $catalogue): void
    {
        DB::transaction(function () use ($catalogue): void {
            $admin = $catalogue->sync();

            foreach ($this->defaultRoles() as $code => $definition) {
                $role = Role::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => $definition['name'], 'description' => $definition['description'], 'is_active' => true],
                );

                if ($role->wasRecentlyCreated) {
                    $codes = array_map(fn (P $permission): string => $permission->value, $definition['permissions']);
                    $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id'));
                }
            }

            $this->bootstrapAdministrators($admin);
        });
    }

    /**
     * First introduction only: every existing user becomes an administrator.
     */
    private function bootstrapAdministrators(Role $admin): void
    {
        if (DB::table('role_user')->exists()) {
            return;
        }

        $admin->users()->syncWithoutDetaching(User::query()->pluck('id'));
    }

    /**
     * The starting permission sets. Editable afterwards on the Roles screen.
     *
     * @return array<string, array{name: string, description: string, permissions: list<P>}>
     */
    private function defaultRoles(): array
    {
        $masterDataView = [P::ProductsView, P::SuppliersView, P::CustomersView, P::WarehousesView];

        $allViews = [
            ...$masterDataView,
            P::InventoryView, P::PurchasingView, P::SalesView, P::ProductionView,
            P::FinanceView, P::ApView, P::ArView, P::CashView,
        ];

        return [
            'MANAGER' => [
                'name' => 'Manager',
                'description' => 'Sees everything, approves orders and may reverse operational postings.',
                'permissions' => [
                    ...$allViews,
                    P::ReportsFinance, P::ReportsInventory, P::ReportsManagement,
                    P::PurchasingOrderApprove, P::PurchasingOrderCancel,
                    P::SalesOrderApprove, P::SalesOrderCancel,
                    P::PurchasingGrnReverse, P::SalesDeliveryReverse, P::ProductionReverse,
                    P::UsersView, P::RolesView,
                    P::PayrollView, P::PayrollEmployeesManage, P::PayrollAttendanceRecord,
                ],
            ],
            'STOREKEEPER' => [
                'name' => 'Storekeeper',
                'description' => 'Receives and dispatches stock: posts goods receipts and deliveries.',
                'permissions' => [
                    ...$masterDataView,
                    P::InventoryView, P::InventoryDamageRecord, P::ReportsInventory,
                    P::PurchasingView, P::PurchasingGrnCreate, P::PurchasingGrnPost, P::PurchasingGrnCancel,
                    P::SalesView, P::SalesDeliveryCreate, P::SalesDeliveryPost, P::SalesDeliveryCancel,
                    P::SalesBagsRecord,
                    P::ProductionView,
                ],
            ],
            'PURCHASING' => [
                'name' => 'Purchasing',
                'description' => 'Maintains suppliers and raises purchase orders. Does not approve or post.',
                'permissions' => [
                    ...$masterDataView,
                    P::SuppliersManage, P::InventoryView,
                    P::PurchasingView, P::PurchasingOrderCreate, P::PurchasingOrderCancel,
                    P::PurchasingGrnCreate, P::PurchasingGrnCancel,
                ],
            ],
            'SALES' => [
                'name' => 'Sales',
                'description' => 'Maintains customers and raises sales orders. Does not approve or post.',
                'permissions' => [
                    ...$masterDataView,
                    P::CustomersManage, P::InventoryView,
                    P::SalesView, P::SalesOrderCreate, P::SalesOrderCancel,
                    P::SalesDeliveryCreate, P::SalesDeliveryCancel,
                ],
            ],
            'PRODUCTION' => [
                'name' => 'Production',
                'description' => 'Records and posts dehusking, husk chipping and copra runs.',
                'permissions' => [
                    P::ProductsView, P::WarehousesView, P::InventoryView,
                    P::ProductionView, P::ProductionCreate, P::ProductionPost, P::ProductionCancel,
                ],
            ],
            'ACCOUNTANT' => [
                'name' => 'Accountant',
                'description' => 'All of finance and payroll, including reversals and setup. Operational screens read-only.',
                'permissions' => [
                    ...$allViews,
                    ...array_values(array_filter(
                        P::cases(),
                        fn (P $permission): bool => str_starts_with($permission->value, 'finance.')
                            || str_starts_with($permission->value, 'payroll.')
                            || str_starts_with($permission->value, 'loans.')
                            || str_starts_with($permission->value, 'reports.'),
                    )),
                ],
            ],
        ];
    }
}
