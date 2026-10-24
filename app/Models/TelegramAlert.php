<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One transaction alert waiting for, or already given, to the transactions bot. Written by
 * App\Observers\TransactionAlertObserver inside the posting's own transaction; sent by
 * `telegram:send-alerts`. See docs/TELEGRAM.md.
 *
 * @property string $document_type
 * @property int $document_id
 * @property string $event
 * @property array<string, mixed>|null $context
 * @property string $status
 * @property int $attempts
 * @property list<string>|null $delivered_to
 * @property string|null $last_error
 */
class TelegramAlert extends Model
{
    public const PENDING = 'PENDING';

    public const SENT = 'SENT';

    public const FAILED = 'FAILED';

    /** Refusals before an alert is given up on (a connection failure does not count). */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['document_type', 'document_id', 'event', 'context', 'status', 'attempts', 'delivered_to', 'last_error', 'sent_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'delivered_to' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
