<?php

namespace App\Console\Commands;

use App\Enums\LoanDocumentStatus;
use App\Enums\Permission;
use App\Mail\LoanReminder;
use App\Models\Loan;
use App\Models\LoanReminder as SentReminder;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Loans\LoanBalances;
use App\Services\Telegram\LoanReminderMessage;
use App\Services\Telegram\TelegramBot;
use App\Support\BusinessClock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the loan installments falling due — 7 days before, 2 days before and on the due day — by
 * email to every active user holding `loans.reminders` (administrators always do), and to the
 * Telegram chats chosen on Administration → Telegram settings when that is switched on. An installment
 * already recorded is not reminded about, and each reminder is sent once (loan_reminders), so
 * the daily run can be repeated safely. See docs/LOANS.md.
 */
#[Signature('loans:remind')]
#[Description('Email reminders for loan installments due in 7 days, 2 days and today')]
class SendLoanRemindersCommand extends Command
{
    /** The days before a due date a reminder goes out. */
    public const DAYS_BEFORE = [7, 2, 0];

    public function handle(LoanBalances $balances, TelegramBot $bot): int
    {
        $today = BusinessClock::today();
        $due = [];

        foreach (Loan::query()->where('status', LoanDocumentStatus::Posted->value)->whereNotNull('due_day')->orderBy('lender')->get() as $loan) {
            $date = $balances->nextDueDate($loan, $today);
            $daysBefore = (int) $today->diffInDays($date);

            if (! in_array($daysBefore, self::DAYS_BEFORE, true)
                || $balances->isPaidOff($loan)
                || $balances->isInstallmentPaid($loan, $date)
                || SentReminder::query()->where('loan_id', $loan->id)->whereOnDay('due_date', $date->toDateString())->where('days_before', $daysBefore)->exists()) {
                continue;
            }

            $due[] = [
                'loan' => $loan,
                'due_date' => $date->toDateString(),
                'days_before' => $daysBefore,
                'item' => [
                    'loan_number' => $loan->loan_number,
                    'lender' => $loan->lender,
                    'due_date' => $date->toDateString(),
                    'days_before' => $daysBefore,
                    'installment' => $loan->installment_amount === null ? null : (string) $loan->installment_amount,
                    'outstanding' => $balances->outstanding($loan),
                    'url' => route('loans.show', $loan),
                ],
            ];
        }

        if ($due === []) {
            $this->info('No loan installments to remind about today.');

            return self::SUCCESS;
        }

        $recipients = User::query()->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $user->hasPermission(Permission::LoansReminders));
        $telegram = TelegramSetting::current();

        if ($recipients->isEmpty() && ! $telegram->isReady()) {
            $this->warn('Nobody receives loan reminders (no active user holds loans.reminders, and Telegram is off).');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $user) {
            try {
                Mail::to($user)->locale($user->locale ?: config('app.locale'))->send(new LoanReminder(array_column($due, 'item')));
                $sent++;
                $this->info("Sent to {$user->email}");
            } catch (Throwable $exception) {
                // One bad address must not stop the others; the failure is reported and logged.
                $failed++;
                report($exception);
                $this->error("Could not send to {$user->email}: {$exception->getMessage()}");
            }
        }

        if ($telegram->isReady()) {
            $message = LoanReminderMessage::forTelegram(array_column($due, 'item'));

            foreach ($telegram->chats as $chat) {
                try {
                    $bot->send($telegram->bot_token, $chat['id'], $message);
                    $sent++;
                    $this->info("Sent to Telegram chat {$chat['title']}");
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("Could not send to Telegram chat {$chat['title']}: {$exception->getMessage()}");
                }
            }
        }

        // Remembered only once someone actually received it, so a failed run tries again.
        if ($sent > 0) {
            foreach ($due as $reminder) {
                SentReminder::create([
                    'loan_id' => $reminder['loan']->id,
                    'due_date' => $reminder['due_date'],
                    'days_before' => $reminder['days_before'],
                    'sent_at' => now(),
                ]);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
