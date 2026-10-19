@php
    use App\Support\DisplayNumber;

    $currency = config('accounting.base_currency');
    $p = $report['performance'];
    $netNegative = str_starts_with($p['net_profit'], '-');
    $topMax = max(1.0, ...array_map(fn ($r) => abs((float) $r['sales_value']), $report['top_products'] ?: [['sales_value' => '1']]));
    $cashMax = max(1.0, ...array_map(fn ($r) => abs((float) $r['balance']), $report['cash_bank']['rows'] ?: [['balance' => '1']]));
    // Embedded in the email itself (no remote image to block); a data URI when only rendered.
    $logoCid = $logo === null ? null : (isset($message)
        ? $message->embedData($logo['bytes'], 'logo', $logo['mime'])
        : 'data:'.$logo['mime'].';base64,'.base64_encode($logo['bytes']));

    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    $card = 'background:#ffffff;border:1px solid #e3ece8;border-radius:14px;';
    $label = 'font-size:12px;color:#5b6b66;text-transform:uppercase;letter-spacing:0.06em;';
    $big = 'font-size:20px;font-weight:700;color:#0f2e28;word-break:break-word;';
    $sectionTitle = 'font-size:17px;font-weight:700;color:#0f2e28;margin:0;';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ __('Weekly update') }}</title>
