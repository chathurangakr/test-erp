<?php

namespace Tests\Feature\Reports;

use App\Actions\Accounting\PostJournalEntry;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Report exports (2026-10-25): every report downloads as PDF and Excel with the same figures and
 * filters as its screen, and only for someone who may see that report.
 */
class ReportExportsTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'reports.profit-loss.export', 'reports.balance-sheet.export', 'reports.cash-bank-position.export',
        'reports.inventory-valuation.export', 'reports.inventory-gl-reconciliation.export', 'reports.product-profitability.export',
        'trial-balance.export', 'cash-bank-book.export', 'ar-aging.export', 'unbilled-deliveries.export',
        'ap-aging.export', 'grni-reconciliation.export', 'inventory.stock.export',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    }

    /** The first worksheet of an .xlsx response, as XML text. */
    private function sheet(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xlsx');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'A valid .xlsx (zip) file.');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $sheet;
    }

    #[Test]
    public function every_report_downloads_as_pdf_and_excel(): void
    {
        $admin = User::factory()->admin()->create();
        $cash = Account::query()->where('code', '1110')->firstOrFail();

        foreach ([...self::ROUTES, 'general-ledger.export'] as $name) {
            $query = $name === 'general-ledger.export' ? ['account_id' => $cash->id] : [];

            $pdf = $this->actingAs($admin)->get(route($name, [...$query, 'format' => 'pdf']));
            $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent(), $name);
            $this->assertStringContainsString('attachment; filename=', (string) $pdf->headers->get('Content-Disposition'));

            $xlsx = $this->actingAs($admin)->get(route($name, [...$query, 'format' => 'xlsx']));
            $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $this->assertStringContainsString('<sheetData>', $this->sheet((string) $xlsx->getContent()), $name);
        }
    }

    #[Test]
    public function the_export_carries_the_reports_own_figures_as_numbers(): void
    {
        $admin = User::factory()->admin()->create();
        $journal = JournalEntry::factory()->create(['journal_date' => '2026-09-10']);
        $journal->lines()->createMany([
            ['account_id' => Account::where('code', '1110')->value('id'), 'debit' => '125000.50', 'credit' => '0'],
            ['account_id' => Account::where('code', '4110')->value('id'), 'debit' => '0', 'credit' => '125000.50'],
        ]);
        app(PostJournalEntry::class)->handle($journal, $admin->id);

        $sheet = $this->sheet((string) $this->actingAs($admin)
            ->get(route('reports.profit-loss.export', ['from' => '2026-09-01', 'to' => '2026-09-30', 'format' => 'xlsx']))
            ->getContent());

        $this->assertStringContainsString('Profit and loss', $sheet);
        $this->assertStringContainsString('From 2026-09-01 to 2026-09-30', $sheet);
        // A real number in Excel, rounded to cents — it adds up there.
        $this->assertStringContainsString('<v>125000.50</v>', $sheet);
    }

    #[Test]
    public function only_someone_who_may_see_a_report_may_download_it(): void
    {
        $this->get(route('ar-aging.export'))->assertRedirect(route('login'));
        $clerk = User::factory()->withPermissions(Permission::ArView)->create();

        $this->actingAs($clerk)->get(route('ar-aging.export', ['format' => 'xlsx']))->assertOk();
        $this->actingAs($clerk)->get(route('reports.profit-loss.export'))->assertForbidden();
        $this->actingAs($clerk)->get(route('ap-aging.export'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get(route('reports.profit-loss.export', ['format' => 'docx']))->assertSessionHasErrors('format');
    }
}
