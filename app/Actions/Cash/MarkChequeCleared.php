<?php

namespace App\Actions\Cash;

use App\Enums\ChequeStatus;
use App\Models\ChequeEvent;
use App\Services\Accounting\ChequeRegister;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a waiting cheque as cleared through the bank on a day. Changes no journal — the money was
 * journalled when the receipt, payment or advance was recorded. A bank reconciliation that ticks
 * the cheque's bank line clears it too, without this. See docs/ACCOUNTING_RULES.md §35.
 */
class MarkChequeCleared
{
    public function __construct(private readonly ChequeRegister $register) {}

    public function handle(string $sourceType, int $sourceId, string $date, ?int $userId = null): ChequeEvent
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $date, $userId): ChequeEvent {
            $document = $this->register->document($sourceType, $sourceId);
            $document->newQuery()->whereKey($sourceId)->lockForUpdate()->first();
            [$status] = $this->register->status($document);

            if ($status !== ChequeStatus::Waiting) {
                throw ValidationException::withMessages(['status' => __('This cheque is :status, not waiting.', ['status' => $status->label()])]);
            }

            $day = CarbonImmutable::parse($date);
            [, $dateColumn] = ChequeRegister::SOURCES[$document::class];

            if ($day->lessThan(CarbonImmutable::parse($document->{$dateColumn}))) {
                throw ValidationException::withMessages(['event_date' => __('A cheque cannot clear before it was recorded.')]);
            }

            return ChequeEvent::create([
                'source_type' => $document::class,
                'source_id' => $document->getKey(),
                'event' => ChequeEvent::CLEARED,
                'event_date' => $day->toDateString(),
                'created_by' => $userId,
            ]);
        });
    }
}
