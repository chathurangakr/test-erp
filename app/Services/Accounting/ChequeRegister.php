<?php

namespace App\Services\Accounting;

use App\Enums\ChequeStatus;
use App\Enums\PaymentMethod;
use App\Models\ChequeEvent;
use App\Models\CustomerAdvance;
use App\Models\CustomerReceipt;
use App\Models\JournalEntryLine;
use App\Models\SupplierAdvance;
use App\Models\SupplierPayment;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Every cheque the factory received or issued — read from the documents that record them
 * (customer receipts, supplier payments, customer and supplier advances paid by CHEQUE) — with its
 * status derived from cheque events and bank reconciliations, never stored on the document.
 * See docs/ACCOUNTING_RULES.md §35.
 */
class ChequeRegister
{
    /**
     * Each document kind: [direction, date column, number column, party relation, bank account
     * id resolver, show route, standing status value].
     *
     * @var array<class-string<Model>, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public const SOURCES = [
        CustomerReceipt::class => ['RECEIVED', 'receipt_date', 'receipt_number', 'customer', 'customer-receipts.show'],
        CustomerAdvance::class => ['RECEIVED', 'advance_date', 'advance_number', 'customer', 'customer-advances.show'],
        SupplierPayment::class => ['ISSUED', 'payment_date', 'payment_number', 'supplier', 'supplier-payments.show'],
        SupplierAdvance::class => ['ISSUED', 'advance_date', 'advance_number', 'supplier', 'supplier-advances.show'],
    ];

    public function __construct(private readonly BankReconciliationService $reconciliation) {}

    /** The document a cheque belongs to, or a refusal when it is not one this register knows. */
    public function document(string $type, int $id): Model
    {
        if (! array_key_exists($type, self::SOURCES)) {
            throw new InvalidArgumentException("Unknown cheque document {$type}");
        }

        $document = $type::query()->findOrFail($id);

        if ($document->payment_method !== PaymentMethod::Cheque) {
            throw new InvalidArgumentException('That document was not paid by cheque.');
        }

        return $document;
    }

    /**
     * Every cheque, newest first, optionally only one direction or status.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cheques(?string $direction = null, ?ChequeStatus $status = null): Collection
    {
        $rows = collect();

        foreach (self::SOURCES as $type => [$dir, $dateColumn, , $party]) {
            if ($direction !== null && $direction !== $dir) {
                continue;
            }

            $documents = $type::query()
                ->where('payment_method', PaymentMethod::Cheque->value)
                ->whereIn('status', ['POSTED', 'REVERSED', 'PARTIALLY_PAID', 'PAID'])
                ->with([$party.':id,code,name'])
                ->orderByDesc($dateColumn)
                ->limit(500)
                ->get();

            foreach ($documents as $document) {
                $rows->push($this->row($document));
            }
        }

        return $rows
            ->when($status !== null, fn (Collection $all) => $all->where('status', $status->value))
            ->sortByDesc(fn (array $row): string => $row['date'].'-'.str_pad((string) $row['source_id'], 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * One cheque as the register shows it.
     *
     * @return array<string, mixed>
     */
    public function row(Model $document): array
    {
        [$direction, $dateColumn, $numberColumn, $party, $route] = self::SOURCES[$document::class];
        [$status, $statusDate] = $this->status($document);
        $partyModel = $document->{$party};
        $standing = $this->standing($document);
        $cleared = $this->event($document, ChequeEvent::CLEARED);
        $bounced = $this->event($document, ChequeEvent::BOUNCED);

        return [
            'source_type' => $document::class,
            'source_id' => $document->getKey(),
            'key' => class_basename($document).':'.$document->getKey(),
            'direction' => $direction,
            'date' => $document->{$dateColumn}?->toDateString(),
            'document_number' => $document->{$numberColumn},
            'document_url' => route($route, $document),
            'party' => $partyModel === null ? null : $partyModel->code.' — '.$partyModel->name,
            'cheque_number' => $document->cheque_number,
            'cheque_date' => $document->cheque_date?->toDateString(),
            'cheque_bank' => $document->cheque_bank,
            'amount' => (string) $document->amount,
            'not_yet_due' => $document->cheque_date !== null && $document->cheque_date->greaterThan(BusinessClock::today()),
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_date' => $statusDate,
            'bank_charge' => $bounced?->bank_charge === null ? null : (string) $bounced->bank_charge,
            'can_clear' => $standing && $status === ChequeStatus::Waiting,
            'can_bounce' => $standing && $cleared === null && in_array($status, [ChequeStatus::Waiting, ChequeStatus::Cleared], true),
            'can_undo_clear' => $cleared !== null,
        ];
    }

    /**
     * @return array{0: ChequeStatus, 1: string|null}
     */
    public function status(Model $document): array
    {
        if (($bounced = $this->event($document, ChequeEvent::BOUNCED)) !== null) {
            return [ChequeStatus::Bounced, $bounced->event_date->toDateString()];
        }

        if (! $this->standing($document)) {
            return [ChequeStatus::Void, null];
        }

        if (($cleared = $this->event($document, ChequeEvent::CLEARED)) !== null) {
            return [ChequeStatus::Cleared, $cleared->event_date->toDateString()];
        }

        $line = $this->bankLine($document);

        if ($line !== null && $this->reconciliation->isReconciled($line->id)) {
            return [ChequeStatus::Cleared, null];
        }

        return [ChequeStatus::Waiting, null];
    }

    /** The standing event of a kind recorded on this document's cheque, if any. */
    public function event(Model $document, string $kind): ?ChequeEvent
    {
        return ChequeEvent::query()
            ->where('source_type', $document::class)
            ->where('source_id', $document->getKey())
            ->where('event', $kind)
            ->whereNull('reversed_at')
            ->first();
    }

    /** Whether the document still stands (not reversed or cancelled). */
    public function standing(Model $document): bool
    {
        return in_array((string) ($document->getAttributes()['status'] ?? ''), ['POSTED', 'PARTIALLY_PAID', 'PAID'], true);
    }

    /** The ledger account of the bank the cheque went through. */
    public function bankAccountId(Model $document): ?int
    {
        return match (true) {
            $document instanceof CustomerReceipt => $document->receipt_account_id,
            $document instanceof SupplierPayment => $document->payment_account_id,
            default => $document->cashBankAccount?->account_id,
        };
    }

    /** The document's journal line on its bank account — what a bank statement shows. */
    private function bankLine(Model $document): ?JournalEntryLine
    {
        $accountId = $this->bankAccountId($document);

        return $document->journal_entry_id === null ? null : JournalEntryLine::query()
            ->where('journal_entry_id', $document->journal_entry_id)
            ->where('account_id', $accountId)
            ->first();
    }
}
