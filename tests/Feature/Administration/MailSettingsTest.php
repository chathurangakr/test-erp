<?php

namespace Tests\Feature\Administration;

use App\Enums\Permission;
use App\Mail\WeeklyUpdate;
use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Administration → Email settings: the account the system sends from, kept in the database with
 * its password encrypted and never shown back.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function valid(array $overrides = []): array
    {
        return [
            'is_enabled' => true,
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'factory@gmail.com',
            'password' => 'abcd efgh ijkl mnop',
            'from_address' => '',
            'from_name' => 'Example Mills ERP',
            ...$overrides,
        ];
    }

    #[Test]
    public function the_password_is_stored_encrypted_and_never_sent_back_to_the_screen(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('mail-settings.update'), $this->valid())->assertSessionHasNoErrors();

        $settings = MailSetting::sole();
        $this->assertSame('abcdefghijklmnop', $settings->password); // spaces removed
        $this->assertStringNotContainsString('abcdefghijklmnop', (string) DB::table('mail_settings')->value('password'));

        $this->actingAs($admin)->get(route('mail-settings.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('administration/mail/Edit')
                ->where('settings.has_password', true)
                ->missing('settings.password'));
    }

    #[Test]
    public function a_blank_password_keeps_the_saved_one_but_switching_on_needs_one(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('mail-settings.update'), $this->valid(['password' => '']))
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)->put(route('mail-settings.update'), $this->valid());
        $this->actingAs($admin)->put(route('mail-settings.update'), $this->valid(['password' => '', 'from_name' => 'Factory']))
            ->assertSessionHasNoErrors();

        $this->assertSame('abcdefghijklmnop', MailSetting::sole()->password);
        $this->assertSame('Factory', MailSetting::sole()->from_name);
    }

    #[Test]
    public function saved_settings_drive_the_mailer_only_while_switched_on(): void
    {
        MailSetting::query()->create([...$this->valid(['password' => 'secretpassword', 'encryption' => 'ssl', 'port' => 465]), 'is_enabled' => false]);

        MailSetting::current()->applyToConfig();
        $this->assertNotSame('smtp.gmail.com', config('mail.mailers.smtp.host'));

        MailSetting::query()->update(['is_enabled' => true]);
        MailSetting::current()->applyToConfig();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('secretpassword', config('mail.mailers.smtp.password'));
        $this->assertSame('factory@gmail.com', config('mail.from.address'));
    }

    #[Test]
    public function the_test_button_sends_last_weeks_update_to_the_signed_in_user(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create(['email' => 'owner@example.test']);

        $this->actingAs($admin)->post(route('mail-settings.test'))->assertSessionHasNoErrors();

        Mail::assertSent(WeeklyUpdate::class, fn (WeeklyUpdate $mail) => $mail->hasTo('owner@example.test'));
    }

    #[Test]
    public function only_the_mail_permission_opens_the_screen(): void
    {
        $this->actingAs(User::factory()->withPermissions(Permission::CompanyManage)->create())
            ->get(route('mail-settings.edit'))->assertForbidden();

        $this->actingAs(User::factory()->withPermissions(Permission::MailManage)->create())
            ->get(route('mail-settings.edit'))->assertOk();
    }
}
