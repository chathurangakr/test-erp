<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\UpdateCompanyProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\UpdateCompanyProfileRequest;
use App\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Company details — the name, address and logo printed on invoices and reports.
 */
class CompanyProfileController extends Controller
{
    public function edit(): Response
    {
        $profile = CompanyProfile::current();

        return Inertia::render('administration/company/Edit', [
            'profile' => $profile->letterhead(),
        ]);
    }

    public function update(UpdateCompanyProfileRequest $request, UpdateCompanyProfile $updateProfile): RedirectResponse
    {
        $updateProfile->handle(
            $request->safe()->only(['name', 'address', 'phone', 'email', 'registration_number']),
            $request->user(),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company details saved.')]);

        return to_route('company.edit');
    }

    /**
     * The logo image, for letterheads and the sign-in screen. Not business data, so anyone may load it.
     */
    public function logo(): HttpResponse
    {
        $image = CompanyProfile::current()->logoImage();

        abort_if($image === null, 404);

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
