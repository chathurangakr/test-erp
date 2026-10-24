<?php

namespace Tests\Feature\Reports;

use App\Enums\CustomerReceiptStatus;
use App\Enums\Permission;
use App\Enums\SalesInvoiceStatus;
use App\Mail\MonthlyUpdate;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\LoanPayment;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Reporting\MonthlyUpdateReport;
use App\Services\Telegram\MonthlyUpdateMessage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The monthly report on the 1st (2026-10-25): last month's figures by email to everyone holding
 * reports.monthly_email and to the Telegram bots set to receive it.
 */
class MonthlyUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function bot(string $purpose, string $chat, bool $monthly): void
    {
        TelegramSetting::create([
            'purpose' => $purpose, 'is_enabled' => true, 'bot_token' => '111:Token'.$purpose,
            'chats' => [['id' => $chat, 'title' => $purpose]], 'sends_monthly_report' => $monthly,
        ]);
    }

    #[Test]
    public function on_the_first_it_reports_last_month_to_permission_holders_and_chosen_bots(): void
    {
        Mail::fake();
        Sleep::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $admin = User::factory()->admin()->create(['email' => 'owner@example.test']);
        $granted = User::factory()->withPermissions(Permission::ReportsMonthlyEmail)->create(['email' => 'accountant@example.test']);
        User::factory()->admin()->create(['email' => 'former@example.test', 'is_active' => false]);
        User::factory()->withPermissions(Permission::ReportsWeeklyEmail)->create(['email' => 'weekly-only@example.test']);
        $this->bot(TelegramSetting::REMINDERS, '555111', true);
        $this->bot(TelegramSetting::TRANSACTIONS, '-100777', false);

        $this->travelTo(Carbon::parse('2026-10-01 08:00', 'Asia/Colombo'));
        $this->artisan('reports:monthly-update')->assertSuccessful();

        Mail::assertSentCount(2);
        Mail::assertSent(MonthlyUpdate::class, fn (MonthlyUpdate $mail) => $mail->hasTo($admin->email)
            && $mail->report['from'] === '2026-09-01' && $mail->report['to'] === '2026-09-30');
        Mail::assertSent(MonthlyUpdate::class, fn (MonthlyUpdate $mail) => $mail->hasTo($granted->email));

        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '555111' && str_contains($request['text'], 'Monthly report — September 2026'));
        Http::assertNotSent(fn (Request $request): bool => $request['chat_id'] === '-100777');
    }

    #[Test]
    public function a_test_copy_goes_to_one_address_and_nowhere_else_and_any_month_can_be_chosen(): void
    {
        Mail::fake();
        Http::fake();
        $this->bot(TelegramSetting::REMINDERS, '555111', true);

        $this->artisan('reports:monthly-update', ['--to' => 'test@example.test', '--month' => '2026-08'])->assertSuccessful();
        $this->artisan('reports:monthly-update', ['--month' => 'August'])->assertExitCode(2);

        Mail::assertSentCount(1);
        Mail::assertSent(MonthlyUpdate::class, fn (MonthlyUpdate $mail) => $mail->hasTo('test@example.test') && $mail->report['from'] === '2026-08-01');
        Http::assertNothingSent();
    }

    #[Test]
    public function the_figures_count_only_standing_documents_of_the_month(): void
    {
        $customer = Customer::factory()->create(['name' => 'Lanka Copra Mills']);
        $invoice = SalesInvoice::factory()->create(['customer_id' => $customer->id, 'invoice_date' => '2026-09-10', 'status' => SalesInvoiceStatus::Posted]);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $invoice->id, 'amount' => '150000']);
        $reversed = SalesInvoice::factory()->create(['customer_id' => $customer->id, 'invoice_date' => '2026-09-11', 'status' => SalesInvoiceStatus::Reversed]);
        SalesInvoiceLine::factory()->create(['sales_invoice_id' => $reversed->id, 'amount' => '99999']);
        CustomerReceipt::factory()->create(['receipt_date' => '2026-09-12', 'amount' => '40000', 'status' => CustomerReceiptStatus::Posted]);
        CustomerReceipt::factory()->create(['receipt_date' => '2026-10-02', 'amount' => '70000', 'status' => CustomerReceiptStatus::Posted]);
        CustomerReceipt::factory()->create(['receipt_date' => '2026-09-13', 'amount' => '5000', 'status' => CustomerReceiptStatus::Draft]);
        LoanPayment::factory()->create(['payment_date' => '2026-09-15', 'amount' => '25000', 'interest_amount' => '3000', 'principal_amount' => '22000']);

        $report = app(MonthlyUpdateReport::class)->build(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(['invoices' => 1, 'total' => '150000.000000', 'customers' => [['name' => 'Lanka Copra Mills', 'amount' => '150000.000000']]], $report['sales']);
        $this->assertSame('40000.000000', $report['money']['from_customers']);
        $this->assertSame('25000.000000', $report['money']['loan_payments']);
        $this->assertSame('3000.000000', $report['money']['loan_interest']);
        $this->assertArrayHasKey('previous', $report);
    }

    #[Test]
    public function the_email_and_the_telegram_message_render_every_section(): void
    {
        $report = app(MonthlyUpdateReport::class)->build(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $html = (new MonthlyUpdate($report))->render();

        foreach (['Monthly report', 'September 2026', 'Sales &amp; profit', 'Sales by customer', 'Goods bought by supplier', 'Money in &amp; out', 'Expenses by account', 'Cash &amp; bank', 'Money owed', 'Loans still owed', 'Production &amp; stock', 'Returns &amp; damage'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $messages = MonthlyUpdateMessage::forTelegram($report, 'Test Mills');

        $this->assertStringStartsWith('📊 <b>Monthly report — September 2026</b>', $messages[0]);
        foreach ($messages as $message) {
            $this->assertLessThan(4096, mb_strlen($message));
        }
    }

    #[Test]
    public function it_is_scheduled_for_the_first_at_8_am_sri_lanka_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'reports:monthly-update'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 1 * *', $event->expression);
        $this->assertSame('Asia/Colombo', $event->timezone);
    }
}
