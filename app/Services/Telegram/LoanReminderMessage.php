<?php

namespace App\Services\Telegram;

use App\Support\DisplayNumber;

/**
 * The loan reminder as a Telegram message: short, one block per installment, with a link to
 * record the payment. The same figures as the reminder email (App\Mail\LoanReminder).
 */
class LoanReminderMessage
{
    /**
     * @param  list<array{loan_number: string, lender: string, due_date: string, days_before: int, installment: string|null, outstanding: string, url: string}>  $items
     */
    public static function forTelegram(array $items): string
    {
        $currency = config('accounting.base_currency');
        $lines = ['<b>'.e(__('Loan installments due')).'</b>'];

        foreach ($items as $item) {
            $when = $item['days_before'] === 0 ? __('today') : __('in :days days', ['days' => $item['days_before']]);
            $icon = $item['days_before'] === 0 ? '🔴' : ($item['days_before'] <= 2 ? '🟠' : '🔵');

            $lines[] = '';
            $lines[] = $icon.' <b>'.e($item['lender']).'</b> — '.e(__('Due :when — :date', ['when' => $when, 'date' => $item['due_date']]));

            if ($item['installment'] !== null) {
                $lines[] = e(__('Usual installment')).': '.e($currency.' '.DisplayNumber::money($item['installment']));
            }

            $lines[] = e(__('Still owed')).': '.e($currency.' '.DisplayNumber::money($item['outstanding']));
            $lines[] = '<a href="'.e($item['url']).'">'.e(__('Record the payment')).'</a>';
        }

        return implode("\n", $lines);
    }
}
