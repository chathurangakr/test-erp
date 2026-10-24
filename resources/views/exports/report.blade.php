@php
    use App\Support\DisplayNumber;

    /** A figure as it reads on paper; text stays as it is. */
    $show = function (mixed $value, string $type): string {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => is_numeric($value) ? DisplayNumber::money((string) $value) : (string) $value,
            'quantity' => is_numeric($value) ? DisplayNumber::quantity((string) $value) : (string) $value,
            'percent' => is_numeric($value) ? DisplayNumber::money((string) $value).'%' : (string) $value,
            default => (string) $value,
        };
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $export['title'] }}</title>
    <style>
        @page { margin: 18mm 14mm 16mm 14mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2b28; }
        .letterhead { width: 100%; border-bottom: 2px solid #0f766e; padding-bottom: 6px; margin-bottom: 10px; }
        .letterhead td { vertical-align: middle; }
        .company { font-size: 13pt; font-weight: bold; color: #0f2e28; }
        .contact { font-size: 8pt; color: #5b6b66; }
        h1 { font-size: 14pt; margin: 4px 0 2px; color: #0f2e28; }
        .details { font-size: 8.5pt; color: #5b6b66; margin-bottom: 10px; }
        h2 { font-size: 10.5pt; margin: 12px 0 4px; }
        table.report { width: 100%; border-collapse: collapse; }
        table.report th { background: #e7f1ec; text-align: left; font-size: 8.5pt; padding: 5px 6px; border-bottom: 1px solid #b9cfc6; }
        table.report td { padding: 4px 6px; border-bottom: 1px solid #edf2f0; }
        table.report .num { text-align: right; white-space: nowrap; }
        table.report tr.bold td { font-weight: bold; background: #f6f9f8; }
        .negative { color: #b91c1c; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5pt; color: #7a8a85; }
        .footer .page:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        <table style="width: 100%;"><tr>
            <td>{{ $export['title'] }} · {{ $printed }}</td>
            <td style="text-align: right;">Page <span class="page"></span></td>
        </tr></table>
    </div>

    <table class="letterhead"><tr>
        @if ($logo)
            <td style="width: 60px;"><img src="{{ $logo }}" style="max-width: 52px; max-height: 52px;"></td>
        @endif
        <td>
            <div class="company">{{ $company->name }}</div>
            <div class="contact">{{ collect([$company->address, $company->phone, $company->email])->filter()->implode(' · ') }}</div>
        </td>
    </tr></table>

    <h1>{{ $export['title'] }}</h1>
    <div class="details">{{ implode(' · ', $export['details']) }}</div>

    @foreach ($export['sections'] as $section)
        @if ($section['heading'])
            <h2>{{ $section['heading'] }}</h2>
        @endif
        <table class="report">
            <thead><tr>
                @foreach ($section['columns'] as $column)
                    <th class="{{ $column['type'] === 'text' ? '' : 'num' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach ($section['rows'] as $row)
                    <tr class="{{ ($row['bold'] ?? false) ? 'bold' : '' }}">
                        @foreach ($section['columns'] as $i => $column)
                            @php $value = $row['cells'][$i] ?? null; @endphp
                            <td class="{{ $column['type'] === 'text' ? '' : 'num' }} {{ $column['type'] !== 'text' && is_string($value) && str_starts_with($value, '-') ? 'negative' : '' }}"
                                @if ($i === 0 && ($row['indent'] ?? 0) > 0) style="padding-left: {{ 6 + 12 * $row['indent'] }}px;" @endif>{{ $show($value, $column['type']) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
