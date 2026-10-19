<?php

namespace Tests\Feature\Reports;

use App\Enums\Permission;
use App\Mail\WeeklyUpdate;
use App\Models\User;
use App\Services\Reporting\WeeklyUpdateReport;
use App\Support\DisplayNumber;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Sunday-evening email: who receives it, which week it covers, and that it renders.
 */
class WeeklyUpdateEmailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_goes_to_every_active_user_holding_the_permission_and_nobody_else(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create(['email' => 'owner@example.test']);
        $granted = User::factory()->withPermissions(Permission::ReportsWeeklyEmail)->create(['email' => 'accountant@example.test']);
        User::factory()->admin()->create(['email' => 'former@example.test', 'is_active' => false]);
        User::factory()->withPermissions(Permission::ReportsFinance)->create(['email' => 'clerk@example.test']);

        $this->artisan('reports:weekly-update')->assertSuccessful();

        Mail::assertSentCount(2);
        Mail::assertSent(WeeklyUpdate::class, fn (WeeklyUpdate $mail) => $mail->hasTo($admin->email));
        Mail::assertSent(WeeklyUpdate::class, fn (WeeklyUpdate $mail) => $mail->hasTo($granted->email));
    }

    #[Test]
    public function it_covers_monday_to_sunday_ending_on_the_sunday_it_runs(): void
    {
        Mail::fake();
        User::factory()->admin()->create();

        // Sunday 18 October 2026, 8 PM in Sri Lanka.
        $this->travelTo(Carbon::parse('2026-10-18 20:00', 'Asia/Colombo'));
        $this->artisan('reports:weekly-update')->assertSuccessful();

        Mail::assertSent(WeeklyUpdate::class, fn (WeeklyUpdate $mail) => $mail->report['from'] === '2026-10-12'
            && $mail->report['to'] === '2026-10-18');

        // Run by hand mid-week, it reports the last complete week.
        $this->travelTo(Carbon::parse('2026-10-21 10:00', 'Asia/Colombo'));
        $this->artisan('reports:weekly-update', ['--to' => 'test@example.test'])->assertSuccessful();

        Mail::assertSent(WeeklyUpdate::class, fn (WeeklyUpdate $mail) => $mail->hasTo('test@example.test')
            && $mail->report['to'] === '2026-10-18');
    }

    #[Test]
    public function the_email_renders_with_every_section(): void
    {
        $report = app(WeeklyUpdateReport::class)
            ->build(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
        $html = (new WeeklyUpdate($report))->render();

        foreach (['Weekly update', 'Sales &amp; profit', 'Cash &amp; bank', 'Money owed', 'Production &amp; stock', 'Stock value now'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    #[Test]
    public function it_is_scheduled_for_sunday_8_pm_sri_lanka_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'reports:weekly-update'));

        $this->assertNotNull($event);
        $this->assertSame('0 20 * * 0', $event->expression);
        $this->assertSame('Asia/Colombo', $event->timezone);
    }

    #[Test]
    public function figures_are_written_from_the_decimal_string(): void
    {
        $this->assertSame('1,234,567.89', DisplayNumber::money('1234567.885000'));
        $this->assertSame('-15,000.00', DisplayNumber::money('-15000.000000'));
        $this->assertSame('0.00', DisplayNumber::money('-0.001000'));
        $this->assertSame('1,520.5', DisplayNumber::quantity('1520.500'));
        $this->assertSame('12,000', DisplayNumber::quantity('12000.000'));
    }
}
