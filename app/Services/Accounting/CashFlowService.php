<?php

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\AssetMaintenance;
use App\Models\CashBankAccount;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceRefund;
use App\Models\CustomerReceipt;
use App\Models\ExpenseVoucher;
use App\Models\FixedAsset;
use App\Models\FundTransfer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Models\SalesCreditNote;
use App\Models\SalesReturn;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceRefund;
use App\Models\SupplierPayment;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The cash flow statement (direct method) — read-only, nothing stored.
 *
 * Every posted line on a configured cash or bank account in the window is money in (debit) or
 * out (credit). Each is classified by the document its journal came from: operating (customers,
 * suppliers, expenses, wages, loan interest), investing (fixed assets bought and sold) or
 * financing (loans received and repaid). A loan payment is split: its interest is operating, the
 * rest financing. Moves between the factory's own cash and bank accounts are left out — they
 * change neither total. Reversals carry their original's source, so they net off in the same row.
 * Opening + net change = closing, which equals the cash and bank balances. See
 * docs/ACCOUNTING_RULES.md §37.
 */
class CashFlowService
{
    /**
     * Each kind of movement: [section, row label]. A source not listed is "Other movements".
     *
     * @var array<class-string, array{0: string, 1: string}>
     */
    private const ROWS = [
        CustomerReceipt::class => ['operating', 'Received from customers'],
        CustomerAdvance::class => ['operating', 'Advances from customers'],
        CustomerAdvanceRefund::class => ['operating', 'Advances given back to customers'],
        SalesReturn::class => ['operating', 'Money back for returns'],
        SalesCreditNote::class => ['operating', 'Money back for discounts'],
        SupplierPayment::class => ['operating', 'Paid to suppliers'],
        SupplierAdvance::class => ['operating', 'Advances to suppliers'],
        SupplierAdvanceRefund::class => ['operating', 'Advances returned by suppliers'],
        ExpenseVoucher::class => ['operating', 'Expenses paid'],
        PayrollRun::class => ['operating', 'Salaries paid'],
        SalaryAdvance::class => ['operating', 'Salary advances'],
        AssetMaintenance::class => ['operating', 'Repairs and maintenance'],
        Loan::class => ['financing', 'Loans received'],
        FixedAsset::class => ['investing', 'Fixed assets bought'],
    ];

    public const SECTIONS = ['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities', 'other' => 'Other movements'];

    public function __construct(private readonly AccountingBalanceService $balances) {}

    /**
     * @return array{from: string, to: string, opening: string, closing: string, net_change: string, sections: list<array{key: string, label: string, rows: list<array{label: string, amount: string}>, total: string}>, balanced: bool}
     */
    public function statement(CarbonInterface $from, CarbonInterface $to): array
    {
        $accountIds = CashBankAccount::query()->pluck('account_id')->unique()->values();
        $rows = [];

        $lines = JournalEntryLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('entry', fn ($entry) => $entry
                ->whereIn('status', [JournalEntryStatus::Posted->value, JournalEntryStatus::Reversed->value])
                ->whereDate('journal_date', '>=', $from->toDateString())
                ->whereDate('journal_date', '<=', $to->toDateString()))
            ->with('entry:id,source_type,source_id,reversal_of_id')
            ->get();

        foreach ($lines as $line) {
            $entry = $line->entry;

            // Cash to bank and back: the same money, not a flow.
            if ($entry->source_type === FundTransfer::class) {
                continue;
            }

            $amount = DecimalMath::sub((string) $line->debit, (string) $line->credit);

            foreach ($this->classify($entry, $amount) as [$section, $label, $part]) {
                $rows[$section][$label] = DecimalMath::add($rows[$section][$label] ?? DecimalMath::zero(), $part);
            }
        }

        $sections = [];
        $net = DecimalMath::zero();

        foreach (self::SECTIONS as $key => $label) {
            $sectionRows = [];
            $total = DecimalMath::zero();

            foreach ($rows[$key] ?? [] as $rowLabel => $value) {
                $sectionRows[] = ['label' => __($rowLabel), 'amount' => DecimalMath::money($value)];
                $total = DecimalMath::add($total, $value);
            }

            if ($sectionRows === [] && $key === 'other') {
                continue;
            }

            $sections[] = ['key' => $key, 'label' => __($label), 'rows' => $sectionRows, 'total' => DecimalMath::money($total)];
            $net = DecimalMath::add($net, $total);
        }

        $opening = $this->cashAndBank($accountIds->all(), Carbon::parse($from->toDateString())->subDay());
        $closing = $this->cashAndBank($accountIds->all(), Carbon::parse($to->toDateString()));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening' => DecimalMath::money($opening),
            'closing' => DecimalMath::money($closing),
            'net_change' => DecimalMath::money($net),
            'sections' => $sections,
            'balanced' => DecimalMath::isZero(DecimalMath::money(DecimalMath::sub(DecimalMath::add($opening, $net), $closing))),
        ];
    }

    /**
     * Where one cash or bank line belongs: [section, label, amount] parts (a loan payment splits).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function classify(JournalEntry $entry, string $amount): array
    {
        $type = $entry->source_type;

        if ($type === LoanPayment::class) {
            $payment = LoanPayment::query()->find($entry->source_id);
            $paid = (string) ($payment?->amount ?? '0');

            if ($payment !== null && DecimalMath::isPositive($paid) && DecimalMath::isPositive((string) $payment->interest_amount)) {
                $interest = DecimalMath::div(DecimalMath::mul($amount, (string) $payment->interest_amount), $paid);

                return [
                    ['operating', 'Loan interest paid', $interest],
                    ['financing', 'Loan repayments', DecimalMath::sub($amount, $interest)],
                ];
            }

            return [['financing', 'Loan repayments', $amount]];
        }

        if ($type === FixedAsset::class) {
            // The purchase is the asset's own journal (and its reversal); any other is a sale.
            $purchase = FixedAsset::query()->whereKey($entry->source_id)->value('journal_entry_id');
            $original = $entry->reversal_of_id ?? $entry->id;

            return [['investing', $original === $purchase ? 'Fixed assets bought' : 'Fixed assets sold', $amount]];
        }

        [$section, $label] = self::ROWS[$type] ?? ['other', 'Other movements (manual journals and the rest)'];

        return [[$section, $label, $amount]];
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function cashAndBank(array $accountIds, Carbon $asOf): string
    {
        $total = DecimalMath::zero();

        foreach ($accountIds as $id) {
            $total = DecimalMath::add($total, $this->balances->netBalance($id, $asOf));
        }

        return $total;
    }
}
