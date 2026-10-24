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
     * Not public on purpose: a public property is handed to the view as-is and would replace
     * the `items` (with their "due in" wording) built in content().
     *
     * @param  list<array{loan_number: string, lender: string, due_date: string, days_before: int, installment: string|null, outstanding: string, url: string}>  $installments
     */
    public function __construct(private readonly array $installments) {}

    public function envelope(): Envelope
    {
        $first = $this->installments[0];

        return new Envelope(
            subject: count($this->installments) === 1
                ? __('Loan installment due :when: :lender', ['when' => $this->dueIn($first['days_before']), 'lender' => $first['lender']])
                : __(':count loan installments due soon', ['count' => count($this->installments)]),
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
                'items' => array_map(fn (array $item): array => $item + ['when' => $this->dueIn($item['days_before'])], $this->installments),
            ],
        );
    }

    private function dueIn(int $daysBefore): string
    {
        return $daysBefore === 0 ? __('today') : __('in :days days', ['days' => $daysBefore]);
    }
}
