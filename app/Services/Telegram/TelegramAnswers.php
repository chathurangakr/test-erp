<?php

namespace App\Services\Telegram;

use App\Enums\DeliveryStatus;
use App\Enums\LoanDocumentStatus;
use App\Enums\Permission;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\Loan;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\InventoryValuationReportService;
use App\Services\Loans\LoanBalances;
use App\Services\Reporting\MonthlyUpdateReport;
use App\Services\Reporting\WeeklyUpdateReport;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use App\Support\DisplayNumber;

/**
 * What the transactions bot answers to /stock, /cash, /today and /owed — read-only, from the
 * services that own each figure, and only what the asking person's ERP permissions allow (the
 * same permissions as the screens). Nothing is calculated here that a screen does not show.
 * See docs/TELEGRAM.md.
 *
 * Each answer is ['text' => HTML, 'buttons' => rows of inline buttons].
 */
class TelegramAnswers
{
    /** The commands, with the permission that unlocks each (any one of them). */
    public const COMMANDS = [
        'stock' => [Permission::InventoryView],
        'cash' => [Permission::CashView],
        'today' => [Permission::SalesView, Permission::ArView, Permission::PurchasingView, Permission::ProductionView, Permission::CashView],
        'owed' => [Permission::ArView, Permission::ApView, Permission::LoansView],
    ];

    /** Product buttons under /stock — Telegram allows 100; a screenful is plenty. */
    private const MAX_BUTTONS = 30;

    public function __construct(
        private readonly InventoryValuationReportService $inventory,
        private readonly CashBankBookService $cashBank,
        private readonly AccountsReceivableService $receivables,
        private readonly AccountsPayableService $payables,
        private readonly LoanBalances $loans,
        private readonly WeeklyUpdateReport $weekly,
        private readonly MonthlyUpdateReport $monthly,
    ) {}

    /**
     * The command list Telegram shows when someone types "/", in English (the default).
     *
     * @return array<string, string>
     */
    public static function menu(): array
    {
        return [
            'stock' => __('Stock on hand'),
            'cash' => __('Cash and bank balances'),
            'today' => __('Today so far'),
            'owed' => __('Who owes us and whom we owe'),
            'help' => __('What I can show you'),
        ];
    }

