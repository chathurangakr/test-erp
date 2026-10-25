@php
    use App\Support\DisplayNumber;
    $p = $report['performance'];
    $m = $report['money'];
    $currency = config('accounting.base_currency');
@endphp
{{ $companyName }} — {{ __('Monthly report') }} ({{ $monthName }})

{{ __('Sales & profit') }} ({{ $currency }})
- {{ __('Revenue') }}: {{ DisplayNumber::money($p['revenue']) }} ({{ __('month before') }} {{ DisplayNumber::money($report['previous']['revenue']) }})
- {{ __('Cost of sales') }}: {{ DisplayNumber::money($p['cost_of_sales']) }}
- {{ __('Gross profit') }}: {{ DisplayNumber::money($p['gross_profit']) }}
- {{ __('Operating expenses') }}: {{ DisplayNumber::money($p['operating_expenses']) }}
- {{ __('Net profit') }}: {{ DisplayNumber::money($p['net_profit']) }} ({{ __('month before') }} {{ DisplayNumber::money($report['previous']['net_profit']) }})

{{ __('Sales by customer') }}: {{ DisplayNumber::money($report['sales']['total']) }}
@foreach ($report['sales']['customers'] as $row)
  * {{ $row['name'] }}: {{ DisplayNumber::money($row['amount']) }}
@endforeach
{{ __('Goods bought by supplier') }}: {{ DisplayNumber::money($report['purchases']['total']) }}
@foreach ($report['purchases']['suppliers'] as $row)
  * {{ $row['name'] }}: {{ DisplayNumber::money($row['amount']) }}
@endforeach

{{ __('Money in & out') }}
- {{ __('Received from customers') }}: {{ DisplayNumber::money($m['from_customers']) }}
- {{ __('Advances from customers') }}: {{ DisplayNumber::money($m['advances_from_customers']) }}
- {{ __('Advances paid to suppliers') }}: {{ DisplayNumber::money($m['advances_to_suppliers']) }}
- {{ __('Paid to suppliers') }}: {{ DisplayNumber::money($m['to_suppliers']) }}
- {{ __('Expenses paid') }}: {{ DisplayNumber::money($m['expenses']) }}
- {{ __('Salaries paid') }}: {{ DisplayNumber::money($m['salaries']) }}
- {{ __('Salary advances') }}: {{ DisplayNumber::money($m['advances']) }}
- {{ __('Loan installments') }}: {{ DisplayNumber::money($m['loan_payments']) }}
- {{ __('Asset maintenance') }}: {{ DisplayNumber::money($m['maintenance']) }}

{{ __('Expenses by account') }}
@foreach ($report['expenses'] as $row)
- {{ $row['name'] }}: {{ DisplayNumber::money($row['amount']) }}
@endforeach

{{ __('Cash & bank') }}: {{ DisplayNumber::money($report['cash_bank']['combined_total']) }}
{{ __('Customers owe us') }}: {{ DisplayNumber::money($report['owed']['receivable']) }}
{{ __('We owe suppliers') }}: {{ DisplayNumber::money($report['owed']['payable']) }}
{{ __('Loans still owed') }}: {{ DisplayNumber::money($report['loans_owed']) }}
{{ __('Stock value now') }}: {{ DisplayNumber::money($report['stock_value']) }}

{{ route('finance-dashboard.index') }}
