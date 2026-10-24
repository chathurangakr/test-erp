<?php

namespace Tests\Feature\Administration;

use App\Actions\Loans\RecordLoan;
use App\Enums\Permission;
use App\Models\FiscalPeriod;
use App\Models\LoanReminder;
use App\Models\TelegramSetting;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telegram settings (2026-10-25): the bot token is stored encrypted and never sent back, the
 * chats are found from who messaged the bot, and loan reminders go to those chats too.
 */
class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAFakeTokenForTests_x-y';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        Http::fake([
            'api.telegram.org/bot'.self::TOKEN.'/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'TrueLineRemindersBot']]),
            'api.telegram.org/bot'.self::TOKEN.'/getUpdates' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 1, 'message' => ['chat' => ['id' => 555111, 'type' => 'private', 'first_name' => 'Chathu']]],
                ['update_id' => 2, 'message' => ['chat' => ['id' => -100777, 'type' => 'group', 'title' => 'Factory owners']]],
            ]]),
            'api.telegram.org/bot'.self::TOKEN.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]),
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);
    }

    private function save(array $data): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('telegram-settings.update'), $data);
    }

    #[Test]
    public function the_token_is_checked_stored_encrypted_and_never_shown_again(): void
    {
        $this->save(['bot_token' => self::TOKEN])->assertSessionHasNoErrors();

        $settings = TelegramSetting::current();
        $this->assertSame('TrueLineRemindersBot', $settings->bot_username);
        $this->assertSame(self::TOKEN, $settings->bot_token);
        $this->assertNotSame(self::TOKEN, DB::table('telegram_settings')->value('bot_token'));

        $this->actingAs($this->admin)->get(route('telegram-settings.edit'))
            ->assertOk()
            ->assertDontSee(self::TOKEN)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.has_token', true)->where('settings.bot_username', 'TrueLineRemindersBot'));

        // A token Telegram refuses is not saved, and the error never repeats the token.
        $this->save(['bot_token' => '999:WrongToken'])->assertSessionHasErrors('bot_token');
        $this->assertSame(self::TOKEN, TelegramSetting::current()->bot_token);
        $this->assertStringNotContainsString('999:WrongToken', session('errors')->first('bot_token'));
    }

    #[Test]
    public function chats_are_found_from_who_messaged_the_bot_and_telegram_needs_one_to_switch_on(): void
    {
        $this->save(['bot_token' => self::TOKEN]);

        $this->actingAs($this->admin)->from(route('telegram-settings.edit'))->post(route('telegram-settings.find-chats'))->assertRedirect();
        $this->actingAs($this->admin)->get(route('telegram-settings.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('foundChats.0.title', 'Factory owners')
                ->where('foundChats.1.title', 'Chathu'));

        $this->save(['is_enabled' => true, 'chats' => []])->assertSessionHasErrors('is_enabled');

        $this->save(['is_enabled' => true, 'chats' => [['id' => '-100777', 'title' => 'Factory owners']]])->assertSessionHasNoErrors();
        $this->assertTrue(TelegramSetting::current()->isReady());

        $this->actingAs($this->admin)->post(route('telegram-settings.test'))->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '-100777');
    }

    #[Test]
    public function loan_reminders_go_to_the_chosen_telegram_chats_once(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(RecordLoan::class)->handle(['lender' => 'People\'s Bank', 'start_date' => '2026-09-01', 'funding' => 'EXISTING', 'amount' => '500000', 'installment_amount' => '25000', 'due_day' => 25], $this->admin->id);
        $this->save(['bot_token' => self::TOKEN, 'is_enabled' => true, 'chats' => [['id' => '555111', 'title' => 'Chathu']]]);

        $this->travelTo(Carbon::parse('2026-09-23 03:00:00', 'UTC'));
        $this->artisan('loans:remind')->assertSuccessful();
        $this->artisan('loans:remind')->assertSuccessful();

        Http::assertSentCount(2); // getMe when saving, then one reminder
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '555111'
            && str_contains($request['text'], 'People&#039;s Bank'));
        $this->assertSame(1, LoanReminder::count());
    }

    #[Test]
    public function only_someone_holding_telegram_manage_may_change_it(): void
    {
        $user = User::factory()->withPermissions(Permission::MailManage)->create();

        $this->actingAs($user)->get(route('telegram-settings.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('telegram-settings.update'), ['bot_token' => self::TOKEN])->assertForbidden();
    }
}
