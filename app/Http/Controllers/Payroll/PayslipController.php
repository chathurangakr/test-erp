<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\PayrollRun;
use App\Services\Payroll\Payslips;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payslips for a paid payroll: everyone in it, or one person (`?employee_id=`), as a page to print
 * or — `?format=pdf` — a PDF with one slip per A5 page. The PDF is in English (its font has no
 * Sinhala letters); the page follows the reader's language. See docs/PAYROLL.md.
 */
class PayslipController extends Controller
{
    public function __invoke(Request $request, PayrollRun $payroll, Payslips $payslips): Response
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(['pdf'])],
        ]);
        $employeeId = isset($data['employee_id']) ? (int) $data['employee_id'] : null;

        if (($data['format'] ?? null) !== 'pdf') {
            return Inertia::render('payroll/payslips/Print', [
                'payroll' => ['id' => $payroll->id, 'payroll_number' => $payroll->payroll_number],
                'slips' => $payslips->for($payroll, $employeeId),
                'employeeId' => $employeeId,
                'currency' => config('accounting.base_currency'),
            ])->toResponse($request);
        }

        App::setLocale('en');
        $slips = $payslips->for($payroll, $employeeId);
        $company = CompanyProfile::current();
        $logo = $company->logoImage();

        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => resource_path()]));
        $pdf->loadHtml(view('exports.payslips', [
            'slips' => $slips,
            'company' => $company,
            'logo' => $logo === null ? null : 'data:'.$logo['mime'].';base64,'.base64_encode($logo['bytes']),
            'currency' => config('accounting.base_currency'),
        ])->render(), 'UTF-8');
        $pdf->setPaper('A5', 'portrait');
        $pdf->render();

        $name = count($slips) === 1 ? $payroll->payroll_number.'-'.$slips[0]['code'] : $payroll->payroll_number;

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="payslip-'.$name.'.pdf"',
        ]);
    }
}
