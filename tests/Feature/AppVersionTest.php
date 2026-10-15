<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_version_comes_from_the_version_file(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', config('app.version'));
        $this->assertSame(trim((string) file_get_contents(base_path('VERSION'))), config('app.version'));
    }

    public function test_the_sign_in_page_is_told_the_version(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('appVersion', config('app.version')));
    }

    public function test_every_signed_in_screen_is_told_the_version(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('appVersion', config('app.version')));
    }

    public function test_the_changelog_describes_the_current_version(): void
    {
        $this->assertStringContainsString('## '.config('app.version').' ', (string) file_get_contents(base_path('CHANGELOG.md')));
    }
}
