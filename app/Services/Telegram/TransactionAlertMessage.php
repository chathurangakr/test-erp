<?php

namespace App\Services\Telegram;

use App\Enums\CustomerBagMovementKind;
use App\Enums\SalesReturnSettlement;
use App\Models\AssetMaintenance;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceApplication;
use App\Models\CustomerBagMovement;
use App\Models\CustomerReceipt;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\ExpenseVoucher;
use App\Models\ExpenseVoucherLine;
use App\Models\FixedAsset;
use App\Models\FundTransfer;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\SalaryAdvance;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\StockDamage;
use App\Models\StockDamageLine;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierPayment;
use App\Models\TelegramAlert;
use App\Models\User;
use App\Services\Loans\LoanBalances;
use App\Support\DecimalMath;
use App\Support\DisplayNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A transaction alert written out for Telegram: what happened, the document's number and date,
 * who it was with, its lines, the total, who did it and a link to open it.
 *
 * Written when the alert is SENT, from the document as it now stands — posted figures never
 * change, so the message is the same as it would have been at posting; only what an event erased
 * comes from the alert's `context`. Figures are read from the documents, never recalculated.
 * See docs/TELEGRAM.md.
 */
class TransactionAlertMessage
{
    /** Lines listed before the rest are summarised, keeping a message well inside Telegram's limit. */
    private const MAX_LINES = 15;

    public function __construct(private readonly LoanBalances $loans) {}

