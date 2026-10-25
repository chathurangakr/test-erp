<?php

namespace App\Actions\Cash;

use App\Models\ChequeEvent;
use App\Services\Accounting\ChequeRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes back a "cleared" marked by mistake: the event is kept, marked reversed, and the cheque is
 * waiting again. See docs/ACCOUNTING_RULES.md §35.
 */
class UndoChequeCleared
{
    public function __construct(private readonly ChequeRegister $register) {}

    public function handle(string $sourceType, int $sourceId, ?int $userId = null): ChequeEvent
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $userId): ChequeEvent {
            $document = $this->register->document($sourceType, $sourceId);
            $event = $this->register->event($document, ChequeEvent::CLEARED)
                ?? throw ValidationException::withMessages(['status' => __('This cheque was not marked cleared.')]);

            $event->update(['reversed_at' => now(), 'reversed_by' => $userId ?? auth()->id()]);

            return $event->refresh();
        });
    }
}
