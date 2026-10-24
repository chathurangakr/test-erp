<?php

namespace Tests\Feature\Administration;

use App\Actions\Assets\RecordFixedAsset;
use App\Actions\Assets\ReverseAssetSale;
use App\Actions\Assets\SellFixedAsset;
use App\Actions\Loans\RecordLoan;
use App\Actions\Loans\ReverseLoan;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\FixedAsset;
use App\Models\Loan;
use App\Models\TelegramAlert;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Telegram\TransactionAlertCatalog;
use App\Services\Telegram\TransactionAlertMessage;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Transaction alerts (2026-10-25): every posting and reversal queues a message for the
 * transactions bot in the posting's own transaction, and `telegram:send-alerts` sends each one
 * once, with the document's details.
 */
class TransactionAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '987654321:AAFakeTransactionsBot_x';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->admin = User::factory()->admin()->create(['name' => 'Chathu']);
        Sleep::fake();
    }

    /**
     * @param  list<string>|null  $types
     */
    private function switchOn(?array $types = null): void
    {
        TelegramSetting::create([
            'purpose' => TelegramSetting::TRANSACTIONS,
            'is_enabled' => true,
            'bot_token' => self::TOKEN,
            'chats' => [['id' => '-100777', 'title' => 'Factory owners'], ['id' => '555111', 'title' => 'Chathu']],
            'alert_types' => $types,
        ]);
    }

    private function loan(): Loan
    {
        return app(RecordLoan::class)->handle([
            'lender' => 'People\'s Bank', 'start_date' => '2026-09-01', 'funding' => 'EXISTING',
            'amount' => '500000', 'installment_amount' => '25000', 'due_day' => 25,
        ], $this->admin->id);
    }

    #[Test]
    public function posting_and_reversing_queue_an_alert_only_while_the_bot_wants_that_kind(): void
    {
        $this->loan();
        $this->assertSame(0, TelegramAlert::count(), 'No bot, no alerts.');

        $this->switchOn(['deliveries']);
        $this->loan();
        $this->assertSame(0, TelegramAlert::count(), 'Loans are not ticked.');

        TelegramSetting::query()->update(['alert_types' => null]);
        $loan = $this->loan();
        app(ReverseLoan::class)->handle($loan, $this->admin->id);

        $this->assertSame(
            [['loans', $loan->id, 'posted'], ['loans', $loan->id, 'reversed']],
            TelegramAlert::query()->orderBy('id')->get()->map(fn (TelegramAlert $a): array => [$a->document_type, $a->document_id, $a->event])->all(),
        );
        $this->assertSame([TelegramAlert::PENDING], TelegramAlert::query()->distinct()->pluck('status')->all());
    }

    #[Test]
    public function a_posting_that_fails_leaves_no_alert_behind(): void
    {
        $this->switchOn();

        try {
            DB::transaction(function (): void {
                $this->loan();

                throw new RuntimeException('Something later in the same posting failed.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Loan::count());
        $this->assertSame(0, TelegramAlert::count());
    }

    #[Test]
    public function each_alert_is_sent_once_to_every_chat_with_the_details(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->switchOn();
        $loan = $this->loan();

        $this->artisan('telegram:send-alerts')->assertSuccessful();
        $this->artisan('telegram:send-alerts')->assertSuccessful();

        Http::assertSentCount(2);
        // The second chat waits a moment, under Telegram's one-message-a-second limit.
        Sleep::assertSleptTimes(1);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '-100777'
            && str_contains($request['text'], $loan->loan_number)
            && str_contains($request['text'], 'People&#039;s Bank')
            && str_contains($request['text'], 'LKR 500,000.00')
            && str_contains($request['text'], 'Chathu')
            && str_contains($request['text'], route('loans.show', $loan)));

        $alert = TelegramAlert::sole();
        $this->assertSame(TelegramAlert::SENT, $alert->status);
        $this->assertSame(['-100777', '555111'], $alert->delivered_to);
    }

    #[Test]
    public function alerts_wait_while_telegram_is_unreachable_and_are_given_up_after_repeated_refusals(): void
    {
        $this->switchOn();
        $this->loan();

        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->pushFailedConnection()
            ->whenEmpty(Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400))]);

        $this->artisan('telegram:send-alerts')->assertFailed();
        $this->assertSame([TelegramAlert::PENDING, 0], [TelegramAlert::sole()->status, TelegramAlert::sole()->attempts]);

        for ($run = 1; $run <= TelegramAlert::MAX_ATTEMPTS; $run++) {
            $this->artisan('telegram:send-alerts');
        }

        $alert = TelegramAlert::sole();
        $this->assertSame(TelegramAlert::FAILED, $alert->status);
        $this->assertStringContainsString('chat not found', (string) $alert->last_error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $alert->last_error);
    }

    #[Test]
    public function when_telegram_asks_to_slow_down_the_alert_waits_without_counting_a_failure(): void
    {
        $this->switchOn();
        $this->loan();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]], 429)]);

        $this->artisan('telegram:send-alerts')->assertFailed();
        $this->artisan('telegram:send-alerts')->assertFailed();

        $alert = TelegramAlert::sole();
        $this->assertSame([TelegramAlert::PENDING, 0], [$alert->status, $alert->attempts]);
        $this->assertStringContainsString('slow down', (string) $alert->last_error);
    }

    #[Test]
    public function an_asset_on_a_loan_is_one_alert_and_its_sale_and_undoing_it_are_reported(): void
    {
        $this->switchOn();
        $bank = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();

        $lorry = app(RecordFixedAsset::class)->handle([
            'name' => 'Lorry', 'category' => 'VEHICLE', 'purchase_date' => '2026-09-02', 'purchase_price' => '1000000',
            'funding' => 'LOAN', 'down_payment' => '0', 'lender' => 'LB Finance',
        ], $this->admin->id);
        app(SellFixedAsset::class)->handle($lorry, [
            'sold_date' => '2026-09-20', 'sale_price' => '1100000', 'buyer' => 'A neighbour',
            'cash_bank_account_id' => $bank->id, 'payment_method' => 'BANK_TRANSFER',
        ]);
        app(ReverseAssetSale::class)->handle($lorry->fresh());

        $alerts = TelegramAlert::query()->orderBy('id')->get();
        $this->assertSame(['fixed_assets:posted', 'fixed_assets:sold', 'fixed_assets:sale_reversed'], $alerts->map(fn ($a): string => $a->document_type.':'.$a->event)->all());

        $messages = app(TransactionAlertMessage::class);
        $this->assertStringContainsString('LB Finance', (string) $messages->forAlert($alerts[0]));
        $this->assertStringContainsString('Profit on sale: <b>LKR 100,000.00</b>', (string) $messages->forAlert($alerts[1]));
        // The sale was cleared from the asset; the message still knows it from the alert.
        $undone = (string) $messages->forAlert($alerts[2]);
        $this->assertStringContainsString('A neighbour', $undone);
        $this->assertStringContainsString('LKR 1,100,000.00', $undone);
    }

    #[Test]
    public function every_kind_of_document_can_be_written_out_posted_and_reversed(): void
    {
        $messages = app(TransactionAlertMessage::class);

        foreach (TransactionAlertCatalog::MODELS as $class => $type) {
            /** @var Model $document */
            $document = $class::factory()->create();

            foreach (['posted', 'reversed'] as $event) {
                $text = $messages->forAlert(new TelegramAlert(['document_type' => $type, 'document_id' => $document->getKey(), 'event' => $event]));

                $this->assertIsString($text, "{$type} {$event}");
                $this->assertLessThan(4096, mb_strlen($text), $type);
                $this->assertStringContainsString('<a href="http', $text, $type);
            }
        }

        $this->assertNull($messages->forAlert(new TelegramAlert(['document_type' => 'loans', 'document_id' => 999999, 'event' => 'posted'])));
        $this->assertNotNull(FixedAsset::first());
    }
}
