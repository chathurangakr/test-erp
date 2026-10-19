<?php

namespace App\Actions\Administration;

use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Saves the company's name, contact details and logo — creating the single row the first time.
 *
 * A new logo replaces the old one; `$removeLogo` clears it. Leaving both out keeps the logo.
 */
class UpdateCompanyProfile
{
    /**
     * @param  array{name: string, address?: ?string, phone?: ?string, email?: ?string, registration_number?: ?string}  $details
     */
    public function handle(array $details, User $user, ?UploadedFile $logo = null, bool $removeLogo = false): CompanyProfile
    {
        return DB::transaction(function () use ($details, $user, $logo, $removeLogo): CompanyProfile {
            $profile = CompanyProfile::query()->orderBy('id')->lockForUpdate()->first() ?? new CompanyProfile;

            $profile->fill($details);
            $profile->updated_by = $user->id;

            if ($logo !== null) {
                $profile->logo = 'data:'.$logo->getMimeType().';base64,'.base64_encode((string) $logo->get());
            } elseif ($removeLogo) {
                $profile->logo = null;
            }

            $profile->save();

            return $profile;
        });
    }
}
