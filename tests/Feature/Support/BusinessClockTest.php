<?php

namespace Tests\Feature\Support;

use App\Actions\Purchasing\GeneratePurchasingReference;
use App\Models\GoodsReceipt;
use App\Models\User;
use App\Support\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The factory runs on Sri Lankan time (UTC+05:30) while stored moments are UTC.
 *
 * The dangerous window is 00:00-05:30 in Colombo, when the UTC date is still yesterday.
 * Every test here freezes the clock inside that window.
 */
class BusinessClockTest extends TestCase
{
    use RefreshDatabase;

    /** 02:00 on 27 September in Colombo = 20:30 on 26 September in UTC. */
    private function earlyMorningInColombo(): void
    {
        $this->travelTo(Carbon::parse('2026-09-26 20:30:00', 'UTC'));
    }

    #[Test]
    public function today_follows_the_factory_calendar_not_utc(): void
    {
        $this->earlyMorningInColombo();

        $this->assertSame('2026-09-26', now()->toDateString(), 'Sanity: the UTC date is still the 26th.');
        $this->assertSame('2026-09-27', BusinessClock::todayString());
    }

    #[Test]
    public function document_numbers_carry_the_sri_lankan_date(): void
    {
        $this->earlyMorningInColombo();

        $this->assertStringStartsWith('GRN-20260927-', app(GeneratePurchasingReference::class)->forGoodsReceipt());
    }

    #[Test]
    public function an_invoice_due_yesterday_in_sri_lanka_is_overdue_even_before_0530(): void
    {
        $this->earlyMorningInColombo();

        $this->assertTrue(BusinessClock::isPastDue(Carbon::parse('2026-09-26')));
        $this->assertFalse(BusinessClock::isPastDue(Carbon::parse('2026-09-27')));
        $this->assertFalse(BusinessClock::isPastDue(null));
    }

    #[Test]
    public function stored_utc_moments_are_shown_in_sri_lankan_time(): void
    {
        $this->assertSame('2026-09-27 02:00:00', BusinessClock::display(Carbon::parse('2026-09-26 20:30:00', 'UTC')));
        $this->assertNull(BusinessClock::display(null));
    }

    #[Test]
    public function stored_timestamps_stay_utc(): void
    {
        $this->earlyMorningInColombo();

        $receipt = GoodsReceipt::factory()->create();

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('2026-09-26 20:30:00', $receipt->fresh()->getRawOriginal('created_at'));
    }

    #[Test]
    public function every_page_receives_the_sri_lankan_date_for_form_defaults(): void
    {
        $this->earlyMorningInColombo();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('businessToday', '2026-09-27'));
    }
}
