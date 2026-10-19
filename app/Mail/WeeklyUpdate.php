<?php

namespace App\Mail;

use App\Models\CompanyProfile;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The Sunday-evening summary sent to everyone holding `reports.weekly_email`.
 *
 * Carries only figures already worked out by App\Services\Reporting\WeeklyUpdateReport; the
 * template formats them and calculates nothing. See docs/WEEKLY_UPDATE.md.
 */
class WeeklyUpdate extends Mailable
{
    /**
     * @param  array<string, mixed>  $report  WeeklyUpdateReport::build()
     */
    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Weekly update: :range', ['range' => $this->range()]),
        );
    }

    public function content(): Content
    {
        $company = CompanyProfile::current();

        return new Content(
            view: 'mail.weekly-update',
            text: 'mail.weekly-update-text',
            with: [
                'companyName' => $company->name,
                'logo' => $company->logoImage(),
                'range' => $this->range(),
                'appName' => config('app.name'),
            ],
        );
    }

    /** "13 Oct – 19 Oct 2026". */
    private function range(): string
    {
        $from = CarbonImmutable::parse($this->report['from']);
        $to = CarbonImmutable::parse($this->report['to']);

        return $from->format('j M').' – '.$to->format('j M Y');
    }
}
