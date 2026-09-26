<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mobile home screen is filtered on the SERVER: a section the user may not see is never
 * sent, rather than sent and hidden by the app.
 */
class ApiDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return list<string>
     */
    private function sectionsFor(string ...$roles): array
    {
        Sanctum::actingAs(User::factory()->withRoles(...$roles)->create(), ['mobile-api']);

        return array_keys($this->getJson(route('api.v1.dashboard'))->assertOk()->json('data.sections'));
    }

    #[Test]
    public function a_storekeeper_receives_operational_sections_and_no_finance_figures(): void
    {
        $sections = $this->sectionsFor('STOREKEEPER');

        $this->assertContains('inventory', $sections);
        $this->assertContains('purchasing', $sections);
        $this->assertNotContains('finance', $sections);
        $this->assertNotContains('receivables', $sections);
        $this->assertNotContains('cash_bank', $sections);
    }

    #[Test]
    public function production_sees_production_but_not_purchasing_or_finance(): void
    {
        $sections = $this->sectionsFor('PRODUCTION');

        $this->assertContains('production', $sections);
        $this->assertNotContains('purchasing', $sections);
        $this->assertNotContains('finance', $sections);
    }

    #[Test]
    public function an_accountant_receives_the_finance_sections(): void
    {
        $sections = $this->sectionsFor('ACCOUNTANT');

        foreach (['finance', 'receivables', 'payables', 'cash_bank'] as $section) {
            $this->assertContains($section, $sections);
        }
    }

    #[Test]
    public function inventory_value_is_only_sent_with_the_inventory_report_permission(): void
    {
        Sanctum::actingAs(User::factory()->withRoles('PURCHASING')->create(), ['mobile-api']);

        $this->getJson(route('api.v1.dashboard'))->assertOk()->assertJsonMissingPath('data.sections.inventory.inventory_value');
    }

    #[Test]
    public function a_user_with_no_role_gets_an_empty_dashboard_not_an_error(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mobile-api']);

        $this->getJson(route('api.v1.dashboard'))->assertOk()->assertExactJson([
            'data' => ['currency' => config('accounting.base_currency'), 'sections' => []],
        ]);
    }
}
