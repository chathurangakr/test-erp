<?php

namespace Tests\Feature\Finance;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The accounting calendar keeps itself open ahead (2026-10-25): this month and next month always
 * exist as open fiscal periods, so postings never stop at a month end.
 */
class OpenNextFiscalPeriodsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function calendar(): array
    {
        return FiscalPeriod::query()->orderBy('start_date')->get()
            ->map(fn (FiscalPeriod $p): string => "{$p->name} {$p->start_date->toDateString()}..{$p->end_date->toDateString()} {$p->status->value}")
            ->all();
    }

    #[Test]
    public function it_opens_this_month_and_next_once_each(): void
    {
        FiscalPeriod::factory()->create(['name' => 'October 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $this->travelTo(Carbon::parse('2026-10-25 09:00', 'Asia/Colombo'));

        $this->artisan('finance:open-next-periods')->assertSuccessful();
        $this->artisan('finance:open-next-periods')->assertSuccessful();

        $this->assertSame([
            'October 2026 2026-10-01..2026-10-31 OPEN',
            'November 2026 2026-11-01..2026-11-30 OPEN',
        ], $this->calendar());

        // On 1 December, December is already there and January follows.
        $this->travelTo(Carbon::parse('2026-12-01 00:05', 'Asia/Colombo'));
        $this->artisan('finance:open-next-periods')->assertSuccessful();
        $this->assertSame(['2026-12-01', '2027-01-01'], FiscalPeriod::query()->whereDate('start_date', '>=', '2026-12-01')->orderBy('start_date')->pluck('start_date')->map->toDateString()->all());
    }

    #[Test]
    public function it_never_starts_a_calendar_or_touches_a_period_someone_made(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 09:00', 'Asia/Colombo'));
        $this->artisan('finance:open-next-periods')->assertSuccessful();
        $this->assertSame([], $this->calendar());

        // A quarter someone opened by hand covers next month: left alone, nothing overlapping added.
        FiscalPeriod::factory()->create(['name' => 'Oct–Dec 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => FiscalPeriodStatus::Closed]);
        $this->artisan('finance:open-next-periods')->assertSuccessful();

        $this->assertSame(['Oct–Dec 2026 2026-10-01..2026-12-31 CLOSED'], $this->calendar());
    }

    #[Test]
    public function it_runs_every_day_just_after_midnight_sri_lanka_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'finance:open-next-periods'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertSame('Asia/Colombo', $event->timezone);
    }
}
