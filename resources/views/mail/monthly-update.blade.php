@php
    use App\Support\DecimalMath;
    use App\Support\DisplayNumber;

    $currency = config('accounting.base_currency');
    $p = $report['performance'];
    $prev = $report['previous'];
    $m = $report['money'];
    $netNegative = str_starts_with($p['net_profit'], '-');
    // Embedded in the email itself (no remote image to block); a data URI when only rendered.
    $logoCid = $logo === null ? null : (isset($message)
        ? $message->embedData($logo['bytes'], 'logo', $logo['mime'])
        : 'data:'.$logo['mime'].';base64,'.base64_encode($logo['bytes']));

    // "▲ 12,000.00" against the month before — display only.
    $change = function (string $now, string $before, bool $upIsGood = true): array {
        $diff = DecimalMath::sub($now, $before);
        if (DecimalMath::isZero(DecimalMath::money($diff))) {
            return [__('no change'), '#5b6b66'];
        }
        $up = DecimalMath::isPositive($diff);
        return [($up ? '▲ ' : '▼ ').DisplayNumber::money(ltrim($diff, '-')), $up === $upIsGood ? '#047857' : '#b91c1c'];
    };
    $bars = function (array $rows): float {
        return max(1.0, ...array_map(fn ($r) => abs((float) $r['amount']), $rows ?: [['amount' => '1']]));
    };

    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    $card = 'background:#ffffff;border:1px solid #e3ece8;border-radius:14px;';
    $label = 'font-size:12px;color:#5b6b66;text-transform:uppercase;letter-spacing:0.06em;';
    $big = 'font-size:20px;font-weight:700;color:#0f2e28;word-break:break-word;';
    $sectionTitle = 'font-size:17px;font-weight:700;color:#0f2e28;margin:0;';
    $row = 'font-size:13px;color:#0f2e28;padding:7px 0;border-bottom:1px solid #eef3f1;'.$font;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ __('Monthly report') }}</title>
