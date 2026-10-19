<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single row naming the business on printed invoices and reports.
 *
 * `logo` is a data URI (`data:image/png;base64,…`) so backups and restores carry it with the
 * rest of the database. It is served to browsers by the company.logo route, never shared in
 * every page's props.
 */
class CompanyProfile extends Model
{
    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'registration_number',
        'logo',
        'updated_by',
    ];

    protected $hidden = ['logo'];

    /**
     * The saved profile, or an unsaved one named after the application while none is saved.
     *
     * Like AccountingSetting::current(), a read never writes a row.
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self(['name' => config('app.name')]);
    }

    /**
     * What every screen needs to print a letterhead.
     *
     * @return array{name: string, address: ?string, phone: ?string, email: ?string, registration_number: ?string, logo_url: ?string}
     */
    public function letterhead(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'registration_number' => $this->registration_number,
            // The version in the address makes browsers fetch a replaced logo at once.
            'logo_url' => $this->logo === null
                ? null
                : route('company.logo', ['v' => $this->updated_at?->getTimestamp()], false),
        ];
    }

    /**
     * @return array{mime: string, bytes: string}|null
     */
    public function logoImage(): ?array
    {
        if ($this->logo === null || ! preg_match('/^data:(image\/[a-z+.-]+);base64,(.+)$/s', $this->logo, $parts)) {
            return null;
        }

        return ['mime' => $parts[1], 'bytes' => (string) base64_decode($parts[2], true)];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
