<?php

namespace Database\Factories;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalPeriod>
 */
class FiscalPeriodFactory extends Factory
{
    protected $model = FiscalPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'name' => $start->format('F Y').' '.$this->faker->unique()->numberBetween(1, 999999),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->endOfMonth()->toDateString(),
            'status' => FiscalPeriodStatus::Open,
            'closed_at' => null,
            'closed_by' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => FiscalPeriodStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
