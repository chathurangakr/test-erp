<?php

namespace App\Services\Telegram;

use App\Support\DecimalMath;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;

/**
 * The monthly report as Telegram messages: the same figures as the email (App\Mail\MonthlyUpdate),
 * section by section, packed into as few messages as Telegram's 4,096-character limit allows.
 * Formats only; every figure comes from App\Services\Reporting\MonthlyUpdateReport.
 */
class MonthlyUpdateMessage
{
    /** Below Telegram's 4,096, leaving room for the HTML entities escaping adds. */
    private const LIMIT = 3800;

    private const TOP_EXPENSES = 8;

    /**
     * @param  array<string, mixed>  $report  MonthlyUpdateReport::build()
     * @return list<string>
     */
    public static function forTelegram(array $report, ?string $companyName): array
    {
        $p = $report['performance'];
        $prev = $report['previous'];
        $m = $report['money'];
        $month = CarbonImmutable::parse($report['from'])->translatedFormat('F Y');

        $sections = [];
        $sections[] = '📊 <b>'.e(__('Monthly report')).' — '.e($month).'</b>'.($companyName ? "\n<i>".e($companyName).'</i>' : '');

        $sections[] = self::block('💰', __('Sales & profit'), [
            self::row(__('Revenue'), $p['revenue'], self::change($p['revenue'], $prev['revenue'])),
            self::row(__('Cost of sales'), $p['cost_of_sales'], self::change($p['cost_of_sales'], $prev['cost_of_sales'])),
            self::row(__('Gross profit'), $p['gross_profit'], __('margin :percent%', ['percent' => $p['gross_margin_percent']])),
            self::row(__('Operating expenses'), $p['operating_expenses'], self::change($p['operating_expenses'], $prev['operating_expenses'])),
            '<b>'.e(str_starts_with($p['net_profit'], '-') ? __('Net loss') : __('Net profit')).': '.e(self::money($p['net_profit'])).'</b> ('.e(self::change($p['net_profit'], $prev['net_profit'])).' '.e(__('on the month before')).')',
        ]);

        $sections[] = self::block('🧾', __('Sales by customer').' — '.__(':count invoices · :total', ['count' => $report['sales']['invoices'], 'total' => DisplayNumber::money($report['sales']['total'])]),
            self::list($report['sales']['customers']) ?: [e(__('No sales were invoiced this month.'))]);
        $sections[] = self::block('📥', __('Goods bought by supplier').' — '.__(':count goods receipts · :total', ['count' => $report['purchases']['receipts'], 'total' => DisplayNumber::money($report['purchases']['total'])]),
            self::list($report['purchases']['suppliers']) ?: [e(__('No goods were received this month.'))]);
        $sections[] = self::block('📦', __('Top products delivered'),
            self::list(array_map(fn (array $row): array => ['name' => $row['product_code'].' '.$row['product_name'], 'amount' => $row['sales_value']], $report['top_products']))
                ?: [e(__('No deliveries were posted this month.'))]);

        $sections[] = self::block('🔄', __('Money in & out'), [
            '⬇ '.self::row(__('Received from customers'), $m['from_customers']),
            '⬆ '.self::row(__('Paid to suppliers'), $m['to_suppliers']),
            '⬆ '.self::row(__('Expenses paid'), $m['expenses']),
            '⬆ '.self::row(__('Salaries paid'), $m['salaries']),
            '⬆ '.self::row(__('Salary advances'), $m['advances']),
            '⬆ '.self::row(__('Loan installments'), $m['loan_payments'], DecimalMath::isPositive($m['loan_interest']) ? __(':amount interest', ['amount' => DisplayNumber::money($m['loan_interest'])]) : null),
            '⬆ '.self::row(__('Asset maintenance'), $m['maintenance']),
        ]);

        $sections[] = self::block('🧮', __('Expenses by account'),
            self::list(array_slice($report['expenses'], 0, self::TOP_EXPENSES)) ?: [e(__('No expenses were recorded this month.'))]);

        $sections[] = self::block('🏦', __('Cash & bank').': '.self::money($report['cash_bank']['combined_total']),
            self::list(array_map(fn (array $row): array => ['name' => $row['name'], 'amount' => $row['balance']], $report['cash_bank']['rows'])));

        $sections[] = self::block('📒', __('Money owed'), [
            self::row(__('Customers owe us'), $report['owed']['receivable'], self::overdue($report['owed']['receivable_overdue'])),
            self::row(__('We owe suppliers'), $report['owed']['payable'], self::overdue($report['owed']['payable_overdue'])),
            self::row(__('Loans still owed'), $report['loans_owed']),
        ]);

        $production = [];

        foreach ($report['production'] as $run) {
            $made = array_map(fn (array $line): string => DisplayNumber::quantity($line['quantity']).' '.$line['uom'].' '.$line['name'], $run['outputs']);
            $production[] = '<b>'.e($run['type']).'</b> ('.e(trans_choice(':count run|:count runs', $run['runs'], ['count' => $run['runs']])).'): '.e(implode(', ', $made));
        }

        $sections[] = self::block('🏭', __('Production & stock'), [
            ...($production ?: [e(__('No production was posted this month.'))]),
            self::row(__('Stock value now'), $report['stock_value']),
            '↩️ '.e(__(':count returns, :amount credited', ['count' => $report['stock_events']['returns'], 'amount' => DisplayNumber::money($report['stock_events']['returns_credited'])])),
            '⚠️ '.e(__(':count write-offs, :amount lost', ['count' => $report['stock_events']['damages'], 'amount' => DisplayNumber::money($report['stock_events']['damaged_value'])])),
            '🛍️ '.e(__('Bags with customers')).': <b>'.e(DisplayNumber::quantity($report['bags_out'])).'</b>',
            '🛠️ '.e(__('Assets bought: :count (:value) · sold: :sold (:soldValue)', ['count' => $report['assets']['bought'], 'value' => DisplayNumber::money($report['assets']['bought_value']), 'sold' => $report['assets']['sold'], 'soldValue' => DisplayNumber::money($report['assets']['sold_value'])])),
        ]);

        $sections[] = '<a href="'.e(route('finance-dashboard.index')).'">'.e(__('Open :app', ['app' => config('app.name')])).'</a>';

        return self::pack($sections);
    }

