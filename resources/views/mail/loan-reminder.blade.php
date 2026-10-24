@php
    use App\Support\DisplayNumber;

    $currency = config('accounting.base_currency');
    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ __('Loan installments due') }}</title>
</head>
<body style="margin:0;padding:0;background:#f5efe6;{{ $font }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5efe6;">
<tr><td align="center" style="padding:20px 6px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
    <tr><td style="background:#b45309;background-image:linear-gradient(135deg,#92400e 0%,#b45309 55%,#d97706 100%);border-radius:20px 20px 0 0;padding:28px;">
        <div style="font-size:13px;color:#fde68a;letter-spacing:0.08em;text-transform:uppercase;">{{ $companyName }}</div>
        <div style="font-size:24px;font-weight:800;color:#ffffff;margin-top:4px;">{{ __('Loan installments due') }}</div>
    </td></tr>
    <tr><td style="background:#fffaf3;padding:16px 12px;border-radius:0 0 20px 20px;">
        @foreach ($items as $item)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #f1e3cf;border-left:6px solid {{ $item['days_before'] === 0 ? '#dc2626' : ($item['days_before'] <= 2 ? '#d97706' : '#2563eb') }};border-radius:14px;margin-bottom:12px;">
                <tr><td style="padding:16px 18px;{{ $font }}">
                    <div style="font-size:12px;color:#7c6a55;text-transform:uppercase;letter-spacing:0.06em;">{{ __('Due :when — :date', ['when' => $item['when'], 'date' => $item['due_date']]) }}</div>
                    <div style="font-size:19px;font-weight:700;color:#3b2a14;margin-top:4px;">{{ $item['lender'] }}</div>
                    <div style="font-size:13px;color:#7c6a55;margin-top:2px;">{{ $item['loan_number'] }}</div>
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px;"><tr>
                        @if ($item['installment'] !== null)
                            <td style="padding-right:24px;{{ $font }}">
                                <div style="font-size:12px;color:#7c6a55;">{{ __('Usual installment') }}</div>
                                <div style="font-size:18px;font-weight:700;color:#3b2a14;">{{ $currency }} {{ DisplayNumber::money($item['installment']) }}</div>
                            </td>
                        @endif
                        <td style="{{ $font }}">
                            <div style="font-size:12px;color:#7c6a55;">{{ __('Still owed') }}</div>
                            <div style="font-size:18px;font-weight:700;color:#3b2a14;">{{ $currency }} {{ DisplayNumber::money($item['outstanding']) }}</div>
                        </td>
                    </tr></table>
                    <a href="{{ $item['url'] }}" style="display:inline-block;margin-top:14px;background:#b45309;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:9px 16px;border-radius:999px;">{{ __('Record the payment') }}</a>
                </td></tr>
            </table>
        @endforeach
        <p style="font-size:12px;color:#7c6a55;margin:8px 6px 0;{{ $font }}">{{ __('You get this 7 days before, 2 days before and on the due day, unless the installment is already recorded.') }}</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
