<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The moment every test runs at, unless it travels elsewhere itself.
     *
     * The scenarios are written around September 2026 — that is when their fiscal periods,
     * invoice due dates and receipts fall. Pinning the clock keeps the suite's answers the same
     * on every day it is run; otherwise "today" drifts out of those periods and reversals, overdue
     * checks and aging change meaning. 10:00 UTC is mid-afternoon in Sri Lanka, safely clear of
     * the midnight boundary that BusinessClockTest examines on purpose.
     */
    public const FIXED_NOW = '2026-09-20 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::FIXED_NOW, 'UTC'));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
