<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\UpdateMailSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateMailSettingsRequest;
use App\Mail\WeeklyUpdate;
use App\Models\MailSetting;
use App\Services\Reporting\WeeklyUpdateReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Email settings — the account the ERP sends from (the weekly update), and a test send.
 *
 * The saved password never reaches the screen: the page only learns whether one is saved.
 */
class MailSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $settings = MailSetting::current();

        return Inertia::render('administration/mail/Edit', [
            'settings' => [
                'is_enabled' => $settings->is_enabled,
                'host' => $settings->host,
                'port' => $settings->port,
                'encryption' => $settings->encryption,
                'username' => $settings->username,
                'has_password' => $settings->password !== null,
                'from_address' => $settings->from_address,
                'from_name' => $settings->from_name,
            ],
            'encryptions' => MailSetting::ENCRYPTIONS,
            'testAddress' => $request->user()->email,
        ]);
    }

    public function update(UpdateMailSettingsRequest $request, UpdateMailSettings $updateSettings): RedirectResponse
    {
        $updateSettings->handle($request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email settings saved.')]);

        return to_route('mail-settings.edit');
    }

    /**
     * Sends this week's update to the signed-in user, through the saved settings.
     */
    public function test(Request $request, WeeklyUpdateReport $reports): RedirectResponse
    {
        [$from, $to] = WeeklyUpdateReport::lastWeek();

        try {
            Mail::to($request->user())
                ->locale($request->user()->locale ?: config('app.locale'))
                ->send(new WeeklyUpdate($reports->build($from, $to)));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['test' => __('The email could not be sent: :reason', ['reason' => $exception->getMessage()])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test email sent to :address.', ['address' => $request->user()->email])]);

        return back();
    }
}