    public static function allows(User $user, string $command): bool
    {
        foreach (self::COMMANDS[$command] ?? [] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{text: string, buttons: list<list<array{text: string, callback_data: string}>>}
     */
    public function help(User $user): array
    {
        $lines = ['👋 <b>'.e(__('Hello :name', ['name' => $user->name])).'</b>', e(__('Tap a command or type it:')), ''];
        $menu = self::menu();

        foreach (array_keys(self::COMMANDS) as $command) {
            if (self::allows($user, $command)) {
                $lines[] = '/'.$command.' — '.e($menu[$command]);
            }
        }

        if (count($lines) === 3) {
            $lines[] = e(__('Your ERP account cannot see any of these yet. Ask an administrator.'));
        }

        return $this->answer($lines);
    }

    public function notAllowed(): array
    {
        return $this->answer(['🔒 '.e(__('Your ERP account is not allowed to see this.'))]);
    }

    /**
     * Stock on hand per product, all warehouses together, with a button per product for its
     * breakdown. Values only for someone who may see inventory reports.
     */
    public function stock(User $user): array
    {
        $report = $this->inventory->report();
        $withValue = $user->hasPermission(Permission::ReportsInventory);
        $products = [];

        foreach ($report['rows'] as $row) {
            $id = $row['product_id'];
            $products[$id] ??= ['name' => $row['product_name'], 'uom' => $row['primary_uom'], 'quantity' => DecimalMath::zero(), 'value' => DecimalMath::zero()];
            $products[$id]['quantity'] = DecimalMath::add($products[$id]['quantity'], $row['quantity']);
            $products[$id]['value'] = DecimalMath::add($products[$id]['value'], $row['value']);
        }

        $lines = ['📦 <b>'.e(__('Stock on hand')).'</b> · '.e(BusinessClock::todayString()), ''];
        $buttons = [];

        foreach ($products as $id => $product) {
            $lines[] = '• '.e($product['name']).': <b>'.e(DisplayNumber::quantity($product['quantity']).' '.$product['uom']).'</b>'
                .($withValue ? ' — '.e(DisplayNumber::money($product['value'])) : '');

            if (count($buttons) < self::MAX_BUTTONS) {
                $buttons[] = ['text' => mb_substr($product['name'], 0, 40), 'callback_data' => 'stock:'.$id];
            }
        }

        if ($products === []) {
            $lines[] = e(__('There is no stock on hand.'));
        } elseif ($withValue) {
            $lines[] = '';
            $lines[] = '💰 <b>'.e(__('Total value')).': '.e($this->money($report['value_total'])).'</b>';
        }

        if ($buttons !== []) {
            $lines[] = '';
            $lines[] = e(__('Tap a product to see it per warehouse.'));
        }

        return $this->answer($lines, array_chunk($buttons, 2));
    }

    /** One product per warehouse, with its second measure where it has one. */
    public function stockItem(User $user, int $productId): array
    {
        $rows = $this->inventory->report($productId)['rows'];
        $withValue = $user->hasPermission(Permission::ReportsInventory);

        if ($rows === []) {
            return $this->answer(['📦 '.e(__('There is no stock of that product now.'))]);
        }

        $lines = ['📦 <b>'.e($rows[0]['product_name']).'</b> · '.e(BusinessClock::todayString()), ''];
        $total = DecimalMath::zero();

        foreach ($rows as $row) {
            $total = DecimalMath::add($total, $row['quantity']);
            $lines[] = '🏬 '.e((string) $row['warehouse_code']).': <b>'.e(DisplayNumber::quantity($row['quantity']).' '.$row['primary_uom']).'</b>'
                .($row['secondary_quantity'] !== null ? ' ('.e(DisplayNumber::quantity($row['secondary_quantity']).' '.$row['secondary_uom']).')' : '')
                .($withValue ? ' — '.e(DisplayNumber::money($row['value'])) : '');
        }

        if (count($rows) > 1) {
            $lines[] = '';
            $lines[] = '<b>'.e(__('Total')).': '.e(DisplayNumber::quantity($total).' '.$rows[0]['primary_uom']).'</b>';
        }

        return $this->answer($lines);
    }

    public function cash(User $user): array
    {
        $summary = $this->cashBank->summary(BusinessClock::today());
        $lines = ['🏦 <b>'.e(__('Cash & bank')).'</b> · '.e(BusinessClock::todayString()), ''];

        foreach ($summary['rows'] as $row) {
            $lines[] = '• '.e($row['display_name']).' <i>('.e($row['kind_label']).')</i>: <b>'.e(DisplayNumber::money($row['balance'])).'</b>';
        }

        $lines[] = '';
        $lines[] = e(__('Cash :cash · Bank :bank', ['cash' => DisplayNumber::money($summary['cash_total']), 'bank' => DisplayNumber::money($summary['bank_total'])]));
        $lines[] = '💰 <b>'.e(__('Total')).': '.e($this->money($summary['combined_total'])).'</b>';

        return $this->answer($lines);
    }

    /** Today so far — each part only for someone who may see it. */
    public function today(User $user): array
    {
        $today = BusinessClock::today();
        $lines = ['📅 <b>'.e(__('Today so far')).'</b> · '.e($today->toDateString())];

        if ($user->hasPermission(Permission::SalesView)) {
            $deliveries = Delivery::query()->where('status', DeliveryStatus::Posted)->whereDate('delivery_date', $today->toDateString())->with('lines')->get();
            $value = $deliveries->flatMap->lines->reduce(fn (string $sum, DeliveryLine $line): string => DecimalMath::add($sum, (string) $line->sales_value), DecimalMath::zero());
            $lines[] = '';
            $lines[] = '🚚 '.e(__('Deliveries')).': <b>'.$deliveries->count().'</b> — '.e($this->money($value));
        }

        if ($user->hasPermission(Permission::ArView)) {
            $sales = $this->monthly->sales($today, $today);
            $lines[] = '🧾 '.e(__('Invoiced')).': <b>'.$sales['invoices'].'</b> — '.e($this->money($sales['total']));
        }

        if ($user->hasPermission(Permission::PurchasingView)) {
            $purchases = $this->monthly->purchases($today, $today);
            $lines[] = '📥 '.e(__('Goods received')).': <b>'.$purchases['receipts'].'</b> — '.e($this->money($purchases['total']));
        }

        if ($user->hasPermission(Permission::ProductionView)) {
            $production = $this->weekly->production($today, $today);
            $lines[] = '';
            $lines[] = '🏭 <b>'.e(__('Production')).'</b>';

            foreach ($production as $run) {
                $made = array_map(fn (array $line): string => DisplayNumber::quantity($line['quantity']).' '.$line['uom'].' '.$line['name'], $run['outputs']);
                $lines[] = '• '.e($run['type']).' ('.e(trans_choice(':count run|:count runs', $run['runs'], ['count' => $run['runs']])).'): '.e(implode(', ', $made));
            }

            if ($production === []) {
                $lines[] = e(__('Nothing produced yet today.'));
            }
        }

        if ($user->hasPermission(Permission::CashView)) {
            $money = $this->monthly->money($today, $today);
            $out = array_reduce(['to_suppliers', 'expenses', 'salaries', 'advances', 'loan_payments', 'maintenance'], fn (string $sum, string $key): string => DecimalMath::add($sum, $money[$key]), DecimalMath::zero());
            $lines[] = '';
            $lines[] = '🔄 <b>'.e(__('Money in & out')).'</b>';
            $lines[] = '⬇ '.e(__('Received from customers')).': <b>'.e(DisplayNumber::money($money['from_customers'])).'</b>';
            $lines[] = '⬆ '.e(__('Paid to suppliers')).': '.e(DisplayNumber::money($money['to_suppliers']));
            $lines[] = '⬆ '.e(__('Expenses paid')).': '.e(DisplayNumber::money($money['expenses']));
            $lines[] = '⬆ '.e(__('Salaries and advances')).': '.e(DisplayNumber::money(DecimalMath::add($money['salaries'], $money['advances'])));
            $lines[] = '⬆ '.e(__('Loans and maintenance')).': '.e(DisplayNumber::money(DecimalMath::add($money['loan_payments'], $money['maintenance'])));
            $lines[] = '<b>'.e(__('In less out')).': '.e($this->money(DecimalMath::sub($money['from_customers'], $out))).'</b>';
        }

        return $this->answer($lines);
    }

    /** Who owes us, whom we owe and what is left on loans — each for someone who may see it. */
    public function owed(User $user): array
    {
        $lines = ['🧾 <b>'.e(__('Money owed')).'</b> · '.e(BusinessClock::todayString())];

        foreach ([
            [Permission::ArView, __('Customers owe us'), $this->receivables->aging(), 'customer_name'],
            [Permission::ApView, __('We owe suppliers'), $this->payables->aging(), 'supplier_name'],
        ] as [$permission, $title, $aging, $name]) {
            if (! $user->hasPermission($permission)) {
                continue;
            }

            $overdue = DecimalMath::sub($aging['totals']['total'], $aging['totals']['current']);
            $lines[] = '';
            $lines[] = '<b>'.e($title).': '.e($this->money($aging['totals']['total'])).'</b>'
                .(DecimalMath::isPositive($overdue) ? ' — ⚠️ '.e(__(':amount overdue', ['amount' => DisplayNumber::money($overdue)])) : '');

            $rows = $aging['rows'];
            usort($rows, fn (array $a, array $b): int => DecimalMath::compare($b['total'], $a['total'], 6));

            foreach (array_slice($rows, 0, 10) as $row) {
                $late = DecimalMath::sub($row['total'], $row['current']);
                $lines[] = '• '.e((string) $row[$name]).': '.e(DisplayNumber::money($row['total']))
                    .(DecimalMath::isPositive($late) ? ' <i>('.e(__(':amount overdue', ['amount' => DisplayNumber::money($late)])).')</i>' : '');
            }
        }

        if ($user->hasPermission(Permission::LoansView)) {
            $loans = Loan::query()->where('status', LoanDocumentStatus::Posted)->orderBy('lender')->get()
                ->map(fn (Loan $loan): array => [$loan->lender, $this->loans->outstanding($loan)])
                ->filter(fn (array $loan): bool => DecimalMath::isPositive($loan[1]));
            $lines[] = '';
            $lines[] = '<b>'.e(__('Loans still owed')).': '.e($this->money($loans->reduce(fn (string $sum, array $loan): string => DecimalMath::add($sum, $loan[1]), DecimalMath::zero()))).'</b>';

            foreach ($loans as [$lender, $outstanding]) {
                $lines[] = '• '.e($lender).': '.e(DisplayNumber::money($outstanding));
            }
        }

        return $this->answer($lines);
    }

    /**
     * @param  list<string>  $lines  already escaped
     * @param  list<list<array{text: string, callback_data: string}>>  $buttons
     * @return array{text: string, buttons: list<list<array{text: string, callback_data: string}>>}
     */
    private function answer(array $lines, array $buttons = []): array
    {
        return ['text' => implode("\n", $lines), 'buttons' => $buttons];
    }

    private function money(string $amount): string
    {
        return config('accounting.base_currency').' '.DisplayNumber::money($amount);
    }
}
