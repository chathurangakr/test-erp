<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_a_user_without_report_permissions_receives_no_chart_figures()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('charts.trend', null)
                ->where('charts.recent_sales', null)
                ->where('charts.cash_bank', null)
                ->where('charts.stock_value', null));
    }

    public function test_each_chart_follows_the_permission_of_its_own_report()
    {
        $this->actingAs(User::factory()->withPermissions(Permission::ReportsInventory)->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('charts.trend', null)
                ->where('charts.cash_bank', null)
                ->where('charts.stock_value', []));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('charts.trend', 6)
                ->where('charts.recent_sales.rows', [])
                ->where('charts.cash_bank', fn ($rows) => is_iterable($rows)));
    }
}
