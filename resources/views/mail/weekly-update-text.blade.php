@php
    use App\Support\DisplayNumber;
    $p = $report['performance'];
    $currency = config('accounting.base_currency');
@endphp
{{ $companyName }} — {{ __('Weekly update') }} ({{ $range }})

{{ __('Sales & profit') }} ({{ $currency }})
- {{ __('Revenue') }}: {{ DisplayNumber::money($p['revenue']) }}
- {{ __('Cost of sales') }}: {{ DisplayNumber::money($p['cost_of_sales']) }}
- {{ __('Gross profit') }}: {{ DisplayNumber::money($p['gross_profit']) }}
- {{ __('Operating expenses') }}: {{ DisplayNumber::money($p['operating_expenses']) }}
- {{ __('Net profit') }}: {{ DisplayNumber::money($p['net_profit']) }}
@foreach ($report['top_products'] as $row)
  * {{ $row['product_code'] }} {{ $row['product_name'] }}: {{ DisplayNumber::money($row['sales_value']) }}
@endforeach

{{ __('Cash & bank') }}: {{ DisplayNumber::money($report['cash_bank']['combined_total']) }}
@foreach ($report['cash_bank']['rows'] as $row)
- {{ $row['name'] }}: {{ DisplayNumber::money($row['balance']) }}
@endforeach

{{ __('Customers owe us') }}: {{ DisplayNumber::money($report['owed']['receivable']) }} ({{ __(':amount overdue', ['amount' => DisplayNumber::money($report['owed']['receivable_overdue'])]) }})
{{ __('We owe suppliers') }}: {{ DisplayNumber::money($report['owed']['payable']) }} ({{ __(':amount overdue', ['amount' => DisplayNumber::money($report['owed']['payable_overdue'])]) }})

{{ __('Production & stock') }}
@forelse ($report['production'] as $run)
- {{ $run['type'] }} ({{ $run['runs'] }}): @foreach ($run['outputs'] as $line){{ DisplayNumber::quantity($line['quantity']) }} {{ $line['uom'] }} {{ $line['name'] }}@if (! $loop->last), @endif @endforeach

@empty
- {{ __('No production was posted this week.') }}
@endforelse
- {{ __('Stock value now') }}: {{ DisplayNumber::money($report['stock_value']) }}

{{ route('finance-dashboard.index') }}
