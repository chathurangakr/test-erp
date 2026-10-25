<?php

namespace Tests\Feature\Lists;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The index-friendly date filters (App\Support\DateFilters) select exactly the days whereDate()
 * did — on a date-and-time column too, where the last day must count until midnight.
 */
class DateFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the names of the users the filter keeps, by when they were added */
    private function kept(callable $filter): array
    {
        return $filter(DB::table('users'))->orderBy('created_at')->pluck('name')->all();
    }

    #[Test]
    public function each_filter_keeps_whole_days_on_a_date_and_time_column(): void
    {
        foreach (['A' => '2026-09-09 23:59:59', 'B' => '2026-09-10 00:00:00', 'C' => '2026-09-10 23:59:59', 'D' => '2026-09-11 00:00:00'] as $name => $moment) {
            User::factory()->create(['name' => $name, 'created_at' => $moment]);
        }

        $this->assertSame(['B', 'C', 'D'], $this->kept(fn ($q) => $q->whereOnOrAfter('created_at', '2026-09-10')));
        $this->assertSame(['A', 'B', 'C'], $this->kept(fn ($q) => $q->whereOnOrBefore('created_at', '2026-09-10')));
        $this->assertSame(['A'], $this->kept(fn ($q) => $q->whereBeforeDay('created_at', '2026-09-10')));
        $this->assertSame(['D'], $this->kept(fn ($q) => $q->whereAfterDay('created_at', '2026-09-10')));
        $this->assertSame(['B', 'C'], $this->kept(fn ($q) => $q->whereOnDay('created_at', now()->setDate(2026, 9, 10)->setTime(15, 30))));

        // The same filters on an Eloquent query.
        $this->assertSame(2, User::query()->whereOnDay('created_at', '2026-09-10')->count());
    }
}
