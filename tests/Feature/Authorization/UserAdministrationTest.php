<?php

namespace Tests\Feature\Authorization;

use App\Enums\GoodsReceiptStatus;
use App\Enums\Permission as P;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Managing users and roles, and the safety rules around them.
 */
class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function role(string $code): Role
    {
        return Role::query()->where('code', $code)->firstOrFail();
    }

    // ------------------------------------------------------------- the last administrator

    #[Test]
    public function the_last_administrator_cannot_remove_their_own_admin_role(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), ['is_active' => true, 'role_ids' => [$this->role('MANAGER')->id]])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($admin->fresh()->isAdministrator());
        $this->assertFalse($admin->fresh()->roles->contains('code', 'MANAGER'), 'A refused change must save nothing.');
    }

    #[Test]
    public function the_last_administrator_cannot_be_deactivated(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), ['is_active' => false, 'role_ids' => [$this->role('ADMIN')->id]])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    #[Test]
    public function a_second_administrator_allows_the_first_to_step_down(): void
    {
        $first = User::factory()->withRoles('ADMIN')->create();
        User::factory()->withRoles('ADMIN')->create();

        $this->actingAs($first)
            ->put(route('users.update', $first), ['is_active' => true, 'role_ids' => [$this->role('ACCOUNTANT')->id]])
            ->assertSessionHasNoErrors();

        $this->assertFalse($first->fresh()->isAdministrator());
        $this->assertTrue($first->fresh()->hasPermission(P::FinanceJournalPost));
    }

    #[Test]
    public function the_administrator_role_cannot_be_deactivated_or_reduced(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        $adminRole = $this->role('ADMIN');

        $this->actingAs($admin)
            ->put(route('roles.update', $adminRole), ['name' => 'Administrator', 'is_active' => false, 'permission_ids' => []])
            ->assertSessionHasErrors('is_active');

        $this->actingAs($admin)
            ->put(route('roles.update', $adminRole), ['name' => 'Owner', 'is_active' => true, 'permission_ids' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame('Owner', $adminRole->fresh()->name);
        $this->assertSame(count(P::cases()), $adminRole->fresh()->permissions()->count());
    }

    #[Test]
    public function the_last_administrator_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();

        $this->actingAs($admin)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($admin->fresh());
        $this->assertAuthenticatedAs($admin);
    }

    // ------------------------------------------------------------- deactivation and history

    #[Test]
    public function an_inactive_user_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->withRoles('ADMIN')->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_user_deactivated_mid_session_is_signed_out_on_their_next_request(): void
    {
        User::factory()->withRoles('ADMIN')->create();
        $user = User::factory()->withRoles('MANAGER')->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get(route('products.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_users_name_stays_on_the_documents_they_recorded(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        $clerk = User::factory()->withRoles('PURCHASING')->create(['name' => 'Nimal Clerk']);
        $order = PurchaseOrder::factory()->create(['created_by' => $clerk->id]);

        $this->actingAs($admin)
            ->put(route('users.update', $clerk), ['is_active' => false, 'role_ids' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame($clerk->id, $order->fresh()->created_by);
        $this->assertSame('Nimal Clerk', $order->fresh()->createdBy->name);

        $this->actingAs($admin)->get(route('users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('users.data', fn ($users) => collect($users)
                ->contains(fn ($row) => $row['name'] === 'Nimal Clerk' && $row['is_active'] === false)));
    }

    #[Test]
    public function an_account_with_business_history_cannot_delete_itself(): void
    {
        User::factory()->withRoles('ADMIN')->create();
        $clerk = User::factory()->withRoles('PURCHASING')->create();
        PurchaseOrder::factory()->create(['created_by' => $clerk->id]);

        $this->actingAs($clerk)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($clerk->fresh());
    }

    #[Test]
    public function an_account_with_no_history_may_still_delete_itself(): void
    {
        User::factory()->withRoles('ADMIN')->create();
        $walkIn = User::factory()->create();

        $this->actingAs($walkIn)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertNull($walkIn->fresh());
    }

    #[Test]
    public function the_database_itself_refuses_to_delete_a_user_named_on_a_journal(): void
    {
        $user = User::factory()->create();
        JournalEntry::factory()->create(['created_by' => $user->id]);

        $this->expectException(QueryException::class);
        $user->delete();
    }

    #[Test]
    public function there_is_no_route_that_deletes_a_user_or_creates_a_permission(): void
    {
        $router = app('router');

        $this->assertFalse($router->has('users.destroy'));
        $this->assertFalse($router->has('permissions.store'));
    }

    // ------------------------------------------------------------- adding a user

    #[Test]
    public function an_administrator_adds_a_user_who_can_sign_in_with_the_roles_given(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();

        $this->actingAs($admin)->get(route('users.create'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('administration/users/Create')->has('roles'));

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Kamal Perera',
            'email' => 'kamal@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'start_in_simple_mode' => true,
            'role_ids' => [$this->role('STOREKEEPER')->id],
        ])->assertSessionHasNoErrors();

        $kamal = User::query()->where('email', 'kamal@example.com')->firstOrFail();
        $this->assertTrue($kamal->is_active);
        $this->assertTrue($kamal->start_in_simple_mode);
        $this->assertSame(['STOREKEEPER'], $kamal->roles->pluck('code')->all());
        $this->assertTrue($kamal->hasPermission(P::PurchasingGrnPost));

        auth()->logout();
        $this->post(route('login.store'), ['email' => 'kamal@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($kamal);
    }

    #[Test]
    public function adding_a_user_needs_the_manage_permission(): void
    {
        $viewer = User::factory()->withPermissions(P::UsersView)->create();

        $this->actingAs($viewer)->get(route('users.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('users.store'), [
            'name' => 'Someone', 'email' => 'someone@example.com',
            'password' => 'password', 'password_confirmation' => 'password', 'role_ids' => [],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }

    #[Test]
    public function an_added_user_needs_an_unused_email_and_a_confirmed_password(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Someone', 'email' => 'taken@example.com',
            'password' => 'password', 'password_confirmation' => 'different', 'role_ids' => [],
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertSame(1, User::query()->count());
    }

    // ------------------------------------------------------------- roles

    #[Test]
    public function a_role_assigned_to_users_cannot_be_deleted(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        User::factory()->withRoles('SALES')->create();

        $this->actingAs($admin)->delete(route('roles.destroy', $this->role('SALES')))->assertSessionHasErrors('role');

        $this->assertNotNull(Role::where('code', 'SALES')->first());
    }

    #[Test]
    public function an_unassigned_role_can_be_deleted_but_admin_never(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        $spare = Role::factory()->create();

        $this->actingAs($admin)->delete(route('roles.destroy', $spare))->assertSessionHasNoErrors();
        $this->assertNull($spare->fresh());

        $this->actingAs($admin)->delete(route('roles.destroy', $this->role('ADMIN')))->assertSessionHasErrors('role');
        $this->assertNotNull($this->role('ADMIN'));
    }

    #[Test]
    public function a_role_is_created_with_existing_permissions_only(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        $view = Permission::where('code', P::InventoryView->value)->value('id');

        $this->actingAs($admin)->post(route('roles.store'), [
            'code' => 'quality_control',
            'name' => 'Quality control',
            'is_active' => true,
            'permission_ids' => [$view],
        ])->assertSessionHasNoErrors();

        $role = Role::where('code', 'QUALITY_CONTROL')->firstOrFail();
        $this->assertSame([P::InventoryView->value], $role->permissions()->pluck('code')->all());

        $this->actingAs($admin)->post(route('roles.store'), [
            'code' => 'BOGUS',
            'name' => 'Bogus',
            'is_active' => true,
            'permission_ids' => [999999],
        ])->assertSessionHasErrors('permission_ids.0');
    }

    #[Test]
    public function a_role_code_cannot_be_changed_after_creation(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();

        $this->actingAs($admin)
            ->put(route('roles.update', $this->role('SALES')), ['code' => 'RENAMED', 'name' => 'Sales', 'is_active' => true, 'permission_ids' => []])
            ->assertSessionHasErrors('code');
    }

    #[Test]
    public function editing_a_role_changes_its_holders_permissions_immediately(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        $seller = User::factory()->withRoles('SALES')->create();
        $approve = Permission::where('code', P::SalesOrderApprove->value)->value('id');

        $this->assertFalse($seller->hasPermission(P::SalesOrderApprove));

        $ids = $this->role('SALES')->permissions()->pluck('permissions.id')->push($approve)->all();
        $this->actingAs($admin)->put(route('roles.update', $this->role('SALES')), [
            'name' => 'Sales', 'is_active' => true, 'permission_ids' => $ids,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($seller->fresh()->hasPermission(P::SalesOrderApprove));
    }

    // ------------------------------------------------------------- permissions never override state

    #[Test]
    public function even_an_administrator_cannot_edit_or_repost_a_posted_goods_receipt(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();
        $receipt = GoodsReceipt::factory()->create(['status' => GoodsReceiptStatus::Posted]);

        $this->actingAs($admin)->patch(route('goods-receipts.post', $receipt))->assertSessionHasErrors();
        $this->actingAs($admin)->get(route('goods-receipts.edit', $receipt))->assertStatus(302);

        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->fresh()->status);
    }

    // ------------------------------------------------------------- what the screens receive

    #[Test]
    public function the_screens_receive_exactly_the_users_permissions(): void
    {
        $producer = User::factory()->withRoles('PRODUCTION')->create();

        $this->actingAs($producer)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.permissions', $producer->permissionCodes())
                ->where('auth.permissions', fn ($codes) => collect($codes)->contains(P::ProductionPost->value)
                    && ! collect($codes)->contains(P::FinanceView->value)
                    && ! collect($codes)->contains(P::UsersView->value)));
    }

    #[Test]
    public function a_view_only_user_receives_no_action_permissions_for_that_module(): void
    {
        $viewer = User::factory()->withPermissions(P::PurchasingView)->create();

        $this->actingAs($viewer)->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.permissions', [P::PurchasingView->value]));
    }

    #[Test]
    public function no_password_hash_or_secret_reaches_the_user_screens(): void
    {
        $admin = User::factory()->withRoles('ADMIN')->create();

        $response = $this->actingAs($admin)->get(route('users.show', $admin));

        $response->assertOk();
        $this->assertStringNotContainsString($admin->password, $response->getContent());
        $response->assertInertia(fn (AssertableInertia $page) => $page->missing('user.password')->missing('user.two_factor_secret'));
    }
}
