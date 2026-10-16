<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One offline entry from the mobile app that became a posted document.
 *
 * Its idempotency key is unique, so the same entry sent twice can only ever be recorded once.
 * Audit history: never updated, never deleted. See docs/API_V1.md §17.
 *
 * @property string $idempotency_key
 * @property int $user_id
 * @property string $operation
 * @property string $request_hash
 */
class ApiSubmission extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'idempotency_key',
        'user_id',
        'personal_access_token_id',
        'device_name',
        'operation',
        'request_hash',
        'document_type',
        'document_id',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('An API submission is audit history and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('An API submission is audit history and cannot be deleted.');
        });
    }

    /**
     * The fingerprint of an entry's body, independent of key order, so the same entry sent
     * again is recognised and a different entry reusing its key is not.
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function hashOf(array $input): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $item): mixed => is_array($item) ? $canonical($item) : $item, $value);
        };

        return hash('sha256', (string) json_encode($canonical($input)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }
}
