<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Receivables\RefreshSalesInvoiceStatus;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\DeliveryStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\PricingBasis;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SalesReturnSettlement;
use App\Enums\SalesReturnStatus;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\DeliveryLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records goods a customer brought back, in one step (owner's rules, 2026-10-24):
 *
 *  - The customer is credited at the PRICE CHARGED on the delivery line — by the primary
 *    quantity returned, or the weighed quantity for a line priced per KG.
 *  - GOOD goods go back into stock (SALES_RETURN, at the unit cost they left with, into the lot
 *    they left from); DAMAGED ones never re-enter stock — their cost is a loss.
 *  - The credit is settled by reducing an invoice of the customer (CREDIT — normally the one that
 *    billed the goods; EXCHANGE — the invoice for the replacement goods) or by money back
 *    (REFUND, no more than the customer paid on the original invoice).
 *
 * Only an INVOICED, posted, non-backflushed delivery line can be returned against, and never
 * more than was delivered, nor credited beyond what was billed. Everything — stock, credit and
 * journal — is written in one transaction. See docs/ACCOUNTING_RULES.md §32.
 */
class RecordSalesReturn
{
    public function __construct(
        private readonly PostInventoryTransaction $postInventory,
        private readonly RecordOperationalJournal $accounting,
        private readonly AccountingPolicy $policy,
        private readonly AccountsReceivableService $receivables,
        private readonly SalesBillingReconciliationService $billing,
        private readonly RefreshSalesInvoiceStatus $refreshInvoice,
        private readonly GenerateSalesReference $references,
    ) {}

    /**
     * @param  array{return_date: string, delivery_line_id: int, good_quantity: string, damaged_quantity?: string|null, secondary_quantity?: string|null, warehouse_id?: int|null, settlement: string, credit_invoice_id?: int|null, cash_bank_account_id?: int|null, payment_method?: string|null, reference?: string|null, reason: string}  $data
     */
    public function handle(array $data, ?int $userId = null): SalesReturn
    {
        return DB::transaction(function () use ($data, $userId): SalesReturn {
            $line = DeliveryLine::query()->with(['delivery', 'product.primaryUom'])->lockForUpdate()->findOrFail($data['delivery_line_id']);
            $delivery = $line->delivery;
            $customer = Customer::query()->whereKey($delivery->customer_id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::parse($data['return_date'])->startOfDay();
            $settlement = SalesReturnSettlement::from($data['settlement']);

            $this->assertReturnable($line);

            $good = DecimalMath::quantity(DecimalMath::of((string) ($data['good_quantity'] ?? '0')));
            $damaged = DecimalMath::quantity(DecimalMath::of((string) ($data['damaged_quantity'] ?? '0')));
            $this->assertQuantities($line, $good, $damaged);

            $original = $this->originalInvoice($line);

            if ($date->lessThan(CarbonImmutable::parse($original->invoice_date))) {
                throw ValidationException::withMessages([
                    'return_date' => __('A return cannot be dated before the invoice :reference (:date).', [
                        'reference' => $original->invoice_number,
                        'date' => $original->invoice_date->toDateString(),
                    ]),
                ]);
            }

            $secondary = $line->pricing_basis === PricingBasis::Secondary
                ? $this->secondaryQuantity($line, $data['secondary_quantity'] ?? null)
                : null;
            $credit = DecimalMath::money(DecimalMath::mul(
                $secondary ?? DecimalMath::add($good, $damaged),
                DecimalMath::of((string) $line->unit_price),
            ));
            $this->assertWithinBilled($line, $credit);

            [$creditInvoice, $refundAccount, $method] = $this->settle($settlement, $data, $customer, $original, $credit, $date);
            $store = DecimalMath::isPositive($good) ? $this->store($data['warehouse_id'] ?? null) : null;

            $unitCost = DecimalMath::cost(DecimalMath::of((string) $line->inventory_unit_cost));

            $return = SalesReturn::create([
                'return_number' => $this->references->forSalesReturn(Carbon::instance($date)),
                'return_date' => $date->toDateString(),
                'customer_id' => $customer->id,
                'delivery_line_id' => $line->id,
                'product_id' => $line->product_id,
                'warehouse_id' => $store?->id ?? $delivery->warehouse_id,
                'lot_id' => $line->lot_id,
                'good_quantity' => $good,
                'damaged_quantity' => $damaged,
                'secondary_quantity' => $secondary,
                'unit_price' => $line->unit_price,
                'credit_amount' => $credit,
                'unit_cost' => $unitCost,
                'good_cost' => DecimalMath::cost(DecimalMath::mul($good, $unitCost)),
                'damaged_cost' => DecimalMath::cost(DecimalMath::mul($damaged, $unitCost)),
                'settlement' => $settlement,
                'original_invoice_id' => $original->id,
                'credit_invoice_id' => $creditInvoice?->id,
                'cash_bank_account_id' => $refundAccount?->id,
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'reason' => $data['reason'],
                'status' => SalesReturnStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            if ($store !== null) {
                $transaction = $this->postInventory->handle(
                    InventoryTransactionType::SalesReturn,
                    [(new PendingInventoryLine(
                        productId: $line->product_id,
                        warehouseId: $store->id,
                        direction: InventoryDirection::In,
                        quantity: $good,
                        lotId: $line->lot_id,
                    ))->withAuthoritativeCost($unitCost, (string) $return->good_cost)],
                    [
                        'transaction_date' => $date->toDateString(),
                        'source_type' => SalesReturn::class,
                        'source_id' => $return->id,
                        'notes' => RecordText::of('Sales return :reference from :customer', [
                            'reference' => $return->return_number,
                            'customer' => $customer->code,
                        ]),
                        'posted_by' => $userId ?? auth()->id(),
                    ],
                );
                $return->update(['inventory_transaction_id' => $transaction->id]);
            }

            $journal = $this->accounting->forSalesReturn($return, $refundAccount, $userId);
            $return->update(['journal_entry_id' => $journal->id]);

            if ($creditInvoice !== null) {
                $this->refreshInvoice->handle($creditInvoice);
            }

            return $return->refresh();
        });
    }

    private function assertReturnable(DeliveryLine $line): void
    {
        if ($line->delivery->status !== DeliveryStatus::Posted) {
            throw ValidationException::withMessages([
                'delivery_line_id' => __('Only goods on a posted delivery can be returned.'),
            ]);
        }

        // Chips and cocopeat carry the raw husk their delivery used up; returning them would
        // need that husk back too. Not confirmed, so refused rather than guessed at.
        if ($line->backflush_product_id !== null) {
            throw ValidationException::withMessages([
                'delivery_line_id' => __(':product used raw material at delivery and cannot be returned here.', [
                    'product' => $line->product->code,
                ]),
            ]);
        }
    }

    private function assertQuantities(DeliveryLine $line, string $good, string $damaged): void
    {
        if (DecimalMath::isNegative($good) || DecimalMath::isNegative($damaged)) {
            throw ValidationException::withMessages(['good_quantity' => __('Quantities cannot be negative.')]);
        }

        $total = DecimalMath::add($good, $damaged);

        if (! DecimalMath::isPositive($total)) {
            throw ValidationException::withMessages(['good_quantity' => __('Enter how many came back.')]);
        }

        $returned = DecimalMath::of((string) SalesReturn::query()
            ->where('delivery_line_id', $line->id)
            ->where('status', SalesReturnStatus::Posted->value)
            ->selectRaw('COALESCE(SUM(good_quantity + damaged_quantity), 0) as total')
            ->value('total'));
        $left = DecimalMath::sub(DecimalMath::of((string) $line->quantity), $returned);

        if (DecimalMath::compare($total, $left, 3) > 0) {
            throw ValidationException::withMessages([
                'good_quantity' => __('Only :left of this delivery can still be returned.', ['left' => DecimalMath::quantity($left)]),
            ]);
        }
    }

    /**
     * The invoice that billed the delivery line. A line not yet invoiced has no price charged
     * to credit — invoice it first, or reverse the delivery if all of it came back.
     */
    private function originalInvoice(DeliveryLine $line): SalesInvoice
    {
        $invoiceId = SalesInvoiceLine::query()
            ->where('delivery_line_id', $line->id)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [
                SalesInvoiceStatus::Posted->value,
                SalesInvoiceStatus::PartiallyPaid->value,
                SalesInvoiceStatus::Paid->value,
            ]))
            ->orderBy('id')
            ->value('sales_invoice_id');

        if ($invoiceId === null) {
            throw ValidationException::withMessages([
                'delivery_line_id' => __('These goods have not been invoiced yet. Invoice the delivery first — or, if all of it came back, reverse the delivery.'),
            ]);
        }

        return SalesInvoice::query()->findOrFail($invoiceId);
    }

    /**
     * The weighed quantity returned, for a line priced per KG: what the credit is priced on.
     */
    private function secondaryQuantity(DeliveryLine $line, mixed $value): string
    {
        $quantity = DecimalMath::quantity(DecimalMath::of((string) ($value ?? '0')));

        if (! DecimalMath::isPositive($quantity)) {
            throw ValidationException::withMessages([
                'secondary_quantity' => __('These goods were priced by weight. Enter the weight that came back.'),
            ]);
        }

        if (DecimalMath::compare($quantity, DecimalMath::of((string) $line->secondary_quantity), 3) > 0) {
            throw ValidationException::withMessages([
                'secondary_quantity' => __('More weight cannot come back than was delivered (:weight).', ['weight' => $line->secondary_quantity]),
            ]);
        }

        return $quantity;
    }

    private function assertWithinBilled(DeliveryLine $line, string $credit): void
    {
        $credited = DecimalMath::of((string) SalesReturn::query()
            ->where('delivery_line_id', $line->id)
            ->where('status', SalesReturnStatus::Posted->value)
            ->sum('credit_amount'));
        $left = DecimalMath::sub($this->billing->billedAmount($line), $credited);

        if (DecimalMath::compare($credit, $left, 2) > 0) {
            throw ValidationException::withMessages([
                'good_quantity' => __('The credit of :credit is more than the :left still invoiced for these goods.', [
                    'credit' => DecimalMath::money($credit),
                    'left' => DecimalMath::money($left),
                ]),
            ]);
        }
    }

    /**
     * Where the credit goes: an invoice it reduces, or the cash/bank account paying it out.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: SalesInvoice|null, 1: CashBankAccount|null, 2: PaymentMethod|null}
     */
    private function settle(SalesReturnSettlement $settlement, array $data, Customer $customer, SalesInvoice $original, string $credit, CarbonImmutable $date): array
    {
        if ($settlement->reducesAnInvoice()) {
            $invoiceId = $data['credit_invoice_id'] ?? ($settlement === SalesReturnSettlement::Credit ? $original->id : null);
            $invoice = $invoiceId === null ? null : SalesInvoice::query()->lockForUpdate()->find($invoiceId);

            if ($invoice === null || $invoice->customer_id !== $customer->id || ! $invoice->status->affectsReceivables()) {
                throw ValidationException::withMessages([
                    'credit_invoice_id' => $settlement === SalesReturnSettlement::Exchange
                        ? __('Choose the invoice for the goods given in exchange. Record that delivery and invoice first.')
                        : __('Choose an invoice of this customer that is still owed.'),
                ]);
            }

            if (CarbonImmutable::parse($invoice->invoice_date)->greaterThan($date)) {
                throw ValidationException::withMessages([
                    'credit_invoice_id' => __('Invoice :reference is dated after this return.', ['reference' => $invoice->invoice_number]),
                ]);
            }

            $outstanding = $this->receivables->outstanding($invoice);

            if (DecimalMath::compare($credit, $outstanding, 2) > 0) {
                throw ValidationException::withMessages([
                    'credit_invoice_id' => __('Invoice :reference is owed only :outstanding, less than this credit of :credit. Give the rest as money back.', [
                        'reference' => $invoice->invoice_number,
                        'outstanding' => DecimalMath::money($outstanding),
                        'credit' => $credit,
                    ]),
                ]);
            }

            return [$invoice, null, null];
        }

        $method = PaymentMethod::from((string) ($data['payment_method'] ?? ''));
        $configuration = CashBankAccount::query()->with('account')->find($data['cash_bank_account_id'] ?? 0)
            ?? throw ValidationException::withMessages(['cash_bank_account_id' => __('Choose the cash or bank account the money is paid from.')]);
        $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('money back'));
        $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');

        // Money back only for money received: never more than the customer paid on the invoice
        // that billed these goods, less what earlier returns already gave back.
        $refunded = DecimalMath::of((string) SalesReturn::query()
            ->where('original_invoice_id', $original->id)
            ->where('settlement', SalesReturnSettlement::Refund->value)
            ->where('status', SalesReturnStatus::Posted->value)
            ->sum('credit_amount'));
        $paid = DecimalMath::sub($this->receivables->receivedAmount($original), $refunded);

        if (DecimalMath::compare($credit, $paid, 2) > 0) {
            throw ValidationException::withMessages([
                'settlement' => __('The customer has paid only :paid on invoice :reference, so no more than that can be given back. Reduce what they owe instead.', [
                    'paid' => DecimalMath::money($paid),
                    'reference' => $original->invoice_number,
                ]),
            ]);
        }

        return [null, $configuration, $method];
    }

    private function store(?int $warehouseId): Warehouse
    {
        $store = $warehouseId === null ? null : Warehouse::query()->operational()->find($warehouseId);

        if ($store === null) {
            throw ValidationException::withMessages(['warehouse_id' => __('Choose the store the good goods go back to.')]);
        }

        return $store;
    }
}
