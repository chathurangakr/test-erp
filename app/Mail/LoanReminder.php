<?php

namespace App\Mail;

use App\Models\CompanyProfile;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The loan installments falling due soon, sent by `loans:remind` 7 days before, 2 days before
 * and on the due day. Carries only figures App\Services\Loans\LoanBalances worked out.
 */
class LoanReminder extends Mailable
{
    /**
     * @param  list<array{loan_number: string, lender: string, due_date: string, days_before: int, installment: string|null, outstanding: string, url: string}>  $items
     */
    public function __construct(public array $items) {}

    public function envelope(): Envelope
    {
        $first = $this->items[0];

        return new Envelope(
            subject: count($this->items) === 1
                ? __('Loan installment due :when: :lender', ['when' => $this->dueIn($first['days_before']), 'lender' => $first['lender']])
                : __(':count loan installments due soon', ['count' => count($this->items)]),
        );
    }

    public function content(): Content
    {
        $company = CompanyProfile::current();

        return new Content(
            view: 'mail.loan-reminder',
            text: 'mail.loan-reminder-text',
            with: [
                'companyName' => $company->name,
                'items' => array_map(fn (array $item): array => $item + ['when' => $this->dueIn($item['days_before'])], $this->items),
            ],
        );
    }

    private function dueIn(int $daysBefore): string
    {
        return $daysBefore === 0 ? __('today') : __('in :days days', ['days' => $daysBefore]);
    }
}
