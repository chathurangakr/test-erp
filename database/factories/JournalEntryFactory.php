<?php

namespace Database\Factories;

use App\Enums\JournalEntryStatus;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_number' => 'JV-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'journal_date' => now()->toDateString(),
            'fiscal_period_id' => FiscalPeriod::factory(),
            'source_type' => null,
            'source_id' => null,
            'description' => $this->faker->sentence(),
            'status' => JournalEntryStatus::Draft,
            'created_by' => null,
        ];
    }
}