</head>
<body style="margin:0;padding:0;background:#eaf3ef;{{ $font }}">
<div style="display:none;max-height:0;overflow:hidden;">
    {{ __('Revenue :revenue · Net profit :net · Cash and bank :cash', ['revenue' => DisplayNumber::money($p['revenue']), 'net' => DisplayNumber::money($p['net_profit']), 'cash' => DisplayNumber::money($report['cash_bank']['combined_total'])]) }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eaf3ef;">
<tr><td align="center" style="padding:20px 6px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">

    {{-- ===================================================== header --}}
    <tr><td style="background:#1e3a8a;background-image:linear-gradient(135deg,#065f46 0%,#0f766e 40%,#1d4ed8 100%);border-radius:20px 20px 0 0;padding:32px 28px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            @if ($logoCid)
                <td width="76" valign="middle">
                    <div style="background:#ffffff;border-radius:16px;padding:8px;width:56px;height:56px;">
                        <img src="{{ $logoCid }}" alt="" width="56" height="56" style="display:block;width:56px;height:56px;object-fit:contain;">
                    </div>
                </td>
            @endif
            <td valign="middle" style="{{ $font }}">
                <div style="font-size:13px;color:#bfdbfe;letter-spacing:0.08em;text-transform:uppercase;">{{ $companyName }}</div>
                <div style="font-size:26px;font-weight:800;color:#ffffff;margin-top:4px;">{{ __('Monthly report') }}</div>
            </td>
        </tr></table>
        <div style="margin-top:18px;">
            <span style="display:inline-block;background:rgba(255,255,255,0.16);color:#eff6ff;border:1px solid rgba(255,255,255,0.3);border-radius:999px;padding:6px 14px;font-size:13px;">
                📅 {{ $monthName }}
            </span>
        </div>
    </td></tr>

    <tr><td style="background:#f7fbf9;padding:20px 12px 8px;border-radius:0 0 20px 20px;">

        {{-- ===================================================== headline --}}
        @php [$netChange, $netColour] = $change($p['net_profit'], $prev['net_profit']); @endphp
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="{{ $card }}background:{{ $netNegative ? '#fff4f2' : '#ecfdf5' }};border-color:{{ $netNegative ? '#fecaca' : '#a7f3d0' }};">
            <tr><td style="padding:20px 22px;{{ $font }}">
                <div style="{{ $label }}">{{ $netNegative ? __('Net loss for the month') : __('Net profit for the month') }}</div>
                <div style="font-size:34px;font-weight:800;color:{{ $netNegative ? '#b91c1c' : '#047857' }};margin-top:4px;">
                    {{ $currency }} {{ DisplayNumber::money($p['net_profit']) }}
                </div>
                <div style="font-size:13px;color:#5b6b66;margin-top:6px;">
                    {{ __('Gross margin :percent%', ['percent' => $p['gross_margin_percent']]) }} ·
                    <span style="color:{{ $netColour }};font-weight:600;">{{ $netChange }}</span> {{ __('on the month before') }}
                </div>
            </td></tr>
        </table>

        {{-- ===================================================== sales & profit --}}
        <div style="height:26px;"></div>
        <p style="{{ $sectionTitle }}{{ $font }}">💰 {{ __('Sales & profit') }}</p>
        <div style="height:10px;"></div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ([[['Revenue', 'revenue', '#0e7490', true], ['Cost of sales', 'cost_of_sales', '#c2410c', false]], [['Gross profit', 'gross_profit', '#047857', true], ['Operating expenses', 'operating_expenses', '#b45309', false]]] as $pair)
                <tr>
                    @foreach ($pair as [$name, $key, $accent, $upIsGood])
                        @php [$text, $colour] = $change($p[$key], $prev[$key], $upIsGood); @endphp
                        <td width="50%" valign="top" style="padding:0 {{ $loop->first ? '6px' : '0' }} 12px {{ $loop->first ? '0' : '6px' }};">
                            <div style="{{ $card }}border-top:4px solid {{ $accent }};padding:14px 12px;{{ $font }}">
                                <div style="{{ $label }}">{{ __($name) }}</div>
                                <div style="{{ $big }}margin-top:4px;">{{ DisplayNumber::money($p[$key]) }}</div>
                                <div style="font-size:12px;color:{{ $colour }};margin-top:4px;font-weight:600;">{{ $text }}</div>
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>

        {{-- ===================================================== customers & suppliers --}}
        @foreach ([
            ['🧾', __('Sales by customer'), __(':count invoices · :total', ['count' => $report['sales']['invoices'], 'total' => DisplayNumber::money($report['sales']['total'])]), $report['sales']['customers'], 'linear-gradient(90deg,#10b981,#06b6d4)', __('No sales were invoiced this month.')],
            ['📥', __('Goods bought by supplier'), __(':count goods receipts · :total', ['count' => $report['purchases']['receipts'], 'total' => DisplayNumber::money($report['purchases']['total'])]), $report['purchases']['suppliers'], 'linear-gradient(90deg,#f59e0b,#ef4444)', __('No goods were received this month.')],
            ['📦', __('Top products delivered'), null, array_map(fn ($r) => ['name' => $r['product_code'].' '.$r['product_name'], 'amount' => $r['sales_value']], $report['top_products']), 'linear-gradient(90deg,#8b5cf6,#06b6d4)', __('No deliveries were posted this month.')],
        ] as [$icon, $title, $subtitle, $rows, $gradient, $empty])
            @php $max = $bars($rows); @endphp
            <div style="height:12px;"></div>
            <div style="{{ $card }}padding:16px 18px;{{ $font }}">
                <div style="font-size:14px;font-weight:700;color:#0f2e28;">{{ $icon }} {{ $title }}</div>
                @if ($subtitle)
                    <div style="font-size:12px;color:#5b6b66;margin-top:2px;">{{ $subtitle }}</div>
                @endif
                @forelse ($rows as $item)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                        <tr>
                            <td style="font-size:13px;color:#0f2e28;{{ $font }}">{{ $item['name'] }}</td>
                            <td align="right" style="font-size:13px;font-weight:700;color:#0f2e28;white-space:nowrap;{{ $font }}">{{ DisplayNumber::money($item['amount']) }}</td>
                        </tr>
                        <tr><td colspan="2" style="padding-top:6px;">
                            <div style="background:#e6f0ec;border-radius:999px;height:8px;width:100%;">
                                <div style="background:#14b8a6;background-image:{{ $gradient }};border-radius:999px;height:8px;width:{{ max(2, round(abs((float) $item['amount']) / $max * 100)) }}%;"></div>
                            </div>
                        </td></tr>
                    </table>
                @empty
                    <div style="font-size:13px;color:#5b6b66;margin-top:8px;">{{ $empty }}</div>
                @endforelse
            </div>
        @endforeach

        {{-- ===================================================== money in & out --}}
        @php
            $out = [
                ['Paid to suppliers', $m['to_suppliers']],
                ['Expenses paid', $m['expenses']],
                ['Salaries paid', $m['salaries']],
                ['Salary advances', $m['advances']],
                ['Loan installments', $m['loan_payments']],
                ['Asset maintenance', $m['maintenance']],
            ];
            $outTotal = DecimalMath::money(array_reduce($out, fn ($sum, $r) => DecimalMath::add($sum, $r[1]), '0'));
            $flow = DecimalMath::money(DecimalMath::sub($m['from_customers'], $outTotal));
        @endphp
        <div style="height:26px;"></div>
        <p style="{{ $sectionTitle }}{{ $font }}">🔄 {{ __('Money in & out') }}</p>
        <div style="height:10px;"></div>
        <div style="{{ $card }}padding:16px 18px;{{ $font }}">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="{{ $row }}font-weight:700;color:#047857;">⬇ {{ __('Received from customers') }}</td>
                    <td align="right" style="{{ $row }}font-weight:700;color:#047857;white-space:nowrap;">{{ DisplayNumber::money($m['from_customers']) }}</td>
                </tr>
                @foreach ($out as [$name, $amount])
                    <tr>
                        <td style="{{ $row }}">⬆ {{ __($name) }}@if ($name === 'Loan installments' && DecimalMath::isPositive($m['loan_interest'])) <span style="color:#5b6b66;">({{ __(':amount interest', ['amount' => DisplayNumber::money($m['loan_interest'])]) }})</span>@endif</td>
                        <td align="right" style="{{ $row }}white-space:nowrap;">{{ DisplayNumber::money($amount) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td style="font-size:14px;font-weight:800;color:#0f2e28;padding-top:10px;{{ $font }}">{{ __('In less out') }}</td>
                    <td align="right" style="font-size:14px;font-weight:800;color:{{ str_starts_with($flow, '-') ? '#b91c1c' : '#047857' }};padding-top:10px;white-space:nowrap;{{ $font }}">{{ $currency }} {{ DisplayNumber::money($flow) }}</td>
                </tr>
            </table>
        </div>

        {{-- ===================================================== expenses --}}
        @php $expenseMax = $bars($report['expenses']); @endphp
        <div style="height:12px;"></div>
        <div style="{{ $card }}padding:16px 18px;{{ $font }}">
            <div style="font-size:14px;font-weight:700;color:#0f2e28;">🧮 {{ __('Expenses by account') }}</div>
            @forelse ($report['expenses'] as $item)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                    <tr>
                        <td style="font-size:13px;color:#0f2e28;{{ $font }}">{{ $item['name'] }}</td>
                        <td align="right" style="font-size:13px;font-weight:700;color:#0f2e28;white-space:nowrap;{{ $font }}">{{ DisplayNumber::money($item['amount']) }}</td>
                    </tr>
                    <tr><td colspan="2" style="padding-top:6px;">
                        <div style="background:#f3ece6;border-radius:999px;height:8px;width:100%;">
                            <div style="background:#f59e0b;border-radius:999px;height:8px;width:{{ max(2, round(abs((float) $item['amount']) / $expenseMax * 100)) }}%;"></div>
                        </div>
                    </td></tr>
                </table>
            @empty
                <div style="font-size:13px;color:#5b6b66;margin-top:8px;">{{ __('No expenses were recorded this month.') }}</div>
            @endforelse
        </div>

        {{-- ===================================================== cash & bank --}}
        <div style="height:26px;"></div>
        <p style="{{ $sectionTitle }}{{ $font }}">🏦 {{ __('Cash & bank') }}</p>
        <div style="height:10px;"></div>
        <div style="{{ $card }}padding:16px 18px;{{ $font }}">
            <div style="{{ $label }}">{{ __('Total in cash and bank') }}</div>
            <div style="font-size:26px;font-weight:800;color:#0e7490;margin-top:4px;">{{ $currency }} {{ DisplayNumber::money($report['cash_bank']['combined_total']) }}</div>
            <div style="font-size:13px;color:#5b6b66;margin-top:4px;">
                {{ __('Cash :cash · Bank :bank', ['cash' => DisplayNumber::money($report['cash_bank']['cash_total']), 'bank' => DisplayNumber::money($report['cash_bank']['bank_total'])]) }}
            </div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
                @foreach ($report['cash_bank']['rows'] as $item)
                    <tr>
                        <td style="{{ $row }}"><strong>{{ $item['name'] }}</strong> <span style="color:#5b6b66;">{{ $item['kind'] }}</span></td>
                        <td align="right" style="{{ $row }}font-weight:700;white-space:nowrap;color:{{ str_starts_with($item['balance'], '-') ? '#b91c1c' : '#0f2e28' }};">{{ DisplayNumber::money($item['balance']) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>

        {{-- ===================================================== money owed, loans, assets --}}
        <div style="height:26px;"></div>
        <p style="{{ $sectionTitle }}{{ $font }}">🧾 {{ __('Money owed') }}</p>
        <div style="height:10px;"></div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ([
                [['Customers owe us', $report['owed']['receivable'], $report['owed']['receivable_overdue'], '#7c3aed'], ['We owe suppliers', $report['owed']['payable'], $report['owed']['payable_overdue'], '#db2777']],
                [['Loans still owed', $report['loans_owed'], null, '#1d4ed8'], ['Bags with customers', null, null, '#0d9488']],
            ] as $pair)
                <tr>
                    @foreach ($pair as [$name, $total, $overdue, $accent])
                        <td width="50%" valign="top" style="padding:0 {{ $loop->first ? '6px' : '0' }} 12px {{ $loop->first ? '0' : '6px' }};">
                            <div style="{{ $card }}border-top:4px solid {{ $accent }};padding:14px 16px;{{ $font }}">
                                <div style="{{ $label }}">{{ __($name) }}</div>
                                <div style="{{ $big }}margin-top:4px;">{{ $total === null ? DisplayNumber::quantity($report['bags_out']) : DisplayNumber::money($total) }}</div>
                                @if ($overdue !== null && DisplayNumber::money($overdue) !== '0.00')
                                    <div style="display:inline-block;margin-top:8px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;">
                                        ⚠ {{ __(':amount overdue', ['amount' => DisplayNumber::money($overdue)]) }}
                                    </div>
                                @elseif ($overdue !== null)
                                    <div style="display:inline-block;margin-top:8px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;">
                                        ✓ {{ __('Nothing overdue') }}
                                    </div>
                                @endif
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
        @if ($report['assets']['bought'] > 0 || $report['assets']['sold'] > 0)
            <div style="{{ $card }}padding:14px 16px;font-size:13px;color:#0f2e28;{{ $font }}">
                🛠️ {{ __('Assets bought: :count (:value) · sold: :sold (:soldValue)', ['count' => $report['assets']['bought'], 'value' => DisplayNumber::money($report['assets']['bought_value']), 'sold' => $report['assets']['sold'], 'soldValue' => DisplayNumber::money($report['assets']['sold_value'])]) }}
            </div>
        @endif

        {{-- ===================================================== production & stock --}}
        <div style="height:26px;"></div>
        <p style="{{ $sectionTitle }}{{ $font }}">🏭 {{ __('Production & stock') }}</p>
        <div style="height:10px;"></div>
        @forelse ($report['production'] as $run)
            <div style="{{ $card }}padding:14px 16px;margin-bottom:12px;{{ $font }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                    <td style="font-size:15px;font-weight:700;color:#0f2e28;{{ $font }}">{{ $run['type'] }}</td>
                    <td align="right"><span style="display:inline-block;background:#e0f2fe;color:#0369a1;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;{{ $font }}">{{ trans_choice(':count run|:count runs', $run['runs'], ['count' => $run['runs']]) }}</span></td>
                </tr></table>
                @if ($run['inputs'] !== [])
                    <div style="font-size:12px;color:#5b6b66;margin-top:10px;">{{ __('Used') }}</div>
                    @foreach ($run['inputs'] as $line)
                        <div style="font-size:13px;color:#0f2e28;margin-top:3px;">➖ <strong>{{ DisplayNumber::quantity($line['quantity']) }} {{ $line['uom'] }}</strong> {{ $line['name'] }}</div>
                    @endforeach
                @endif
                <div style="font-size:12px;color:#5b6b66;margin-top:10px;">{{ __('Made') }}</div>
                @foreach ($run['outputs'] as $line)
                    <div style="font-size:13px;color:#0f2e28;margin-top:3px;">➕ <strong>{{ DisplayNumber::quantity($line['quantity']) }} {{ $line['uom'] }}</strong> {{ $line['name'] }}</div>
                @endforeach
            </div>
        @empty
            <div style="{{ $card }}padding:14px 16px;margin-bottom:12px;font-size:13px;color:#5b6b66;{{ $font }}">{{ __('No production was posted this month.') }}</div>
        @endforelse
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="50%" valign="top" style="padding:0 6px 0 0;">
                    <div style="{{ $card }}border-top:4px solid #0d9488;padding:14px 16px;{{ $font }}">
                        <div style="{{ $label }}">{{ __('Stock value now') }}</div>
                        <div style="{{ $big }}margin-top:4px;">{{ DisplayNumber::money($report['stock_value']) }}</div>
                    </div>
                </td>
                <td width="50%" valign="top" style="padding:0 0 0 6px;">
                    <div style="{{ $card }}border-top:4px solid #dc2626;padding:14px 16px;{{ $font }}">
                        <div style="{{ $label }}">{{ __('Returns & damage') }}</div>
                        <div style="font-size:13px;color:#0f2e28;margin-top:6px;">↩️ {{ __(':count returns, :amount credited', ['count' => $report['stock_events']['returns'], 'amount' => DisplayNumber::money($report['stock_events']['returns_credited'])]) }}</div>
                        <div style="font-size:13px;color:#0f2e28;margin-top:4px;">⚠️ {{ __(':count write-offs, :amount lost', ['count' => $report['stock_events']['damages'], 'amount' => DisplayNumber::money($report['stock_events']['damaged_value'])]) }}</div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- ===================================================== button & footer --}}
        <div style="text-align:center;padding:28px 0 8px;">
            <a href="{{ route('finance-dashboard.index') }}" style="display:inline-block;background:#0f766e;background-image:linear-gradient(135deg,#059669,#1d4ed8);color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;border-radius:12px;padding:13px 28px;{{ $font }}">
                {{ __('Open :app', ['app' => $appName]) }} →
            </a>
        </div>
        <p style="font-size:12px;color:#7a8a85;text-align:center;line-height:1.6;margin:12px 8px 18px;{{ $font }}">
            {{ __('Figures are for :month; ▲ ▼ compare with the month before. Cash and money owed are at the end of the month; loans, bags and stock as they stand now. Profit counts invoiced sales only.', ['month' => $monthName]) }}<br>
            {{ __('Sent automatically on the 1st of every month.') }}
        </p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
