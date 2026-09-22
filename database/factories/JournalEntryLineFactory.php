<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntryLine>
 */
class JournalEntryLineFactory extends Factory
{
    protected $model = JournalEntryLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'account_id' => Account::factory(),
            'description' => null,
            'debit' => '0.000000',
            'credit' => '0.000000',
        ];
    }

    public function debit(string $amount): static
    {
        return $this->state(fn (): array => ['debit' => $amount, 'credit' => '0.000000']);
    }

    public function credit(string $amount): static
    {
        return $this->state(fn (): array => ['credit' => $amount, 'debit' => '0.000000']);
    }
}