</head>
<body style="margin:0;padding:0;background:#eaf3ef;{{ $font }}">
<div style="display:none;max-height:0;overflow:hidden;">
    {{ __('Revenue :revenue · Net profit :net · Cash and bank :cash', ['revenue' => DisplayNumber::money($p['revenue']), 'net' => DisplayNumber::money($p['net_profit']), 'cash' => DisplayNumber::money($report['cash_bank']['combined_total'])]) }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eaf3ef;">
<tr><td align="center" style="padding:20px 6px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">

    {{-- ===================================================== header --}}
    <tr><td style="background:#0f766e;background-image:linear-gradient(135deg,#065f46 0%,#0f766e 45%,#0e7490 100%);border-radius:20px 20px 0 0;padding:32px 28px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            @if ($logoCid)
                <td width="76" valign="middle">
                    <div style="background:#ffffff;border-radius:16px;padding:8px;width:56px;height:56px;">
                        <img src="{{ $logoCid }}" alt="" width="56" height="56" style="display:block;width:56px;height:56px;object-fit:contain;">
                    </div>
                </td>
            @endif
            <td valign="middle" style="{{ $font }}">
                <div style="font-size:13px;color:#a7f3d0;letter-spacing:0.08em;text-transform:uppercase;">{{ $companyName }}</div>
                <div style="font-size:26px;font-weight:800;color:#ffffff;margin-top:4px;">{{ __('Weekly update') }}</div>
            </td>
        </tr></table>
        <div style="margin-top:18px;">
            <span style="display:inline-block;background:rgba(255,255,255,0.16);color:#ecfdf5;border:1px solid rgba(255,255,255,0.3);border-radius:999px;padding:6px 14px;font-size:13px;">
                📅 {{ $range }}
            </span>
        </div>
    </td></tr>

    <tr><td style="background:#f7fbf9;padding:20px 12px 8px;border-radius:0 0 20px 20px;">

        {{-- ===================================================== headline --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="{{ $card }}background:{{ $netNegative ? '#fff4f2' : '#ecfdf5' }};border-color:{{ $netNegative ? '#fecaca' : '#a7f3d0' }};">
            <tr><td style="padding:20px 22px;{{ $font }}">
                <div style="{{ $label }}">{{ $netNegative ? __('Net loss this week') : __('Net profit this week') }}</div>
                <div style="font-size:34px;font-weight:800;color:{{ $netNegative ? '#b91c1c' : '#047857' }};margin-top:4px;">
                    {{ $currency }} {{ DisplayNumber::money($p['net_profit']) }}
                </div>
                <div style="font-size:13px;color:#5b6b66;margin-top:6px;">
                    {{ __('Gross margin :percent%', ['percent' => $p['gross_margin_percent']]) }}
                </div>
            </td></tr>
        </table>

        {{-- ===================================================== sales & profit --}}
        <div style="height:26px;"></div>
        <p style="{{ $sectionTitle }}{{ $font }}">💰 {{ __('Sales & profit') }}</p>
        <div style="height:10px;"></div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach ([[['Revenue', $p['revenue'], '#0e7490'], ['Cost of sales', $p['cost_of_sales'], '#c2410c']], [['Gross profit', $p['gross_profit'], '#047857'], ['Operating expenses', $p['operating_expenses'], '#b45309']]] as $pair)
                <tr>
                    @foreach ($pair as [$name, $value, $accent])
                        <td width="50%" valign="top" style="padding:0 {{ $loop->first ? '6px' : '0' }} 12px {{ $loop->first ? '0' : '6px' }};">
                            <div style="{{ $card }}border-top:4px solid {{ $accent }};padding:14px 12px;{{ $font }}">
                                <div style="{{ $label }}">{{ __($name) }}</div>
                                <div style="{{ $big }}margin-top:4px;">{{ DisplayNumber::money($value) }}</div>
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>

        <div style="{{ $card }}padding:16px 18px;{{ $font }}">
            <div style="font-size:14px;font-weight:700;color:#0f2e28;">{{ __('Top products delivered') }}</div>
            @forelse ($report['top_products'] as $row)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                    <tr>
                        <td style="font-size:13px;color:#0f2e28;{{ $font }}"><strong>{{ $row['product_code'] }}</strong> <span style="color:#5b6b66;">{{ $row['product_name'] }}</span></td>
                        <td align="right" style="font-size:13px;font-weight:700;color:#0f2e28;white-space:nowrap;{{ $font }}">{{ DisplayNumber::money($row['sales_value']) }}</td>
                    </tr>
                    <tr><td colspan="2" style="padding-top:6px;">
                        <div style="background:#e6f0ec;border-radius:999px;height:8px;width:100%;">
                            <div style="background:#14b8a6;background-image:linear-gradient(90deg,#10b981,#06b6d4);border-radius:999px;height:8px;width:{{ max(2, round(abs((float) $row['sales_value']) / $topMax * 100)) }}%;"></div>
                        </div>
                    </td></tr>
                </table>
            @empty
                <div style="font-size:13px;color:#5b6b66;margin-top:8px;">{{ __('No deliveries were posted this week.') }}</div>
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
            @foreach ($report['cash_bank']['rows'] as $row)
                @php $overdrawn = str_starts_with($row['balance'], '-'); @endphp
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                    <tr>
                        <td style="font-size:13px;color:#0f2e28;{{ $font }}"><strong>{{ $row['name'] }}</strong> <span style="color:#5b6b66;">{{ $row['kind'] }}</span></td>
                        <td align="right" style="font-size:13px;font-weight:700;color:{{ $overdrawn ? '#b91c1c' : '#0f2e28' }};white-space:nowrap;{{ $font }}">{{ DisplayNumber::money($row['balance']) }}</td>
                    </tr>
                    <tr><td colspan="2" style="padding-top:6px;">
                        <div style="background:#e6eef3;border-radius:999px;height:8px;width:100%;">
                            <div style="background:{{ $overdrawn ? '#ef4444' : '#0ea5e9' }};border-radius:999px;height:8px;width:{{ max(2, round(abs((float) $row['balance']) / $cashMax * 100)) }}%;"></div>
                        </div>
                    </td></tr>
                </table>
            @endforeach
        </div>

        {{-- ===================================================== money owed --}}
        <div style="height:26px;"></div>
        <p style="{{ $sectionTitle }}{{ $font }}">🧾 {{ __('Money owed') }}</p>
        <div style="height:10px;"></div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                @foreach ([['Customers owe us', $report['owed']['receivable'], $report['owed']['receivable_overdue'], '#7c3aed'], ['We owe suppliers', $report['owed']['payable'], $report['owed']['payable_overdue'], '#db2777']] as [$name, $total, $overdue, $accent])
                    <td width="50%" valign="top" style="padding:0 {{ $loop->first ? '6px' : '0' }} 0 {{ $loop->first ? '0' : '6px' }};">
                        <div style="{{ $card }}border-top:4px solid {{ $accent }};padding:14px 16px;{{ $font }}">
                            <div style="{{ $label }}">{{ __($name) }}</div>
                            <div style="{{ $big }}margin-top:4px;">{{ DisplayNumber::money($total) }}</div>
                            @if (DisplayNumber::money($overdue) !== '0.00')
                                <div style="display:inline-block;margin-top:8px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;">
                                    ⚠ {{ __(':amount overdue', ['amount' => DisplayNumber::money($overdue)]) }}
                                </div>
                            @else
                                <div style="display:inline-block;margin-top:8px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;">
                                    ✓ {{ __('Nothing overdue') }}
                                </div>
                            @endif
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>

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
            <div style="{{ $card }}padding:14px 16px;margin-bottom:12px;font-size:13px;color:#5b6b66;{{ $font }}">{{ __('No production was posted this week.') }}</div>
        @endforelse
        <div style="{{ $card }}border-top:4px solid #0d9488;padding:14px 16px;{{ $font }}">
            <div style="{{ $label }}">{{ __('Stock value now') }}</div>
            <div style="{{ $big }}margin-top:4px;">{{ $currency }} {{ DisplayNumber::money($report['stock_value']) }}</div>
        </div>

        {{-- ===================================================== button & footer --}}
        <div style="text-align:center;padding:28px 0 8px;">
            <a href="{{ route('finance-dashboard.index') }}" style="display:inline-block;background:#0f766e;background-image:linear-gradient(135deg,#059669,#0e7490);color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;border-radius:12px;padding:13px 28px;{{ $font }}">
                {{ __('Open :app', ['app' => $appName]) }} →
            </a>
        </div>
        <p style="font-size:12px;color:#7a8a85;text-align:center;line-height:1.6;margin:12px 8px 18px;{{ $font }}">
            {{ __('Sales and profit are for :range. Cash, money owed and stock are as at the end of the week. Profit counts invoiced sales only.', ['range' => $range]) }}<br>
            {{ __('Sent automatically every Sunday at 8 PM.') }}
        </p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
