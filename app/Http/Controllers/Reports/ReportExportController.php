<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Services\Reporting\ReportExports;
use App\Support\BusinessClock;
use App\Support\Exports\XlsxWriter;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads a report as PDF or Excel (`?format=pdf|xlsx`), with the same filters as its screen.
 * Each report has its own export route carrying the SAME permission as the report itself
 * (routes/exports.php); the route's `report` default says which. Exports are in English, the
 * language accountants and banks receive. See docs/REPORT_EXPORTS.md.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportExports $exports, XlsxWriter $xlsx): Response
    {
        $report = (string) $request->route('report');
        $format = $request->validate([
            'format' => ['nullable', Rule::in(['pdf', 'xlsx'])],
            'account_id' => [$report === 'general-ledger' ? 'required' : 'nullable', 'integer', 'exists:accounts,id'],
        ])['format'] ?? 'pdf';

        App::setLocale('en');
        $export = $exports->build($report, $request);
        $company = CompanyProfile::current();

        if ($format === 'xlsx') {
            return response($xlsx->write($export, $company->name), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$export['filename'].'.xlsx"',
            ]);
        }

        $logo = $company->logoImage();
        $html = view('exports.report', [
            'export' => $export,
            'company' => $company,
            'logo' => $logo === null ? null : 'data:'.$logo['mime'].';base64,'.base64_encode($logo['bytes']),
            'printed' => __('Printed :when by :name', ['when' => BusinessClock::display(now()), 'name' => $request->user()->name]),
        ])->render();

        // Nothing is fetched from elsewhere: the logo is inline and fonts are dompdf's own.
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => resource_path()]));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', ($export['landscape'] ?? false) ? 'landscape' : 'portrait');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'.pdf"',
        ]);
    }
}
