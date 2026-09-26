<?php

namespace Tests\Feature\Authorization;

use App\Enums\Permission as P;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The permission catalogue, the seeded roles, and how permissions combine.
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function role(string $code): Role
    {
        return Role::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function codesOf(string $role): array
    {
        return $this->role($role)->permissions()->orderBy('code')->pluck('code')->all();
    }

    // ------------------------------------------------------------- seeding

    #[Test]
    public function the_seven_roles_are_seeded(): void
    {
        $this->seedRoles();

        $this->assertEqualsCanonicalizing(
            ['ADMIN', 'MANAGER', 'STOREKEEPER', 'PURCHASING', 'SALES', 'PRODUCTION', 'ACCOUNTANT'],
            Role::query()->pluck('code')->all(),
        );
    }

    #[Test]
    public function every_catalogue_permission_is_seeded_once_and_nothing_else(): void
    {
        $this->seedRoles();

        $this->assertEqualsCanonicalizing(
            array_map(fn (P $permission): string => $permission->value, P::cases()),
            Permission::query()->pluck('code')->all(),
        );
    }

    #[Test]
    public function role_and_permission_codes_are_unique_in_the_database(): void
    {
        $this->seedRoles();

        $this->expectException(QueryException::class);
        DB::table('roles')->insert(['code' => 'ADMIN', 'name' => 'Duplicate', 'is_active' => true]);
    }

    #[Test]
    public function a_permission_code_cannot_be_duplicated(): void
    {
        $this->seedRoles();

        $this->expectException(QueryException::class);
        DB::table('permissions')->insert(['code' => 'products.view', 'name' => 'Duplicate', 'module' => 'Master Data']);
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $this->seedRoles();
        $counts = [Role::count(), Permission::count(), DB::table('permission_role')->count()];

        $this->seedRoles();

        $this->assertSame($counts, [Role::count(), Permission::count(), DB::table('permission_role')->count()]);
    }

    #[Test]
    public function rerunning_the_seeder_keeps_an_administrators_edits_to_a_role(): void
    {
        $this->seedRoles();

        $sales = $this->role('SALES');
        $sales->permissions()->sync(Permission::where('code', P::SalesView->value)->pluck('id'));

        $this->seedRoles();

        $this->assertSame([P::SalesView->value], $this->codesOf('SALES'));
    }

    #[Test]
    public function the_administrator_role_holds_every_permission_even_after_tampering(): void
    {
        $this->seedRoles();
        $this->role('ADMIN')->permissions()->detach();

        $this->seedRoles();

        $this->assertCount(count(P::cases()), $this->codesOf('ADMIN'));
    }

    // ------------------------------------------------------------- bootstrap

    #[Test]
    public function introducing_roles_makes_every_existing_user_an_administrator(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->seedRoles();

        $this->assertTrue($first->isAdministrator());
        $this->assertTrue($second->isAdministrator());
    }

    #[Test]
    public function the_bootstrap_never_fires_again_so_later_users_get_no_access(): void
    {
        User::factory()->create();
        $this->seedRoles();

        $newcomer = User::factory()->create();
        $this->seedRoles();

        $this->assertFalse($newcomer->fresh()->isAdministrator());
        $this->assertSame([], $newcomer->fresh()->permissionCodes());
    }

    #[Test]
    public function a_fresh_install_grants_the_first_administrator_from_the_console(): void
    {
        $this->seedRoles();
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('authorization:grant-admin', ['email' => 'owner@example.com'])->assertSuccessful();
        $this->artisan('authorization:grant-admin', ['email' => 'nobody@example.com'])->assertFailed();

        $this->assertTrue($owner->fresh()->isAdministrator());
    }

    #[Test]
    public function a_self_registered_user_holds_no_role_and_no_permission(): void
    {
        $this->seedRoles();

        $this->post(route('register.store'), [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'walkin@example.com')->firstOrFail();

        $this->assertCount(0, $user->roles);
        $this->assertSame([], $user->permissionCodes());
        $this->actingAs($user)->get(route('products.index'))->assertForbidden();
    }

    // ------------------------------------------------------------- combining roles

    #[Test]
    public function a_user_may_hold_several_roles_and_gets_their_union(): void
    {
        $this->seedRoles();
        $user = User::factory()->withRoles('STOREKEEPER', 'PURCHASING')->create();

        $this->assertCount(2, $user->roles);
        $this->assertTrue($user->hasPermission(P::PurchasingGrnPost));    // from STOREKEEPER
        $this->assertTrue($user->hasPermission(P::SuppliersManage));      // from PURCHASING
        $this->assertFalse($user->hasPermission(P::PurchasingOrderApprove)); // from neither
    }

    #[Test]
    public function an_inactive_role_grants_nothing(): void
    {
        $this->seedRoles();
        $user = User::factory()->withRoles('ACCOUNTANT')->create();

        $this->role('ACCOUNTANT')->update(['is_active' => false]);

        $this->assertSame([], $user->fresh()->permissionCodes());
    }

    #[Test]
    public function an_inactive_user_holds_no_permission_whatever_their_roles(): void
    {
        $this->seedRoles();
        $user = User::factory()->withRoles('ADMIN')->create();

        $user->forceFill(['is_active' => false])->save();

        $this->assertSame([], $user->fresh()->permissionCodes());
        $this->assertFalse(Gate::forUser($user->fresh())->allows(P::ProductsView->value));
    }

    // ------------------------------------------------------------- the seeded defaults

    #[Test]
    public function admin_has_every_permission(): void
    {
        $this->seedRoles();
        $admin = User::factory()->withRoles('ADMIN')->create();

        foreach (P::cases() as $permission) {
            $this->assertTrue($admin->hasPermission($permission), "ADMIN lacks {$permission->value}");
        }
    }

    #[Test]
    public function purchasing_does_not_automatically_get_finance(): void
    {
        $this->seedRoles();

        $finance = array_filter($this->codesOf('PURCHASING'), fn (string $code): bool => str_starts_with($code, 'finance.'));

        $this->assertSame([], $finance);
        $this->assertNotContains(P::PurchasingOrderApprove->value, $this->codesOf('PURCHASING'));
        $this->assertNotContains(P::SalesDeliveryPost->value, $this->codesOf('PURCHASING'));
    }

    #[Test]
    public function the_accountant_does_not_automatically_post_production(): void
    {
        $this->seedRoles();
        $codes = $this->codesOf('ACCOUNTANT');

        $this->assertContains(P::ProductionView->value, $codes);
        $this->assertNotContains(P::ProductionPost->value, $codes);
        $this->assertNotContains(P::ProductsManage->value, $codes);
        $this->assertContains(P::FinanceJournalReverse->value, $codes);
    }

    #[Test]
    public function the_manager_reads_everything_and_approves_but_posts_no_journals(): void
    {
        $this->seedRoles();
        $codes = $this->codesOf('MANAGER');

        $this->assertContains(P::FinanceView->value, $codes);
        $this->assertContains(P::ReportsFinance->value, $codes);
        $this->assertContains(P::PurchasingOrderApprove->value, $codes);
        $this->assertNotContains(P::FinanceJournalPost->value, $codes);
        $this->assertNotContains(P::ProductsManage->value, $codes);
    }

    #[Test]
    public function production_and_sales_roles_stay_in_their_lanes(): void
    {
        $this->seedRoles();

        $this->assertNotContains(P::FinanceView->value, $this->codesOf('PRODUCTION'));
        $this->assertNotContains(P::ApView->value, $this->codesOf('PRODUCTION'));
        $this->assertNotContains(P::PurchasingGrnPost->value, $this->codesOf('SALES'));
        $this->assertNotContains(P::FinanceJournalCreate->value, $this->codesOf('SALES'));
    }

    #[Test]
    public function read_only_access_comes_naturally_from_holding_only_view_permissions(): void
    {
        $viewer = User::factory()->withPermissions(P::PurchasingView)->create();
        $order = PurchaseOrder::factory()->create();

        $this->actingAs($viewer)->get(route('purchase-orders.index'))->assertOk();
        $this->actingAs($viewer)->get(route('purchase-orders.show', $order))->assertOk();
        $this->actingAs($viewer)->get(route('purchase-orders.create'))->assertForbidden();
        $this->actingAs($viewer)->patch(route('purchase-orders.approve', $order))->assertForbidden();
    }
}