    /**
     * Sections joined into messages, never splitting one.
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    private static function pack(array $sections): array
    {
        $messages = [''];

        foreach ($sections as $section) {
            $last = array_key_last($messages);
            $joined = $messages[$last] === '' ? $section : $messages[$last]."\n\n".$section;

            if (mb_strlen($joined) > self::LIMIT && $messages[$last] !== '') {
                $messages[] = $section;
            } else {
                $messages[$last] = $joined;
            }
        }

        return $messages;
    }

    /**
     * @param  list<string>  $lines  already escaped
     */
    private static function block(string $icon, string $title, array $lines): string
    {
        return $icon.' <b>'.e($title).'</b>'.($lines === [] ? '' : "\n".implode("\n", $lines));
    }

    private static function row(string $label, string $amount, ?string $note = null): string
    {
        return e($label).': <b>'.e(self::money($amount)).'</b>'.($note ? ' <i>('.e($note).')</i>' : '');
    }

    /**
     * @param  list<array{name: string, amount: string}>  $rows
     * @return list<string>
     */
    private static function list(array $rows): array
    {
        return array_map(fn (array $row): string => '• '.e($row['name']).' — <b>'.e(DisplayNumber::money($row['amount'])).'</b>', $rows);
    }

    private static function change(string $now, string $before): string
    {
        $diff = DecimalMath::money(DecimalMath::sub($now, $before));

        if (DecimalMath::isZero($diff)) {
            return __('no change');
        }

        return (DecimalMath::isPositive($diff) ? '▲ ' : '▼ ').DisplayNumber::money(ltrim($diff, '-'));
    }

    private static function overdue(string $amount): ?string
    {
        return DecimalMath::isPositive($amount) ? __(':amount overdue', ['amount' => DisplayNumber::money($amount)]) : null;
    }

    private static function money(string $amount): string
    {
        return config('accounting.base_currency').' '.DisplayNumber::money($amount);
    }
}
