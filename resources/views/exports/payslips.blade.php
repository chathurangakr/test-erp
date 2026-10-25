@php
    use App\Support\DisplayNumber;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslips</title>
    <style>
        @page { margin: 10mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #1f2b28; }
        .slip { page-break-after: always; position: relative; }
        .slip:last-child { page-break-after: auto; }
        .head { width: 100%; border-bottom: 2px solid #0f766e; padding-bottom: 4px; margin-bottom: 8px; }
        .company { font-size: 11pt; font-weight: bold; }
        .contact { font-size: 7pt; color: #5b6b66; }
        .title { font-size: 13pt; font-weight: bold; letter-spacing: 2px; text-align: right; }
        table.facts { width: 100%; margin-bottom: 8px; }
        table.facts td { padding: 1px 0; }
        .label { color: #5b6b66; }
        table.days { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.days th { background: #e7f1ec; text-align: left; padding: 3px 4px; font-size: 7.5pt; }
        table.days td { padding: 2px 4px; border-bottom: 1px solid #edf2f0; }
        .num { text-align: right; white-space: nowrap; }
        table.totals { width: 60%; margin-left: 40%; border-collapse: collapse; }
        table.totals td { padding: 3px 4px; }
        .net td { font-weight: bold; font-size: 10pt; border-top: 2px solid #1f2b28; }
        .void { color: #b91c1c; border: 2px solid #b91c1c; text-align: center; font-weight: bold; padding: 3px; margin-bottom: 6px; }
        .sign { width: 100%; margin-top: 28px; }
        .sign td { width: 50%; text-align: center; font-size: 7pt; padding-top: 3px; }
        .line { border-top: 1px solid #5b6b66; }
    </style>
</head>
<body>
@foreach ($slips as $slip)
    <div class="slip">
        @if ($slip['reversed'])
            <div class="void">REVERSED — this payment was undone</div>
        @endif
        <table class="head"><tr>
            @if ($logo)
                <td style="width: 44px;"><img src="{{ $logo }}" style="max-width: 40px; max-height: 40px;"></td>
            @endif
            <td>
                <div class="company">{{ $company->name }}</div>
                <div class="contact">{{ collect([$company->address, $company->phone])->filter()->implode(' · ') }}</div>
            </td>
            <td class="title">PAYSLIP</td>
        </tr></table>

        <table class="facts">
            <tr><td class="label">Employee</td><td><strong>{{ $slip['name'] }}</strong> ({{ $slip['code'] }})@if ($slip['nic']) · NIC {{ $slip['nic'] }}@endif</td></tr>
            <tr><td class="label">Period</td><td>{{ $slip['period_start'] }} to {{ $slip['period_end'] }}</td></tr>
            <tr><td class="label">Paid on</td><td>{{ $slip['pay_date'] }} · {{ $slip['paid_by'] }}@if ($slip['reference']) · {{ $slip['reference'] }}@endif</td></tr>
            <tr><td class="label">Payroll</td><td>{{ $slip['payroll_number'] }}</td></tr>
        </table>

        <table class="days">
            <thead><tr><th>Date</th><th>Day</th><th class="num">Day rate</th><th class="num">Pay</th></tr></thead>
            <tbody>
                @foreach ($slip['days'] as $day)
                    <tr><td>{{ $day['date'] }}</td><td>{{ $day['status'] }}</td><td class="num">{{ DisplayNumber::money($day['rate']) }}</td><td class="num">{{ DisplayNumber::money($day['pay']) }}</td></tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td>Full days / half days / leave</td><td class="num">{{ $slip['full_days'] }} / {{ $slip['half_days'] }} / {{ $slip['leave_days'] }}</td></tr>
            <tr><td>Earned</td><td class="num">{{ DisplayNumber::money($slip['gross_pay']) }}</td></tr>
            <tr><td>Advance taken back</td><td class="num">- {{ DisplayNumber::money($slip['advances_deducted']) }}</td></tr>
            <tr class="net"><td>Paid ({{ $currency }})</td><td class="num">{{ DisplayNumber::money($slip['net_pay']) }}</td></tr>
            <tr><td class="label">Advance still owed</td><td class="num">{{ DisplayNumber::money($slip['advance_still_owed']) }}</td></tr>
        </table>

        <table class="sign"><tr>
            <td><div class="line">Paid by</div></td>
            <td><div class="line">Received by ({{ $slip['name'] }})</div></td>
        </tr></table>
    </div>
@endforeach
</body>
</html>
