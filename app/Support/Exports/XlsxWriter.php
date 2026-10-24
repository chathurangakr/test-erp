<?php

namespace App\Support\Exports;

use App\Support\DecimalMath;
use RuntimeException;
use ZipArchive;

/**
 * Writes one report export (App\Services\Reporting\ReportExports) as an Excel .xlsx file — the
 * Office Open XML format, written directly with ZipArchive, so no spreadsheet library is needed.
 *
 * One sheet: the company and report title, the filter lines, then each section's header row and
 * rows. Money and quantities are real numbers (they add up in Excel), shown with thousands
 * separators; money is rounded to cents and quantities to three decimals, from the decimal string
 * itself — never through a float.
 */
class XlsxWriter
{
    /** Cell styles, by index into styles.xml's cellXfs. */
    private const TEXT = 0;

    private const BOLD = 1;

    private const MONEY = 2;

    private const MONEY_BOLD = 3;

    private const QUANTITY = 4;

    private const QUANTITY_BOLD = 5;

    private const TITLE = 6;

    private const HEADER = 7;

    private const WHOLE = 8;

    private const WHOLE_BOLD = 9;

    /**
     * @param  array{title: string, details: list<string>, sections: list<array{heading: string|null, columns: list<array{label: string, type: string}>, rows: list<array{cells: list<mixed>, bold?: bool, indent?: int}>}>}  $export
     * @return string the .xlsx file's bytes
     */
    public function write(array $export, ?string $company): string
    {
        $rows = [];
        $widths = [];

        if ($company !== null && $company !== '') {
            $rows[] = [[$company, self::BOLD, 'text']];
        }

        $rows[] = [[$export['title'], self::TITLE, 'text']];

        foreach ($export['details'] as $line) {
            $rows[] = [[$line, self::TEXT, 'text']];
        }

        foreach ($export['sections'] as $section) {
            $rows[] = [];

            if ($section['heading'] !== null) {
                $rows[] = [[$section['heading'], self::BOLD, 'text']];
            }

            $rows[] = array_map(fn (array $column): array => [$column['label'], self::HEADER, 'text'], $section['columns']);

            foreach ($section['columns'] as $i => $column) {
                $widths[$i] = max($widths[$i] ?? 8, mb_strlen($column['label']) + 2);
            }

            foreach ($section['rows'] as $row) {
                $bold = (bool) ($row['bold'] ?? false);
                $cells = [];

                foreach ($section['columns'] as $i => $column) {
                    $value = $row['cells'][$i] ?? null;

                    if ($i === 0 && ($row['indent'] ?? 0) > 0 && $value !== null) {
                        $value = str_repeat('    ', (int) $row['indent']).$value;
                    }

                    $cells[] = [$value, $this->style($column['type'], $bold), $column['type']];
                    $widths[$i] = min(60, max($widths[$i] ?? 8, mb_strlen((string) $value) + 2));
                }

                $rows[] = $cells;
            }
        }

        return $this->package($this->sheet($rows, $widths));
    }

    private function style(string $type, bool $bold): int
    {
        return match ($type) {
            'money', 'percent' => $bold ? self::MONEY_BOLD : self::MONEY,
            'quantity' => $bold ? self::QUANTITY_BOLD : self::QUANTITY,
            default => $bold ? self::BOLD : self::TEXT,
        };
    }

    /**
     * @param  list<list<array{0: mixed, 1: int, 2: string}>>  $rows
     * @param  array<int, int>  $widths
     */
    private function sheet(array $rows, array $widths): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($widths !== []) {
            $xml .= '<cols>';

            foreach ($widths as $i => $width) {
                $xml .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $i + 1, $width);
            }

            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        foreach ($rows as $r => $cells) {
            $xml .= '<row r="'.($r + 1).'">';

            foreach ($cells as $c => [$value, $style, $type]) {
                $ref = $this->column($c).($r + 1);

                if ($value === null || $value === '') {
                    $xml .= '<c r="'.$ref.'" s="'.$style.'"/>';
                } elseif (in_array($type, ['money', 'quantity', 'percent'], true) && is_numeric($value)) {
                    $number = DecimalMath::round((string) $value, $type === 'quantity' ? 3 : 2);

                    // A whole quantity shows as "1,250", a part one as "12.500".
                    if ($type === 'quantity' && DecimalMath::isZero(DecimalMath::sub($number, DecimalMath::round($number, 0)))) {
                        $style = $style === self::QUANTITY_BOLD ? self::WHOLE_BOLD : self::WHOLE;
                    }

                    $xml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.$number.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function package(string $sheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Excel file could not be created.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** Fonts: regular, bold, title. Number formats: money #,##0.00, quantities #,##0.000 or #,##0. */
    private function styles(): string
    {
        $xf = fn (int $numFmt, int $font, bool $fill = false): string => sprintf(
            '<xf numFmtId="%d" fontId="%d" fillId="%d" borderId="0" xfId="0"%s%s/>',
            $numFmt, $font, $fill ? 2 : 0, $numFmt > 0 ? ' applyNumberFormat="1"' : '', $font > 0 ? ' applyFont="1"' : '',
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.000"/></numFmts>'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE7F1EC"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10">'
            .$xf(0, 0).$xf(0, 1).$xf(164, 0).$xf(164, 1).$xf(165, 0).$xf(165, 1).$xf(0, 2).$xf(0, 1, true).$xf(3, 0).$xf(3, 1)
            .'</cellXfs></styleSheet>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private function column(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    private function escape(string $value): string
    {
        // Control characters are not allowed in XML; everything else is escaped.
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
