<?php

namespace Tests\Feature\Administration;

use App\Enums\Permission;
use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The company details printed on invoices and reports: kept in the database, logo included, so
 * a backup carries them.
 */
class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function before_anything_is_saved_printouts_use_the_application_name(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.name', config('app.name'))
                ->where('company.logo_url', null));

        $this->assertSame(0, CompanyProfile::count());
    }

    #[Test]
    public function an_administrator_saves_the_details_and_logo_and_every_page_carries_them(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('company.update'), [
                'name' => 'True Line Company',
                'address' => "12 Factory Road\nKurunegala",
                'phone' => '077 123 4567',
                'email' => 'office@example.com',
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('company.edit'));

        $profile = CompanyProfile::sole();
        $this->assertSame('True Line Company', $profile->name);
        $this->assertSame($admin->id, $profile->updated_by);
        $this->assertStringStartsWith('data:image/png;base64,', $profile->logo);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('company.name', 'True Line Company')
                ->where('company.phone', '077 123 4567')
                ->where('company.logo_url', fn (?string $url) => str_starts_with((string) $url, '/company/logo')));

        $this->actingAs(User::factory()->create())
            ->get(route('company.logo'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function saving_without_a_new_logo_keeps_the_old_one_and_removing_clears_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('company.update'), [
            'name' => 'True Line Company',
            'logo' => UploadedFile::fake()->image('logo.jpg'),
        ]);

        $this->actingAs($admin)->put(route('company.update'), ['name' => 'True Line Company (Pvt) Ltd']);
        $this->assertNotNull(CompanyProfile::sole()->logo);
        $this->assertSame('True Line Company (Pvt) Ltd', CompanyProfile::sole()->name);

        $this->actingAs($admin)->put(route('company.update'), ['name' => 'True Line Company', 'remove_logo' => true]);
        $this->assertNull(CompanyProfile::sole()->logo);
        $this->actingAs($admin)->get(route('company.logo'))->assertNotFound();
    }

    #[Test]
    public function only_an_image_is_accepted_as_the_logo(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('company.update'), [
                'name' => 'True Line Company',
                'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('logo');

        $this->assertSame(0, CompanyProfile::count());
    }

    #[Test]
    public function the_details_need_the_company_permission(): void
    {
        $this->actingAs(User::factory()->withPermissions(Permission::UsersManage)->create())
            ->put(route('company.update'), ['name' => 'Someone Else'])
            ->assertForbidden();

        $this->actingAs(User::factory()->withPermissions(Permission::CompanyManage)->create())
            ->get(route('company.edit'))
            ->assertOk();
    }
}
