<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\CancelJournalEntry;
use App\Actions\Accounting\CloseFiscalPeriod;
use App\Actions\Accounting\GenerateJournalReference;
use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AccountType;
use App\Enums\FiscalPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Journal entries — the double-entry engine.
 *
 * The rule the whole system rests on is that a posted journal balances exactly. These tests
 * come at it from every direction a wrong entry could arrive from: too few lines, a line
 * that is both a debit and a credit, a negative amount, an entry that is a rupee out, a
 * closed period, an inactive account, a control account.
 */
class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    private FiscalPeriod $period;

    private Account $electricity;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);

        $this->electricity = Account::factory()->ofType(AccountType::Expense)->create([
            'code' => '6400', 'name' => 'Electricity',
        ]);

        $this->cash = Account::factory()->ofType(AccountType::Asset)->create([
            'code' => '1110', 'name' => 'Cash on Hand',
        ]);
    }

    private function balances(): AccountingBalanceService
    {
        return app(AccountingBalanceService::class);
    }

    /**
     * A draft entry with the given lines.
     *
     * @param  list<array{account: Account, debit?: string, credit?: string}>  $lines
     */
    private function draft(array $lines = [], array $attributes = []): JournalEntry
    {
        $entry = JournalEntry::factory()->create(array_merge([
            'fiscal_period_id' => $this->period->id,
            'journal_date' => '2026-09-15',
            'description' => 'Electricity paid from cash',
        ], $attributes));

        foreach ($lines ?: [
            ['account' => $this->electricity, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '10000.000000'],
        ] as $line) {
            JournalEntryLine::factory()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account']->id,
                'debit' => $line['debit'] ?? '0.000000',
                'credit' => $line['credit'] ?? '0.000000',
            ]);
        }

        return $entry->refresh();
    }

    private function postEntry(JournalEntry $entry, ?int $userId = null): JournalEntry
    {
        return app(PostJournalEntry::class)->handle($entry, $userId);
    }

    // ------------------------------------------------------------- drafts

    #[Test]
    public function a_draft_journal_entry_can_be_created(): void
    {
        $entry = $this->draft();

        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertCount(2, $entry->lines);
        $this->assertSame('10000.000000', $entry->debitTotal());
        $this->assertSame('10000.000000', $entry->creditTotal());
        $this->assertTrue($entry->isBalanced());
    }

    #[Test]
    public function a_draft_affects_no_account_balance(): void
    {
        $this->draft();

        $this->assertSame('0.000000', $this->balances()->debitTotal($this->electricity->id));
        $this->assertSame('0.000000', $this->balances()->creditTotal($this->cash->id));
        $this->assertSame('0.000000', $this->balances()->balance($this->electricity));
    }

    #[Test]
    public function a_cancelled_draft_affects_no_balance_and_survives(): void
    {
        $entry = $this->draft();

        $cancelled = app(CancelJournalEntry::class)->handle($entry);

        $this->assertSame(JournalEntryStatus::Cancelled, $cancelled->status);
        $this->assertSame('0.000000', $this->balances()->balance($this->electricity));

        // Nothing was deleted.
        $this->assertNotNull(JournalEntry::find($entry->id));
        $this->assertCount(2, $cancelled->lines()->get());
    }

    #[Test]
    public function a_posted_entry_cannot_be_cancelled(): void
    {
        $entry = $this->postEntry($this->draft());

        $this->expectException(ValidationException::class);

        app(CancelJournalEntry::class)->handle($entry);
    }

    // ------------------------------------------------------------- the balance rule

    #[Test]
    public function a_balanced_entry_posts_and_affects_balances(): void
    {
        $user = User::factory()->admin()->create();

        $posted = $this->postEntry($this->draft(), $user->id);

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
        $this->assertSame($user->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);

        // Dr Electricity 10,000 / Cr Cash 10,000.
        $this->assertSame('10000.000000', $this->balances()->debitTotal($this->electricity->id));
        $this->assertSame('10000.000000', $this->balances()->creditTotal($this->cash->id));

        // Both read positive on their own normal side.
        $this->assertSame('10000.000000', $this->balances()->balance($this->electricity));
        $this->assertSame('-10000.000000', $this->balances()->balance($this->cash));
    }

    #[Test]
    public function an_unbalanced_entry_is_rejected(): void
    {
        $entry = $this->draft([
            ['account' => $this->electricity, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '9999.999999'],
        ]);

        try {
            $this->postEntry($entry);
            $this->fail('Expected an unbalanced journal to be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('does not balance', implode(' ', $e->errors()['lines']));
        }

        $this->assertSame(JournalEntryStatus::Draft, $entry->fresh()->status);
        $this->assertSame('0.000000', $this->balances()->debitTotal($this->electricity->id));
    }

    #[Test]
    public function a_journal_must_have_at_least_two_lines(): void
    {
        $entry = $this->draft([['account' => $this->electricity, 'debit' => '10000.000000']]);

        $this->expectException(ValidationException::class);

        $this->postEntry($entry);
    }

    #[Test]
    public function a_line_cannot_be_both_a_debit_and_a_credit(): void
    {
        $entry = $this->draft();
        $entry->lines()->first()->update(['debit' => '10000.000000', 'credit' => '10000.000000']);

        $this->expectException(ValidationException::class);

        $this->postEntry($entry->refresh());
    }

    #[Test]
    public function a_line_with_no_amount_is_rejected(): void
    {
        $entry = $this->draft([
            ['account' => $this->electricity, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '10000.000000'],
            ['account' => $this->cash],
        ]);

        $this->expectException(ValidationException::class);

        $this->postEntry($entry);
    }

    #[Test]
    public function a_negative_debit_is_rejected(): void
    {
        $entry = $this->draft([
            ['account' => $this->electricity, 'debit' => '-10000.000000'],
            ['account' => $this->cash, 'credit' => '-10000.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postEntry($entry);
    }

    #[Test]
    public function a_negative_credit_is_rejected(): void
    {
        $entry = $this->draft([
            ['account' => $this->electricity, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '-10000.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postEntry($entry);
    }

    #[Test]
    public function an_entry_that_debits_and_credits_nothing_is_rejected(): void
    {
        $entry = $this->draft([
            ['account' => $this->electricity, 'debit' => '0.000000'],
            ['account' => $this->cash, 'credit' => '0.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postEntry($entry);
    }

    #[Test]
    public function the_balance_rule_holds_at_the_sixth_decimal(): void
    {
        // Three lines against one, summing exactly. A float would not survive this.
        $other = Account::factory()->ofType(AccountType::Expense)->create(['code' => '6500']);

        $posted = $this->postEntry($this->draft([
            ['account' => $this->electricity, 'debit' => '0.333333'],
            ['account' => $this->electricity, 'debit' => '0.333333'],
            ['account' => $other, 'debit' => '0.333334'],
            ['account' => $this->cash, 'credit' => '1.000000'],
        ]));

        $this->assertSame('1.000000', $posted->debitTotal());
        $this->assertSame('1.000000', $posted->creditTotal());
        $this->assertSame('0.000000', $posted->difference());
    }

    // ------------------------------------------------------------- accounts

    #[Test]
    public function an_inactive_account_cannot_be_posted_to(): void
    {
        $this->cash->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->postEntry($this->draft());
    }

    #[Test]
    public function a_manual_entry_cannot_post_to_a_control_account(): void
    {
        $control = Account::factory()->control()->create(['code' => '1210', 'name' => 'Trade Receivables']);

        $entry = $this->draft([
            ['account' => $control, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '10000.000000'],
        ]);

        try {
            $this->postEntry($entry);
            $this->fail('Expected a control account to refuse a manual posting.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('control account', implode(' ', $e->errors()['lines.0.account_id']));
        }

        $this->assertSame('0.000000', $this->balances()->debitTotal($control->id));
    }

    /**
     * A control account is the system's to post to, not a person's — so an entry that names
     * its source document may use one.
     */
    #[Test]
    public function a_system_generated_entry_may_post_to_a_control_account(): void
    {
        $control = Account::factory()->control()->create(['code' => '1210']);

        $entry = $this->draft([
            ['account' => $control, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '10000.000000'],
        ], ['source_type' => 'App\\Models\\Delivery', 'source_id' => 1]);

        $posted = $this->postEntry($entry);

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
        $this->assertSame('10000.000000', $this->balances()->debitTotal($control->id));
    }

    #[Test]
    public function an_account_that_refuses_manual_posting_is_rejected(): void
    {
        $header = Account::factory()->noManualPosting()->create(['code' => '6000']);

        $entry = $this->draft([
            ['account' => $header, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '10000.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postEntry($entry);
    }

    // ------------------------------------------------------------- fiscal periods

    #[Test]
    public function a_closed_period_rejects_a_posting(): void
    {
        app(CloseFiscalPeriod::class)->handle($this->period);

        $entry = $this->draft();

        try {
            $this->postEntry($entry);
            $this->fail('Expected a closed period to refuse the posting.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('closed', implode(' ', $e->errors()['fiscal_period_id']));
        }

        $this->assertSame(JournalEntryStatus::Draft, $entry->fresh()->status);
    }

    #[Test]
    public function a_journal_date_outside_its_period_is_rejected(): void
    {
        $entry = $this->draft(attributes: ['journal_date' => '2026-10-05']);

        try {
            $this->postEntry($entry);
            $this->fail('Expected a date outside the period to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_date', $e->errors());
        }

        $this->assertSame(JournalEntryStatus::Draft, $entry->fresh()->status);
    }

    // ------------------------------------------------------------- immutability

    #[Test]
    public function a_posted_entry_cannot_be_edited_or_deleted(): void
    {
        $posted = $this->postEntry($this->draft());

        try {
            $posted->update(['description' => 'tampered']);
            $this->fail('Expected the update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $posted->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only a draft', $e->getMessage());
        }
    }

    #[Test]
    public function the_lines_of_a_posted_entry_cannot_be_edited_or_deleted(): void
    {
        $posted = $this->postEntry($this->draft());
        $line = $posted->lines()->first();

        try {
            $line->update(['debit' => '1.000000']);
            $this->fail('Expected the line update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $line->delete();
            $this->fail('Expected the line delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }

        $this->assertSame('10000.000000', $this->balances()->debitTotal($this->electricity->id));
    }

    #[Test]
    public function an_entry_cannot_be_posted_twice(): void
    {
        $posted = $this->postEntry($this->draft());

        try {
            $this->postEntry($posted);
            $this->fail('Expected double posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('10000.000000', $this->balances()->debitTotal($this->electricity->id));
    }

    // ------------------------------------------------------------- numbering

    #[Test]
    public function journal_numbers_are_unique_and_sequential(): void
    {
        $first = app(GenerateJournalReference::class)->forJournalEntry();
        JournalEntry::factory()->create([
            'journal_number' => $first,
            'fiscal_period_id' => $this->period->id,
        ]);
        $second = app(GenerateJournalReference::class)->forJournalEntry();

        $this->assertMatchesRegularExpression('/^JV-\d{8}-\d{6}$/', $first);
        $this->assertNotSame($first, $second);
        $this->assertSame(1, (int) substr($first, -6));
        $this->assertSame(2, (int) substr($second, -6));

        $this->expectException(QueryException::class);

        JournalEntry::factory()->create([
            'journal_number' => $first,
            'fiscal_period_id' => $this->period->id,
        ]);
    }

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_creates_a_new_entry_with_the_sides_swapped(): void
    {
        $user = User::factory()->admin()->create();
        $original = $this->postEntry($this->draft(), $user->id);

        $reversal = app(ReverseJournalEntry::class)->handle($original, $user->id);

        $this->assertNotSame($original->id, $reversal->id);
        $this->assertSame(JournalEntryStatus::Posted, $reversal->status);
        $this->assertSame($original->id, $reversal->reversal_of_id);

        $lines = $reversal->lines->keyBy('account_id');

        // Original: Dr Electricity 10,000 / Cr Cash 10,000.
        // Reversal: Dr Cash 10,000 / Cr Electricity 10,000.
        $this->assertSame('10000.000000', $lines[$this->cash->id]->debit);
        $this->assertSame('0.000000', $lines[$this->cash->id]->credit);
        $this->assertSame('10000.000000', $lines[$this->electricity->id]->credit);
        $this->assertSame('0.000000', $lines[$this->electricity->id]->debit);
    }

    #[Test]
    public function a_reversal_leaves_every_balance_at_zero(): void
    {
        $original = $this->postEntry($this->draft());
        app(ReverseJournalEntry::class)->handle($original);

        $this->assertSame('0.000000', $this->balances()->netBalance($this->electricity->id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->cash->id));

        // Both sides remain visible — the correction is auditable, not erased.
        $this->assertSame('10000.000000', $this->balances()->debitTotal($this->electricity->id));
        $this->assertSame('10000.000000', $this->balances()->creditTotal($this->electricity->id));
    }

    #[Test]
    public function the_original_remains_intact_and_readable_after_reversal(): void
    {
        $user = User::factory()->admin()->create();
        $original = $this->postEntry($this->draft(), $user->id);

        $reversal = app(ReverseJournalEntry::class)->handle($original, $user->id);
        $fresh = $original->fresh(['lines']);

        $this->assertSame(JournalEntryStatus::Reversed, $fresh->status);
        $this->assertSame($reversal->id, $fresh->reversed_by_id);
        $this->assertSame($user->id, $fresh->reversed_by_user_id);
        $this->assertNotNull($fresh->reversed_at);

        // Its own lines are untouched.
        $this->assertCount(2, $fresh->lines);
        $this->assertSame('10000.000000', $fresh->lines->firstWhere('account_id', $this->electricity->id)->debit);
    }

    #[Test]
    public function a_reversal_preserves_awkward_amounts_exactly(): void
    {
        $original = $this->postEntry($this->draft([
            ['account' => $this->electricity, 'debit' => '16000.000000'],
            ['account' => $this->cash, 'credit' => '15999.999960'],
            ['account' => $this->cash, 'credit' => '0.000040'],
        ]));

        $reversal = app(ReverseJournalEntry::class)->handle($original);

        $this->assertSame('16000.000000', $reversal->debitTotal());
        $this->assertSame('16000.000000', $reversal->creditTotal());
        $this->assertSame(
            ['0.000040', '15999.999960'],
            $reversal->lines->pluck('debit')->filter(fn (string $d): bool => $d !== '0.000000')->sort()->values()->all(),
        );

        $this->assertSame('0.000000', $this->balances()->netBalance($this->electricity->id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->cash->id));
    }

    #[Test]
    public function an_entry_cannot_be_reversed_twice(): void
    {
        $original = $this->postEntry($this->draft());
        app(ReverseJournalEntry::class)->handle($original);

        $this->expectException(ValidationException::class);

        app(ReverseJournalEntry::class)->handle($original->fresh());
    }

    #[Test]
    public function a_reversal_cannot_itself_be_reversed(): void
    {
        $original = $this->postEntry($this->draft());
        $reversal = app(ReverseJournalEntry::class)->handle($original);

        $this->expectException(ValidationException::class);

        app(ReverseJournalEntry::class)->handle($reversal);
    }

    #[Test]
    public function a_draft_cannot_be_reversed(): void
    {
        $this->expectException(ValidationException::class);

        app(ReverseJournalEntry::class)->handle($this->draft());
    }

    #[Test]
    public function a_reversal_needs_an_open_period_of_its_own(): void
    {
        $original = $this->postEntry($this->draft());

        // Close every period, so the reversal has nowhere to land.
        app(CloseFiscalPeriod::class)->handle($this->period);

        try {
            app(ReverseJournalEntry::class)->handle($original, null, ['journal_date' => '2026-09-20']);
            $this->fail('Expected the reversal to be refused with no open period.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_date', $e->errors());
        }

        // The original is untouched — no half-finished reversal.
        $this->assertSame(JournalEntryStatus::Posted, $original->fresh()->status);
        $this->assertNull($original->fresh()->reversed_by_id);
        $this->assertSame(1, JournalEntry::count());
    }

    #[Test]
    public function a_reversal_posts_into_the_period_containing_its_own_date(): void
    {
        $original = $this->postEntry($this->draft());

        $october = FiscalPeriod::factory()->create([
            'name' => 'October 2026',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);

        $reversal = app(ReverseJournalEntry::class)->handle($original, null, ['journal_date' => '2026-10-10']);

        $this->assertSame($october->id, $reversal->fiscal_period_id);
        $this->assertSame('2026-10-10', $reversal->journal_date->toDateString());
    }

    // ------------------------------------------------------------- fiscal period rules

    #[Test]
    public function a_period_with_draft_entries_cannot_be_closed(): void
    {
        $this->draft();

        try {
            app(CloseFiscalPeriod::class)->handle($this->period);
            $this->fail('Expected closing to be refused while a draft exists.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('draft journal entries', implode(' ', $e->errors()['status']));
        }

        $this->assertSame(FiscalPeriodStatus::Open, $this->period->fresh()->status);
    }

    #[Test]
    public function a_period_closes_once_its_drafts_are_resolved(): void
    {
        $user = User::factory()->admin()->create();
        $draft = $this->draft();

        app(CancelJournalEntry::class)->handle($draft);

        $closed = app(CloseFiscalPeriod::class)->handle($this->period, $user->id);

        $this->assertSame(FiscalPeriodStatus::Closed, $closed->status);
        $this->assertSame($user->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }

    #[Test]
    public function a_closed_period_cannot_be_closed_again(): void
    {
        app(CloseFiscalPeriod::class)->handle($this->period);

        $this->expectException(ValidationException::class);

        app(CloseFiscalPeriod::class)->handle($this->period->fresh());
    }

    #[Test]
    public function the_open_period_for_a_date_is_found_by_its_range(): void
    {
        $this->assertSame($this->period->id, FiscalPeriod::openFor('2026-09-01')?->id);
        $this->assertSame($this->period->id, FiscalPeriod::openFor('2026-09-30')?->id);
        $this->assertNull(FiscalPeriod::openFor('2026-08-31'));
        $this->assertNull(FiscalPeriod::openFor('2026-10-01'));

        app(CloseFiscalPeriod::class)->handle($this->period);

        $this->assertNull(FiscalPeriod::openFor('2026-09-15'));
    }
}
