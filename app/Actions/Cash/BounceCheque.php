<?php

namespace App\Actions\Cash;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Payables\ReverseSupplierAdvance;
use App\Actions\Payables\ReverseSupplierPayment;
use App\Actions\Receivables\ReverseCustomerAdvance;
use App\Actions\Receivables\ReverseCustomerReceipt;
use App\Models\ChequeEvent;
use App\Models\CustomerAdvance;
use App\Models\CustomerReceipt;
use App\Models\SupplierAdvance;
use App\Models\SupplierPayment;
use App\Services\Accounting\ChequeRegister;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that a cheque bounced: the money never moved, so its document — the customer receipt,
 * supplier payment or advance — is REVERSED in the same transaction (its own reversal action, so
 * every rule of that document applies: an advance still applied to an invoice must be unapplied
 * first), and the bounce is kept with its day and reason. The invoice it paid is owed again.
 * What the bank charged for the bounce, when entered, is journalled with it in the same step:
 * `Dr bank charges / Cr the cheque's bank account` (RecordOperationalJournal::forChequeBankCharge).
 * A wrong charge is corrected by reversing that journal. See docs/ACCOUNTING_RULES.md §35.
 */
class BounceCheque
{
    public function __construct(
        private readonly ChequeRegister $register,
        private readonly RecordOperationalJournal $accounting,
    ) {}

    public function handle(string $sourceType, int $sourceId, string $date, string $reason, ?int $userId = null, ?string $bankCharge = null): ChequeEvent
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $date, $reason, $userId, $bankCharge): ChequeEvent {
            $document = $this->register->document($sourceType, $sourceId);
            $charge = $bankCharge === null || $bankCharge === '' ? null : DecimalMath::money(DecimalMath::of($bankCharge));

            if ($charge !== null && DecimalMath::isNegative($charge)) {
                throw ValidationException::withMessages(['bank_charge' => __('Enter a bank charge of zero or more.')]);
            }

            if (! $this->register->standing($document)) {
                throw ValidationException::withMessages(['status' => __('This cheque\'s document is already reversed.')]);
            }

            if ($this->register->event($document, ChequeEvent::CLEARED) !== null) {
                throw ValidationException::withMessages(['status' => __('This cheque is marked cleared. Undo that first if it bounced.')]);
            }

            match (true) {
                $document instanceof CustomerReceipt => app(ReverseCustomerReceipt::class)->handle($document, $userId),
                $document instanceof SupplierPayment => app(ReverseSupplierPayment::class)->handle($document, $userId),
                $document instanceof CustomerAdvance => app(ReverseCustomerAdvance::class)->handle($document, $userId),
                $document instanceof SupplierAdvance => app(ReverseSupplierAdvance::class)->handle($document, $userId),
            };

            $event = ChequeEvent::create([
                'source_type' => $document::class,
                'source_id' => $document->getKey(),
                'event' => ChequeEvent::BOUNCED,
                'event_date' => CarbonImmutable::parse($date)->toDateString(),
                'reason' => $reason,
                'bank_charge' => $charge !== null && DecimalMath::isPositive($charge) ? $charge : null,
                'created_by' => $userId,
            ]);

            if ($event->bank_charge !== null) {
                $row = $this->register->row($document->refresh());
                $journal = $this->accounting->forChequeBankCharge($event, (int) $this->register->bankAccountId($document), '#'.($row['cheque_number'] ?? '').' ('.$row['document_number'].')', $userId);
                $event->update(['journal_entry_id' => $journal->id]);
            }

            return $event->refresh();
        });
    }
}
