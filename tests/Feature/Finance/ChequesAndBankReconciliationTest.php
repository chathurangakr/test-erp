<?php

namespace Tests\Feature\Finance;

use App\Actions\Cash\BounceCheque;
use App\Actions\Cash\MarkChequeCleared;
use App\Actions\Cash\RecordBankReconciliation;
use App\Actions\Cash\ReverseBankReconciliation;
use App\Actions\Cash\UndoChequeCleared;
use App\Actions\Payables\RecordSupplierAdvance;
use App\Actions\Receivables\RecordCustomerAdvance;
use App\Enums\AdvanceStatus;
use App\Enums\ChequeStatus;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Models\Supplier;
use App\Models\SupplierAdvance;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\ChequeRegister;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cheque lifecycle and bank reconciliation (2026-10-25). Neither changes how money is journalled:
 * a cheque is waiting, cleared or bounced (a bounce reverses its document), and a bank statement
 * is agreed by ticking the lines it shows until nothing is left over.
 */
class ChequesAndBankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private CashBankAccount $bank;

    private CustomerAdvance $received;

    private SupplierAdvance $issued;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->bank = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();

        $cheque = ['cash_bank_account_id' => $this->bank->id, 'payment_method' => 'CHEQUE', 'cheque_bank' => 'BOC'];
        $this->received = app(RecordCustomerAdvance::class)->handle($cheque + [
            'customer_id' => Customer::factory()->create()->id, 'advance_date' => '2026-09-05', 'amount' => '100000',
            'cheque_number' => '111111', 'cheque_date' => '2026-09-05',
        ]);
        $this->issued = app(RecordSupplierAdvance::class)->handle($cheque + [
            'supplier_id' => Supplier::factory()->create()->id, 'advance_date' => '2026-09-10', 'amount' => '30000',
            'cheque_number' => '222222', 'cheque_date' => '2026-09-25',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(CustomerAdvance|SupplierAdvance $document): array
    {
        return app(ChequeRegister::class)->row($document->refresh());
    }

    #[Test]
    public function the_register_lists_each_cheque_and_marks_it_cleared_or_back(): void
    {
        $cheques = app(ChequeRegister::class)->cheques();
        $this->assertSame(['ISSUED', 'RECEIVED'], $cheques->pluck('direction')->all());
        $this->assertSame([ChequeStatus::Waiting->value], $cheques->pluck('status')->unique()->values()->all());
        $this->assertTrue($this->row($this->issued)['not_yet_due'], 'Dated 25 September; the suite runs before it.');

        $this->assertRefused(fn () => app(MarkChequeCleared::class)->handle(CustomerAdvance::class, $this->received->id, '2026-09-04'), 'event_date', 'before it was recorded');

        app(MarkChequeCleared::class)->handle(CustomerAdvance::class, $this->received->id, '2026-09-08');
        $this->assertSame([ChequeStatus::Cleared->value, '2026-09-08'], [$this->row($this->received)['status'], $this->row($this->received)['status_date']]);
        $this->assertRefused(fn () => app(MarkChequeCleared::class)->handle(CustomerAdvance::class, $this->received->id, '2026-09-09'), 'status', 'not waiting');

        app(UndoChequeCleared::class)->handle(CustomerAdvance::class, $this->received->id);
        $this->assertSame(ChequeStatus::Waiting->value, $this->row($this->received)['status']);
    }

    #[Test]
    public function a_bounced_cheque_reverses_its_document(): void
    {
        $bankBefore = app(AccountingBalanceService::class)->balance(Account::where('code', '1120')->firstOrFail());

        app(BounceCheque::class)->handle(CustomerAdvance::class, $this->received->id, '2026-09-12', 'Insufficient funds');

        $this->assertSame(AdvanceStatus::Reversed, $this->received->refresh()->status);
        $this->assertSame(ChequeStatus::Bounced->value, $this->row($this->received)['status']);
        $this->assertSame(bcsub($bankBefore, '100000', 6), app(AccountingBalanceService::class)->balance(Account::where('code', '1120')->firstOrFail()));
        $this->assertRefused(fn () => app(BounceCheque::class)->handle(CustomerAdvance::class, $this->received->id, '2026-09-12', 'Again'), 'status', 'already reversed');

        // A cheque marked cleared must be un-cleared before it can bounce.
        app(MarkChequeCleared::class)->handle(SupplierAdvance::class, $this->issued->id, '2026-09-26');
        $this->assertRefused(fn () => app(BounceCheque::class)->handle(SupplierAdvance::class, $this->issued->id, '2026-09-27', 'Stopped'), 'status', 'Undo that first');
    }

    #[Test]
    public function a_statement_is_agreed_line_by_line_in_date_order(): void
    {
        $lines = JournalEntryLine::query()->where('account_id', $this->bank->account_id)->orderBy('id')->pluck('id')->all();
        $record = fn (array $overrides) => app(RecordBankReconciliation::class)->handle(array_merge([
            'cash_bank_account_id' => $this->bank->id, 'statement_date' => '2026-09-30', 'statement_balance' => '70000', 'journal_entry_line_ids' => $lines,
        ], $overrides));

        $this->assertRefused(fn () => $record(['statement_balance' => '69000']), 'statement_balance', 'a difference of -1,000.00');
        $this->assertRefused(fn () => $record(['statement_date' => '2026-09-07']), 'journal_entry_line_ids', 'dated after the statement');

        // The statement on the 7th shows only the cheque received.
        $first = $record(['statement_date' => '2026-09-07', 'statement_balance' => '100000', 'journal_entry_line_ids' => [$lines[0]]]);
        $this->assertSame(ChequeStatus::Cleared->value, $this->row($this->received)['status']);
        $this->assertSame(ChequeStatus::Waiting->value, $this->row($this->issued)['status']);

        $this->assertRefused(fn () => $record(['statement_date' => '2026-09-07']), 'statement_date', 'in order');
        $this->assertRefused(fn () => $record(['journal_entry_line_ids' => $lines]), 'journal_entry_line_ids', 'already reconciled');

        $second = $record(['journal_entry_line_ids' => [$lines[1]]]);
        $this->assertSame(ChequeStatus::Cleared->value, $this->row($this->issued)['status']);

        $this->assertRefused(fn () => app(ReverseBankReconciliation::class)->handle($first), 'status', 'Only the latest');
        app(ReverseBankReconciliation::class)->handle($second);
        $this->assertSame(ChequeStatus::Waiting->value, $this->row($this->issued)['status']);
    }

    #[Test]
    public function the_screens_follow_the_permissions(): void
    {
        $viewer = User::factory()->withPermissions(Permission::CashView)->create();

        $this->actingAs($viewer)->get(route('cheques.index'))->assertOk()->assertInertia(fn ($page) => $page->has('cheques', 2));
        $this->actingAs($viewer)->get(route('bank-reconciliation.index'))->assertOk()->assertInertia(fn ($page) => $page->has('lines', 2));
        $this->actingAs($viewer)->post(route('cheques.clear'), ['cheque' => 'CustomerAdvance:'.$this->received->id, 'event_date' => '2026-09-08'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('cheques.clear'), ['cheque' => 'CustomerAdvance:'.$this->received->id, 'event_date' => '2026-09-08'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('cheques.clear'), ['cheque' => 'Account:1', 'event_date' => '2026-09-08'])->assertSessionHasErrors('cheque');
        $this->assertSame(ChequeStatus::Cleared->value, $this->row($this->received)['status']);
    }

    private function assertRefused(callable $call, string $field, string $message): void
    {
        try {
            $call();
            $this->fail("Expected a refusal on {$field}: {$message}");
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, (string) $exception->validator->errors()->first($field));
        }
    }
}
