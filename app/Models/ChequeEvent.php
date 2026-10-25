<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * What happened to a cheque recorded on a document (source_type + source_id): CLEARED or BOUNCED
 * on a day. Never edited or deleted; a clearing marked by mistake is undone by setting
 * `reversed_at`. See docs/ACCOUNTING_RULES.md §35.
 *
 * @property string $source_type
 * @property int $source_id
 * @property string $event
 * @property Carbon $event_date
 * @property Carbon|null $reversed_at
 */
class ChequeEvent extends Model
{
    public const CLEARED = 'CLEARED';

    public const BOUNCED = 'BOUNCED';

    protected $fillable = ['source_type', 'source_id', 'event', 'event_date', 'reason', 'created_by', 'reversed_at', 'reversed_by'];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            if (array_diff(array_keys($event->getDirty()), ['reversed_at', 'reversed_by', 'updated_at']) !== []) {
                throw new LogicException('A cheque event cannot be edited.');
            }
        });

        static::deleting(fn () => throw new LogicException('A cheque event is never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