    /** The message, or null when the document no longer exists. */
    public function forAlert(TelegramAlert $alert): ?string
    {
        $class = array_search($alert->document_type, TransactionAlertCatalog::MODELS, true);
        $model = $class === false ? null : $class::query()->find($alert->document_id);

        if ($model === null) {
            return null;
        }

        $card = $this->card($model, $alert->event, $alert->context ?? []);

        return $this->render($card, $alert->event, $this->who($model, $alert->event));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{icon: string, title: string, number: string, date: string, facts: list<array{0: string, 1: string|null}>, lines: list<string>, total: array{0: string, 1: string}|null, url: string}
     */
    private function card(Model $model, string $event, array $context): array
    {
        return match (true) {
            $model instanceof GoodsReceipt => $this->goodsReceipt($model),
            $model instanceof Delivery => $this->delivery($model),
            $model instanceof ProductionOrder => $this->production($model),
            $model instanceof SalesInvoice => $this->salesInvoice($model),
            $model instanceof CustomerReceipt => $this->customerReceipt($model),
            $model instanceof SalesReturn => $this->salesReturn($model),
            $model instanceof SalesCreditNote => $this->creditNote($model),
            $model instanceof CustomerAdvance, $model instanceof SupplierAdvance => $this->partyAdvance($model),
            $model instanceof CustomerAdvanceApplication, $model instanceof SupplierAdvanceApplication => $this->advanceApplication($model),
            $model instanceof CustomerBagMovement => $this->bags($model),
            $model instanceof SupplierInvoice => $this->supplierInvoice($model),
            $model instanceof SupplierPayment => $this->supplierPayment($model),
            $model instanceof ExpenseVoucher => $this->expenseVoucher($model),
            $model instanceof FundTransfer => $this->fundTransfer($model),
            $model instanceof JournalEntry => $this->journal($model),
            $model instanceof StockDamage => $this->damage($model),
            $model instanceof SalaryAdvance => $this->advance($model),
            $model instanceof PayrollRun => $this->payroll($model),
            $model instanceof Loan => $this->loan($model),
            $model instanceof LoanPayment => $this->loanPayment($model),
            $model instanceof FixedAsset => $this->asset($model, $event, $context),
            $model instanceof AssetMaintenance => $this->maintenance($model),
        };
    }

    private function goodsReceipt(GoodsReceipt $receipt): array
    {
        $receipt->load(['supplier', 'warehouse', 'lines.product.primaryUom']);
        $landed = DecimalMath::add(DecimalMath::add((string) $receipt->transport_cost, (string) $receipt->loading_cost), (string) $receipt->other_cost);
        $total = $receipt->lines->reduce(fn (string $sum, GoodsReceiptLine $line): string => DecimalMath::add($sum, (string) $line->material_cost), $landed);

        return $this->make('📥', __('Goods received'), $receipt->grn_number, $receipt->receipt_date, [
            [__('Supplier'), $receipt->supplier?->name],
            [__('Warehouse'), $receipt->warehouse?->name],
            [__('Supplier reference'), $receipt->supplier_delivery_reference],
            [__('Transport, loading and other costs'), DecimalMath::isPositive($landed) ? $this->money($landed) : null],
        ], $receipt->lines->map(fn (GoodsReceiptLine $line): string => $this->quantityLine($line->product, (string) $line->quantity)
            .' × '.DisplayNumber::money((string) $line->unit_price).' = '.DisplayNumber::money((string) $line->material_cost)),
            [__('Total cost'), $total], route('goods-receipts.show', $receipt));
    }

    private function delivery(Delivery $delivery): array
    {
        $delivery->load(['customer', 'warehouse', 'lines.product.primaryUom', 'lines.product.secondaryUom']);
        $value = $this->sum($delivery->lines, 'sales_value');
        $cost = $this->sum($delivery->lines, 'cost_of_goods_sold');

        return $this->make('🚚', __('Delivery'), $delivery->delivery_number, $delivery->delivery_date, [
            [__('Customer'), $delivery->customer?->name],
            [__('Warehouse'), $delivery->warehouse?->name],
            [__('Vehicle'), trim($delivery->vehicle_number.' '.($delivery->driver_name ? '('.$delivery->driver_name.')' : '')) ?: null],
            [__('Cost of goods'), $this->money($cost)],
            [__('Gross profit'), $this->money(DecimalMath::sub($value, $cost))],
        ], $delivery->lines->map(fn (DeliveryLine $line): string => $this->quantityLine($line->product, (string) $line->quantity)
            .$this->secondary($line->product, $line->secondary_quantity)
            .' = '.DisplayNumber::money((string) $line->sales_value)),
            [__('Sales value'), $value], route('deliveries.show', $delivery));
    }

    private function production(ProductionOrder $order): array
    {
        $order->load(['warehouse', 'inputs.product.primaryUom', 'outputs.product.primaryUom', 'outputs.product.secondaryUom']);
        $route = match ($order->production_type->value) {
            'DEHUSKING' => 'dehusking.show',
            'HUSK_CHIPPING' => 'husk-chipping.show',
            default => 'copra.show',
        };
        $lines = $order->inputs->map(fn (ProductionInput $input): string => '➖ '.$this->quantityLine($input->product, (string) $input->quantity))
            ->concat($order->outputs->map(fn (ProductionOutput $output): string => '➕ '.$this->quantityLine($output->product, (string) $output->quantity)
                .$this->secondary($output->product, $output->secondary_quantity)));

        return $this->make('🏭', $order->production_type->label(), $order->production_number, $order->production_date, [
            [__('Warehouse'), $order->warehouse?->name],
            [__('Labour'), DecimalMath::isPositive((string) $order->labour_cost) ? $this->money((string) $order->labour_cost) : null],
            [__('Waste'), $order->waste_quantity !== null ? DisplayNumber::quantity((string) $order->waste_quantity) : null],
        ], $lines, null, route($route, $order));
    }

    private function salesInvoice(SalesInvoice $invoice): array
    {
        $invoice->load(['customer', 'lines.product']);

        return $this->make('🧾', __('Sales invoice'), $invoice->invoice_number, $invoice->invoice_date, [
            [__('Customer'), $invoice->customer?->name],
            [__('Due'), $invoice->due_date?->toDateString()],
        ], $invoice->lines->map(fn (SalesInvoiceLine $line): string => e((string) ($line->product?->name ?? $line->description)).' = '.DisplayNumber::money((string) $line->amount)),
            [__('Total'), $invoice->total()], route('sales-invoices.show', $invoice));
    }

    private function customerReceipt(CustomerReceipt $receipt): array
    {
        $receipt->load(['customer', 'receiptAccount', 'allocations.invoice']);

        return $this->make('💵', __('Money received from a customer'), $receipt->receipt_number, $receipt->receipt_date, [
            [__('Customer'), $receipt->customer?->name],
            [__('Received into'), $receipt->receiptAccount?->display_name],
            [__('Method'), $this->method($receipt)],
        ], $receipt->allocations->map(fn ($allocation): string => e((string) $allocation->invoice?->invoice_number).' = '.DisplayNumber::money((string) $allocation->amount)),
            [__('Amount'), (string) $receipt->amount], route('customer-receipts.show', $receipt));
    }

    private function salesReturn(SalesReturn $return): array
    {
        $return->load(['customer', 'product.primaryUom', 'cashBankAccount', 'creditInvoice']);
        $settled = match ($return->settlement) {
            SalesReturnSettlement::Refund => __('Money back from :account', ['account' => (string) $return->cashBankAccount?->display_name]),
            default => $return->settlement->label().($return->creditInvoice ? ' — '.$return->creditInvoice->invoice_number : ''),
        };

        return $this->make('↩️', __('Goods returned by a customer'), $return->return_number, $return->return_date, [
            [__('Customer'), $return->customer?->name],
            [__('Settled by'), $settled],
            [__('Reason'), $return->reason],
        ], array_filter([
            DecimalMath::isPositive((string) $return->good_quantity) ? __('Back in stock').': '.$this->quantityLine($return->product, (string) $return->good_quantity) : null,
            DecimalMath::isPositive((string) $return->damaged_quantity) ? __('Damaged').': '.$this->quantityLine($return->product, (string) $return->damaged_quantity) : null,
        ]), [__('Credited'), (string) $return->credit_amount], route('sales-returns.show', $return));
    }

    private function partyAdvance(CustomerAdvance|SupplierAdvance $advance): array
    {
        $customer = $advance instanceof CustomerAdvance;
        $advance->load([$customer ? 'customer' : 'supplier', 'cashBankAccount']);
        $party = $customer ? $advance->customer : $advance->supplier;

        return $this->make($customer ? '💵' : '💸', $customer ? __('Advance received from a customer') : __('Advance paid to a supplier'), $advance->advance_number, $advance->advance_date, [
            [$customer ? __('Customer') : __('Supplier'), $party?->name],
            [$customer ? __('Received into') : __('Paid from'), $advance->cashBankAccount?->display_name],
            [__('Method'), $advance->payment_method->label().($advance->cheque_number ? ' #'.$advance->cheque_number : '')],
            [__('Note'), $advance->note],
        ], [], [__('Amount'), (string) $advance->amount], route($customer ? 'customer-advances.show' : 'supplier-advances.show', $advance));
    }

    private function advanceApplication(CustomerAdvanceApplication|SupplierAdvanceApplication $application): array
    {
        $customer = $application instanceof CustomerAdvanceApplication;
        $application->load(['advance', 'invoice']);
        $invoice = $customer ? $application->invoice?->invoice_number : $application->invoice?->internal_invoice_number;

        return $this->make('🔗', __('Advance applied to an invoice'), (string) $application->advance?->advance_number, $application->application_date, [
            [__('Invoice'), $invoice],
        ], [], [__('Applied'), (string) $application->amount], route($customer ? 'customer-advances.show' : 'supplier-advances.show', $application->advance));
    }

    private function creditNote(SalesCreditNote $note): array
    {
        $note->load(['customer', 'invoice']);

        return $this->make('🏷️', $note->kind->label(), $note->credit_note_number, $note->credit_date, [
            [__('Customer'), $note->customer?->name],
            [__('Invoice'), $note->invoice?->invoice_number],
            [__('Reason'), $note->reason],
        ], [], [__('Credited'), (string) $note->amount], route('credit-notes.show', $note));
    }

    private function bags(CustomerBagMovement $movement): array
    {
        $movement->load(['customer', 'product.primaryUom']);
        $icon = match ($movement->kind) {
            CustomerBagMovementKind::Given => '🛍️',
            CustomerBagMovementKind::Returned => '🔙',
            CustomerBagMovementKind::Lost => '❌',
        };

        return $this->make($icon, __('Customer bags').' — '.$movement->kind->label(), $movement->movement_number, $movement->movement_date, [
            [__('Customer'), $movement->customer?->name],
            [__('Note'), $movement->note],
        ], [$this->quantityLine($movement->product, (string) $movement->quantity)], null, route('customer-bags.show', $movement));
    }

    private function supplierInvoice(SupplierInvoice $invoice): array
    {
        $invoice->load(['supplier', 'lines.goodsReceipt', 'lines.debitAccount']);

        return $this->make('📄', __('Supplier invoice'), $invoice->internal_invoice_number, $invoice->invoice_date, [
            [__('Supplier'), $invoice->supplier?->name],
            [__('Their invoice number'), $invoice->supplier_invoice_number],
            [__('Due'), $invoice->due_date?->toDateString()],
        ], $invoice->lines->map(fn (SupplierInvoiceLine $line): string => e((string) ($line->goodsReceipt?->grn_number ?? $line->debitAccount?->name ?? $line->description))
            .' = '.DisplayNumber::money((string) $line->amount)),
            [__('Total'), $invoice->total()], route('supplier-invoices.show', $invoice));
    }

    private function supplierPayment(SupplierPayment $payment): array
    {
        $payment->load(['supplier', 'paymentAccount', 'allocations.invoice']);

        return $this->make('💸', __('Payment to a supplier'), $payment->payment_number, $payment->payment_date, [
            [__('Supplier'), $payment->supplier?->name],
            [__('Paid from'), $payment->paymentAccount?->display_name],
            [__('Method'), $this->method($payment)],
        ], $payment->allocations->map(fn ($allocation): string => e((string) $allocation->invoice?->internal_invoice_number).' = '.DisplayNumber::money((string) $allocation->amount)),
            [__('Amount'), (string) $payment->amount], route('supplier-payments.show', $payment));
    }

    private function expenseVoucher(ExpenseVoucher $voucher): array
    {
        $voucher->load(['cashBankAccount', 'supplier', 'lines.expenseAccount']);

        return $this->make('🧮', __('Expense paid'), $voucher->voucher_number, $voucher->voucher_date, [
            [__('Paid to'), $voucher->supplier?->name ?? $voucher->payee_name],
            [__('Paid from'), $voucher->cashBankAccount?->display_name],
            [__('Description'), $voucher->description],
        ], $voucher->lines->map(fn (ExpenseVoucherLine $line): string => e((string) $line->expenseAccount?->name)
            .($line->description ? ' — '.e($line->description) : '').' = '.DisplayNumber::money((string) $line->amount)),
            [__('Total'), $voucher->total()], route('expense-vouchers.show', $voucher));
    }

    private function fundTransfer(FundTransfer $transfer): array
    {
        $transfer->load(['fromAccount', 'toAccount']);

        return $this->make('🔁', __('Money moved between cash and bank'), $transfer->transfer_number, $transfer->transfer_date, [
            [__('From'), $transfer->fromAccount?->display_name],
            [__('To'), $transfer->toAccount?->display_name],
            [__('Description'), $transfer->description],
        ], [], [__('Amount'), (string) $transfer->amount], route('fund-transfers.show', $transfer));
    }

    private function journal(JournalEntry $journal): array
    {
        $journal->load('lines.account');

        return $this->make('📒', __('Manual journal'), $journal->journal_number, $journal->journal_date, [
            [__('Description'), $journal->description],
        ], $journal->lines->map(fn (JournalEntryLine $line): string => e((string) $line->account?->code.' '.$line->account?->name).' — '
            .(DecimalMath::isPositive((string) $line->debit) ? __('Dr').' '.DisplayNumber::money((string) $line->debit) : __('Cr').' '.DisplayNumber::money((string) $line->credit))),
            [__('Total'), $this->sum($journal->lines, 'debit')], route('journals.show', $journal));
    }

    private function damage(StockDamage $damage): array
    {
        $damage->load(['warehouse', 'lines.product.primaryUom']);

        return $this->make('⚠️', __('Damaged stock written off'), $damage->damage_number, $damage->damage_date, [
            [__('Warehouse'), $damage->warehouse?->name],
            [__('Reason'), $damage->reason],
        ], $damage->lines->map(fn (StockDamageLine $line): string => $this->quantityLine($line->product, (string) $line->quantity).' = '.DisplayNumber::money((string) $line->total_cost)),
            [__('Value lost'), $this->sum($damage->lines, 'total_cost')], route('stock-damages.show', $damage));
    }

    private function advance(SalaryAdvance $advance): array
    {
        $advance->load(['employee', 'cashBankAccount']);

        return $this->make('🤝', __('Salary advance'), $advance->advance_number, $advance->advance_date, [
            [__('Employee'), $advance->employee?->name],
            [__('Paid from'), $advance->cashBankAccount?->display_name],
            [__('Note'), $advance->note],
        ], [], [__('Amount'), (string) $advance->amount], route('salary-advances.show', $advance));
    }

    private function payroll(PayrollRun $run): array
    {
        $run->load(['cashBankAccount', 'lines.employee']);
        $period = $run->period_start->equalTo($run->period_end)
            ? $run->period_start->toDateString()
            : $run->period_start->toDateString().' → '.$run->period_end->toDateString();

        return $this->make('👷', __('Salaries paid'), $run->payroll_number, $run->pay_date, [
            [__('For'), $period],
            [__('Paid from'), $run->cashBankAccount?->display_name],
            [__('Earned'), $this->money($run->total('gross_pay'))],
            [__('Advances taken back'), DecimalMath::isPositive($run->total('advances_deducted')) ? $this->money($run->total('advances_deducted')) : null],
        ], $run->lines->sortBy(fn (PayrollLine $line): string => (string) $line->employee?->name)
            ->map(fn (PayrollLine $line): string => e((string) $line->employee?->name).' — '.trans_choice(':count day|:count days', $line->full_days, ['count' => $line->full_days])
                .($line->half_days > 0 ? ' + '.trans_choice(':count half day|:count half days', $line->half_days, ['count' => $line->half_days]) : '')
                .' = '.DisplayNumber::money((string) $line->net_pay)),
            [__('Handed over'), $run->total('net_pay')], route('payrolls.show', $run));
    }

    private function loan(Loan $loan): array
    {
        return $this->make('🏦', __('Loan'), $loan->loan_number, $loan->start_date, [
            [__('Lender'), $loan->lender],
            [__('How'), $loan->funding->label()],
            [__('Purpose'), $loan->purpose],
            [__('Usual installment'), $loan->installment_amount !== null ? $this->money((string) $loan->installment_amount) : null],
            [__('Due every month on day'), $loan->due_day !== null ? (string) $loan->due_day : null],
        ], [], [__('Amount'), (string) $loan->amount], route('loans.show', $loan));
    }

    private function loanPayment(LoanPayment $payment): array
    {
        $payment->load(['loan', 'cashBankAccount']);

        return $this->make('🏦', __('Loan installment paid'), $payment->payment_number, $payment->payment_date, [
            [__('Lender'), $payment->loan?->lender],
            [__('Paid from'), $payment->cashBankAccount?->display_name],
            [__('Interest'), DecimalMath::isPositive((string) $payment->interest_amount) ? $this->money((string) $payment->interest_amount) : null],
            [__('Off the loan'), $this->money((string) $payment->principal_amount)],
            [__('Still owed'), $payment->loan ? $this->money($this->loans->outstanding($payment->loan)) : null],
        ], [], [__('Paid'), (string) $payment->amount], route('loans.show', $payment->loan_id));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function asset(FixedAsset $asset, string $event, array $context): array
    {
        $asset->load(['cashBankAccount', 'saleCashBankAccount', 'loan']);
        $url = route('assets.show', $asset);

        if ($event === 'sold') {
            $price = (string) ($context['sale_price'] ?? $asset->sale_price);
            $result = DecimalMath::sub($price, (string) $asset->purchase_price);

            return $this->make('🏷️', __('Asset sold'), $asset->asset_number, $context['sold_date'] ?? $asset->sold_date, [
                [__('Asset'), $asset->name],
                [__('Buyer'), $context['buyer'] ?? $asset->buyer],
                [__('Paid into'), $asset->saleCashBankAccount?->display_name],
                [__('Cost'), $this->money((string) $asset->purchase_price)],
                [DecimalMath::isNegative($result) ? __('Loss on sale') : __('Profit on sale'), $this->money(ltrim($result, '-'))],
            ], [], [__('Sale price'), $price], $url);
        }

        if ($event === 'sale_reversed') {
            return $this->make('↩️', __('Asset sale undone'), $asset->asset_number, $context['sold_date'] ?? null, [
                [__('Asset'), $asset->name],
                [__('Buyer'), $context['buyer'] ?? null],
            ], [], isset($context['sale_price']) ? [__('Sale price'), (string) $context['sale_price']] : null, $url);
        }

        return $this->make('🛠️', __('Fixed asset'), $asset->asset_number, $asset->purchase_date, [
            [__('Asset'), $asset->name],
            [__('Category'), $asset->category->label()],
            [__('How'), $asset->funding->label()],
            [__('Paid from'), $asset->cashBankAccount?->display_name],
            [__('Down payment'), DecimalMath::isPositive((string) $asset->down_payment) ? $this->money((string) $asset->down_payment) : null],
            [__('Loan'), $asset->loan ? $asset->loan->loan_number.' — '.$asset->loan->lender.' ('.$this->money((string) $asset->loan->amount).')' : null],
        ], [], [__('Price'), (string) $asset->purchase_price], $url);
    }

    private function maintenance(AssetMaintenance $job): array
    {
        $job->load(['fixedAsset', 'cashBankAccount']);

        return $this->make('🔧', __('Asset maintenance'), $job->maintenance_number, $job->maintenance_date, [
            [__('Asset'), $job->fixedAsset?->name],
            [__('Work done'), $job->work_done],
            [__('Done by'), $job->done_by],
            [__('Paid from'), $job->cashBankAccount?->display_name],
        ], [], [__('Cost'), (string) $job->cost], route('assets.show', $job->fixed_asset_id));
    }

    /**
     * @param  list<array{0: string, 1: string|null}>  $facts
     * @param  iterable<string>  $lines  already HTML-escaped
     * @param  array{0: string, 1: string}|null  $total
     * @return array{icon: string, title: string, number: string, date: string, facts: list<array{0: string, 1: string|null}>, lines: list<string>, total: array{0: string, 1: string}|null, url: string}
     */
    private function make(string $icon, string $title, string $number, CarbonInterface|string|null $date, array $facts, iterable $lines, ?array $total, string $url): array
    {
        return [
            'icon' => $icon,
            'title' => $title,
            'number' => $number,
            'date' => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date,
            'facts' => $facts,
            'lines' => array_values(collect($lines)->all()),
            'total' => $total,
            'url' => $url,
        ];
    }

    /**
     * @param  array{icon: string, title: string, number: string, date: string, facts: list<array{0: string, 1: string|null}>, lines: list<string>, total: array{0: string, 1: string}|null, url: string}  $card
     */
    private function render(array $card, string $event, ?string $who): string
    {
        $out = $event === 'reversed'
            ? ['↩️ <b>'.e(__('REVERSED')).' — '.e($card['title']).'</b>']
            : [$card['icon'].' <b>'.e($card['title']).'</b>'];
        $out[] = '<code>'.e($card['number']).'</code>'.($card['date'] !== '' ? ' · '.e($card['date']) : '');

        foreach ($card['facts'] as [$label, $value]) {
            if ($value !== null && $value !== '') {
                $out[] = e($label).': <b>'.e($value).'</b>';
            }
        }

        if ($card['lines'] !== []) {
            $out[] = '';

            foreach (array_slice($card['lines'], 0, self::MAX_LINES) as $line) {
                $out[] = '• '.$line;
            }

            if (count($card['lines']) > self::MAX_LINES) {
                $out[] = e(__('… and :count more', ['count' => count($card['lines']) - self::MAX_LINES]));
            }
        }

        if ($card['total'] !== null) {
            $out[] = '';
            $out[] = '💰 <b>'.e($card['total'][0]).': '.e($this->money($card['total'][1])).'</b>';
        }

        $out[] = '';

        if ($who !== null) {
            $out[] = '👤 '.e($event === 'reversed' ? __('Reversed by :name', ['name' => $who]) : $who);
        }

        $out[] = '<a href="'.e($card['url']).'">'.e(__('Open in :app', ['app' => config('app.name')])).'</a>';

        return implode("\n", $out);
    }

    /** "1,000 NOS Coconut (large)" — escaped. */
    private function quantityLine(?Model $product, string $quantity): string
    {
        return '<b>'.e(DisplayNumber::quantity($quantity).' '.$product?->primaryUom?->code).'</b> '.e((string) $product?->name);
    }

    /** " (12.5 KG)" when a second measure was recorded, in the product's own second unit. */
    private function secondary(?Model $product, mixed $quantity): string
    {
        return $quantity === null ? '' : ' ('.e(DisplayNumber::quantity((string) $quantity).' '.$product?->secondaryUom?->code).')';
    }

    private function money(string $amount): string
    {
        return config('accounting.base_currency').' '.DisplayNumber::money($amount);
    }

    /**
     * @param  Collection<int, Model>  $rows
     */
    private function sum(Collection $rows, string $column): string
    {
        return $rows->reduce(fn (string $sum, Model $row): string => DecimalMath::add($sum, (string) ($row->{$column} ?? '0')), DecimalMath::zero());
    }

    private function method(CustomerReceipt|SupplierPayment $document): ?string
    {
        return (string) $document->payment_method?->label().($document->cheque_number ? ' #'.$document->cheque_number : '');
    }

    /** Who posted it, or for a reversal who reversed it. */
    private function who(Model $model, string $event): ?string
    {
        $id = $event === 'reversed'
            ? ($model->reversed_by ?? $model->reversed_by_user_id ?? null)
            : ($model->posted_by ?? $model->created_by ?? null);

        return $id === null ? null : User::query()->whereKey($id)->value('name');
    }
}
