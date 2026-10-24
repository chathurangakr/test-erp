<?php

namespace App\Mail;

use App\Models\CompanyProfile;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The monthly report sent on the 1st to everyone holding `reports.monthly_email`.
 *
 * Carries only figures already worked out by App\Services\Reporting\MonthlyUpdateReport; the
 * template formats them and calculates nothing beyond a change on the month before.
 * See docs/WEEKLY_UPDATE.md.
 */
class MonthlyUpdate extends Mailable
{
    /**
     * @param  array<string, mixed>  $report  MonthlyUpdateReport::build()
     */
    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Monthly report: :month', ['month' => $this->monthName()]),
        );
    }

    public function content(): Content
    {
        $company = CompanyProfile::current();

        return new Content(
            view: 'mail.monthly-update',
            text: 'mail.monthly-update-text',
            with: [
                'companyName' => $company->name,
                'logo' => $company->logoImage(),
                'monthName' => $this->monthName(),
                'appName' => config('app.name'),
            ],
        );
    }

    /** "October 2026". */
    private function monthName(): string
    {
        return CarbonImmutable::parse($this->report['from'])->translatedFormat('F Y');
    }
}
