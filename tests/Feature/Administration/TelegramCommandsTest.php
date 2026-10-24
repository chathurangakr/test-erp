<?php

namespace Tests\Feature\Administration;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Models\Product;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telegram commands (2026-10-25): linked people ask the transactions bot for /stock, /cash,
 * /today and /owed in a private chat, and see only what their ERP permissions allow.
 */
class TelegramCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '987654321:AAFakeTransactionsBot_x';

    private const SECRET = 'a-webhook-secret-only-telegram-knows-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        Sleep::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        TelegramSetting::create([
            'purpose' => TelegramSetting::TRANSACTIONS, 'bot_token' => self::TOKEN, 'bot_username' => 'FactoryTransactionsBot',
            'commands_enabled' => true, 'webhook_secret' => self::SECRET, 'chats' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function webhook(array $update, ?string $secret = null): TestResponse
    {
        $secret ??= (string) TelegramSetting::current(TelegramSetting::TRANSACTIONS)->webhook_secret;

        return $this->postJson(route('telegram.webhook'), $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    private function say(string $text, int $from = 4242, string $chatType = 'private'): TestResponse
    {
        $chat = $chatType === 'private' ? ['id' => $from, 'type' => 'private', 'first_name' => 'Kamal'] : ['id' => -100777, 'type' => $chatType, 'title' => 'Factory owners'];

        return $this->webhook(['update_id' => 1, 'message' => ['message_id' => 1, 'from' => ['id' => $from, 'username' => 'kamal'], 'chat' => $chat, 'text' => $text]]);
    }

    /** The text of the last message the bot sent. */
    private function lastReply(): string
    {
        $sent = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));

        return (string) $sent->last()?->data()['text'];
    }

    private function linked(Permission ...$permissions): User
    {
        return User::factory()->withPermissions(...$permissions)->create(['telegram_user_id' => '4242', 'name' => 'Kamal']);
    }

    #[Test]
    public function only_telegram_with_the_secret_is_listened_to(): void
    {
        $this->webhook(['update_id' => 1], 'wrong-secret')->assertForbidden();
        $this->postJson(route('telegram.webhook'), ['update_id' => 1])->assertForbidden();
        $this->webhook(['update_id' => 1])->assertOk();

        TelegramSetting::query()->update(['commands_enabled' => false]);
        $this->webhook(['update_id' => 1])->assertForbidden();
    }

    #[Test]
    public function a_person_links_their_telegram_with_a_one_time_code(): void
    {
        $user = User::factory()->withPermissions(Permission::CashView)->create(['email' => 'kamal@example.test']);

        $location = $this->actingAs($user)->post(route('telegram-link.store'))->headers->get('Location');
        $this->assertStringStartsWith('https://t.me/FactoryTransactionsBot?start=', (string) $location);
        $code = substr((string) $location, strlen('https://t.me/FactoryTransactionsBot?start='));

        $this->say('/start '.$code)->assertOk();

        $this->assertSame('4242', $user->fresh()->telegram_user_id);
        $this->assertSame('kamal', $user->fresh()->telegram_username);
        $this->assertStringContainsString('kamal@example.test', $this->lastReply());
        $this->assertStringContainsString('/cash', $this->lastReply());
        $this->assertStringNotContainsString('/stock', $this->lastReply(), 'Only the commands they may use are listed.');

        // A code works once.
        $this->say('/start '.$code, 5555);
        $this->assertStringContainsString('expired or was already used', $this->lastReply());

        $this->actingAs($user)->delete(route('telegram-link.destroy'))->assertRedirect();
        $this->assertNull($user->fresh()->telegram_user_id);
    }

    #[Test]
    public function strangers_groups_and_deactivated_people_get_no_figures(): void
    {
        $this->say('/cash');
        $this->assertStringContainsString('know you yet', $this->lastReply());

        $user = $this->linked(Permission::CashView);
        $this->say('/cash', 4242, 'group');
        $this->assertStringContainsString('private chat', $this->lastReply());
        $this->assertStringNotContainsString('Cash on Hand', $this->lastReply());

        $user->forceFill(['is_active' => false])->save();
        $this->say('/cash');
        $this->assertStringContainsString('know you yet', $this->lastReply());
    }

    #[Test]
    public function each_answer_follows_the_persons_permissions(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'MAIN']);
        $product = Product::factory()->create(['name' => 'Coconut (large)']);
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(productId: $product->id, warehouseId: $warehouse->id, direction: InventoryDirection::In, quantity: '1250.000', unitCost: '80.000000'),
        ]);
        $this->linked(Permission::InventoryView);

        $this->say('/stock');
        $this->assertStringContainsString('Coconut (large): <b>1,250', $this->lastReply());
        $this->assertStringNotContainsString('100,000.00', $this->lastReply(), 'No values without the inventory report permission.');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && ($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'stock:'.$product->id);

        $this->webhook(['update_id' => 2, 'callback_query' => [
            'id' => 'cb1', 'from' => ['id' => 4242], 'data' => 'stock:'.$product->id,
            'message' => ['message_id' => 2, 'chat' => ['id' => 4242, 'type' => 'private']],
        ]])->assertOk();
        $this->assertStringContainsString('MAIN: <b>1,250', $this->lastReply());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery'));

        $this->say('/cash');
        $this->assertStringContainsString('not allowed', $this->lastReply());

        $this->say('/today');
        $this->assertStringContainsString('not allowed', $this->lastReply());
    }

    #[Test]
    public function cash_today_and_owed_answer_for_someone_who_may_see_them(): void
    {
        $this->linked(Permission::CashView, Permission::ArView, Permission::ApView, Permission::LoansView);

        $this->say('/cash');
        $this->assertStringContainsString('Cash on Hand', $this->lastReply());

        $this->say('/today');
        $this->assertStringContainsString('Received from customers', $this->lastReply());
        $this->assertStringNotContainsString('Production', $this->lastReply());

        $this->say('/owed@FactoryTransactionsBot');
        $this->assertStringContainsString('Customers owe us', $this->lastReply());
        $this->assertStringContainsString('Loans still owed', $this->lastReply());

        $this->say('/whatever');
        $this->assertStringContainsString('/owed', $this->lastReply());
    }

    #[Test]
    public function an_administrator_switches_commands_on_and_off(): void
    {
        TelegramSetting::query()->update(['commands_enabled' => false, 'webhook_secret' => null]);
        $admin = User::factory()->admin()->create();

        // A computer on its own or on a LAN cannot be reached by Telegram: said plainly, nothing sent.
        URL::forceRootUrl('https://localhost:8443');
        $this->actingAs($admin)->post(route('telegram-settings.commands'), ['enabled' => true])->assertSessionHasErrors('transactions_commands');
        URL::forceRootUrl('https://192.168.1.20');
        $this->actingAs($admin)->post(route('telegram-settings.commands'), ['enabled' => true])->assertSessionHasErrors('transactions_commands');
        Http::assertNothingSent();
        URL::forceRootUrl('https://erp.example.test');

        $this->actingAs($admin)->post(route('telegram-settings.commands'), ['enabled' => true])->assertSessionHasNoErrors();

        $settings = TelegramSetting::current(TelegramSetting::TRANSACTIONS);
        $this->assertTrue($settings->answersCommands());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === route('telegram.webhook')
            && $request['secret_token'] === $settings->webhook_secret);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands') && ($request['language_code'] ?? null) === 'si');

        // While Telegram sends here, Find chats uses the chats seen here, not getUpdates.
        $this->say('/start');
        $this->actingAs($admin)->post(route('telegram-settings.find-chats'), ['bot' => 'transactions'])->assertSessionHasNoErrors();
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/getUpdates'));
        $this->actingAs($admin)->get(route('telegram-settings.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('foundChats.chats.0.id', '4242')->where('bots.transactions.commands_enabled', true));

        $this->actingAs($admin)->get(route('profile.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('telegram.available', true)->where('telegram.linked', false));

        $this->actingAs($admin)->post(route('telegram-settings.commands'), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse(TelegramSetting::current(TelegramSetting::TRANSACTIONS)->answersCommands());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteWebhook'));
    }
}
