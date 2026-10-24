<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateTelegramSettingsRequest;
use App\Models\CompanyProfile;
use App\Models\TelegramAlert;
use App\Models\TelegramSetting;
use App\Services\Reporting\MonthlyUpdateReport;
use App\Services\Telegram\MonthlyUpdateMessage;
use App\Services\Telegram\TelegramAnswers;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TransactionAlertCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Telegram settings — two bots, each with its own token, chats and test message: the reminders
 * bot (loan reminders) and the transactions bot (a message for every posting and reversal, with
 * a choice of which kinds). Either may also get the monthly report. A saved token never reaches
 * the screen: the page only learns whether one is saved and the bot's @username.
 * See docs/TELEGRAM.md.
 */
class TelegramSettingsController extends Controller
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('administration/telegram/Edit', [
            'bots' => collect(TelegramSetting::PURPOSES)
                ->mapWithKeys(fn (string $purpose): array => [$purpose => $this->present(TelegramSetting::current($purpose))]),
            'alertTypes' => collect(TransactionAlertCatalog::labels())
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            'alertQueue' => [
                'pending' => TelegramAlert::query()->where('status', TelegramAlert::PENDING)->count(),
                'failed' => TelegramAlert::query()->where('status', TelegramAlert::FAILED)->count(),
            ],
            // Chats that have written to a bot, found with "Find chats" just now: {bot, chats}.
            'foundChats' => $request->session()->get('telegram_found_chats'),
        ]);
    }

    public function update(UpdateTelegramSettingsRequest $request): RedirectResponse
    {
        $settings = TelegramSetting::query()->firstOrNew(
            ['purpose' => $request->bot()],
            // A new reminders bot gets the monthly report unless told otherwise.
            ['sends_monthly_report' => $request->bot() === TelegramSetting::REMINDERS],
        );
        $token = $request->validated('bot_token');

        if (filled($token)) {
            $settings->bot_token = $token;
            $settings->bot_username = $this->check(fn (): string => $this->bot->username($token), 'bot_token');
            // Commands belonged to the old bot; they are switched on again for the new one.
            $settings->commands_enabled = false;
            $settings->webhook_secret = null;
        }

        $settings->chats = array_values($request->validated('chats') ?? []);
        $settings->is_enabled = (bool) $request->validated('is_enabled', false);

        if ($request->has('sends_monthly_report')) {
            $settings->sends_monthly_report = (bool) $request->validated('sends_monthly_report');
        }

        if ($settings->purpose === TelegramSetting::TRANSACTIONS && $request->has('alert_types')) {
            $settings->alert_types = array_values($request->validated('alert_types') ?? []);
        }

        if ($settings->is_enabled && ($settings->bot_token === null || $settings->chats === [])) {
            throw ValidationException::withMessages(['is_enabled' => __('Save the bot token and choose at least one chat before switching Telegram on.')]);
        }

        $settings->updated_by = $request->user()->id;
        $settings->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram settings saved.')]);

        return to_route('telegram-settings.edit');
    }

    /**
     * Lists the private chats and groups that have messaged a bot, so they can be chosen.
     */
    public function findChats(Request $request): RedirectResponse
    {
        $bot = $this->purpose($request);
        $settings = TelegramSetting::current($bot);
        $token = $settings->bot_token
            ?? throw ValidationException::withMessages([$bot.'_find' => __('Save the bot token first.')]);

        // While Telegram sends this bot's messages here it will not list them; we kept our own.
        $chats = $settings->commands_enabled
            ? ($settings->seen_chats ?? [])
            : $this->check(fn (): array => $this->bot->recentChats($token), $bot.'_find');

        if ($chats === []) {
            throw ValidationException::withMessages([$bot.'_find' => __('Nobody has messaged the bot yet. Send it any message in Telegram, then try again.')]);
        }

        return back()->with('telegram_found_chats', ['bot' => $bot, 'chats' => $chats]);
    }

    /**
     * Sends a short test message to every chat a bot has chosen.
     */
    public function test(Request $request): RedirectResponse
    {
        $settings = $this->usable($this->purpose($request), 'test');
        $field = $settings->purpose.'_test';
        $text = $settings->purpose === TelegramSetting::TRANSACTIONS
            ? __(':app will send a message here for every transaction.', ['app' => config('app.name')])
            : __(':app will send loan reminders here.', ['app' => config('app.name')]);

        foreach ($settings->chats as $chat) {
            $this->check(fn () => $this->bot->send((string) $settings->bot_token, $chat['id'], '✅ '.e($text)), $field);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test message sent to :count chat(s).', ['count' => count($settings->chats)])]);

        return back();
    }

    /**
     * Switches the transactions bot's commands (/stock, /cash, /today, /owed) on or off. On: a
     * new secret, Telegram told to send the bot's messages to this server with it, and the command
     * list set (English and Sinhala). This server must be reachable from the internet over HTTPS.
     */
    public function commands(Request $request): RedirectResponse
    {
        $enable = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $settings = TelegramSetting::current(TelegramSetting::TRANSACTIONS);
        $token = $settings->bot_token
            ?? throw ValidationException::withMessages(['transactions_commands' => __('Save the bot token first.')]);

        if ($enable && ! $this->reachableFromTheInternet(route('telegram.webhook'))) {
            throw ValidationException::withMessages(['transactions_commands' => __('Telegram cannot reach this computer (:address is not on the internet). Switch commands on from the cloud server instead.', [
                'address' => parse_url(route('telegram.webhook'), PHP_URL_HOST),
            ])]);
        }

        if ($enable) {
            $secret = Str::random(48);
            $this->check(fn () => $this->bot->setWebhook($token, route('telegram.webhook'), $secret), 'transactions_commands');
            $this->check(fn () => $this->bot->setCommands($token, TelegramAnswers::menu()), 'transactions_commands');
            $this->check(fn () => $this->bot->setCommands($token, $this->translatedMenu('si'), 'si'), 'transactions_commands');
            $settings->forceFill(['commands_enabled' => true, 'webhook_secret' => $secret])->save();
        } else {
            $this->check(fn () => $this->bot->deleteWebhook($token), 'transactions_commands');
            $settings->forceFill(['commands_enabled' => false, 'webhook_secret' => null])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $enable ? __('Commands are on. Type / in a private chat with the bot.') : __('Commands are off.')]);

        return back();
    }

    /**
     * Sends last month's report to a bot's chats now, to see what arrives on the 1st.
     */
    public function monthly(Request $request, MonthlyUpdateReport $reports): RedirectResponse
    {
        $settings = $this->usable($this->purpose($request), 'monthly');
        $field = $settings->purpose.'_monthly';
        [$from, $to] = MonthlyUpdateReport::lastMonth();
        $messages = MonthlyUpdateMessage::forTelegram($reports->build($from, $to), CompanyProfile::current()->name);

        foreach ($settings->chats as $chat) {
            foreach ($messages as $i => $text) {
                // A report in several parts stays under Telegram's one-message-a-second limit.
                if ($i > 0) {
                    Sleep::for(1100)->milliseconds();
                }

                $this->check(fn () => $this->bot->send((string) $settings->bot_token, $chat['id'], $text), $field);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Last month\'s report sent to :count chat(s).', ['count' => count($settings->chats)])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TelegramSetting $settings): array
    {
        return [
            'is_enabled' => $settings->is_enabled,
            'has_token' => $settings->bot_token !== null,
            'bot_username' => $settings->bot_username,
            'chats' => $settings->chats ?? [],
            // No choice saved yet means every kind.
            'alert_types' => $settings->alert_types ?? array_keys(TransactionAlertCatalog::labels()),
            'sends_monthly_report' => $settings->sends_monthly_report,
            'commands_enabled' => $settings->commands_enabled,
        ];
    }

    /**
     * Whether Telegram could deliver to this address: not localhost, and not a private or reserved
     * IP (a LAN computer). A name that does not resolve here is left for Telegram to judge.
     */
    private function reachableFromTheInternet(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP)
            || (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * @return array<string, string>
     */
    private function translatedMenu(string $locale): array
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        $menu = TelegramAnswers::menu();
        app()->setLocale($previous);

        return $menu;
    }

    private function purpose(Request $request): string
    {
        return (string) ($request->validate(['bot' => ['sometimes', Rule::in(TelegramSetting::PURPOSES)]])['bot'] ?? TelegramSetting::REMINDERS);
    }

    /** A bot that has a token and chats; otherwise an error under "{bot}_{action}". */
    private function usable(string $purpose, string $action): TelegramSetting
    {
        $settings = TelegramSetting::current($purpose);

        if ($settings->bot_token === null || ($settings->chats ?? []) === []) {
            throw ValidationException::withMessages([$purpose.'_'.$action => __('Save the bot token and choose at least one chat first.')]);
        }

        return $settings;
    }

    /**
     * Runs a Telegram call, turning its failure into a message beside the field.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function check(callable $call, string $field): mixed
    {
        try {
            return $call();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
