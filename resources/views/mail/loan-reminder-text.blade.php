@php
    use App\Support\DisplayNumber;
    $currency = config('accounting.base_currency');
@endphp
{{ $companyName }} — {{ __('Loan installments due') }}

@foreach ($items as $item)
- {{ $item['lender'] }} ({{ $item['loan_number'] }}): {{ __('Due :when — :date', ['when' => $item['when'], 'date' => $item['due_date']]) }}
@if ($item['installment'] !== null)
  {{ __('Usual installment') }}: {{ $currency }} {{ DisplayNumber::money($item['installment']) }}
@endif
  {{ __('Still owed') }}: {{ $currency }} {{ DisplayNumber::money($item['outstanding']) }}
  {{ $item['url'] }}
@endforeach
