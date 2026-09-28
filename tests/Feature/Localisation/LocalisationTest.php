<?php

namespace Tests\Feature\Localisation;

use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Actions\Purchasing\SaveGoodsReceipt;
use App\Enums\GoodsReceiptStatus;
use App\Enums\ProductionType;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\RecordText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The full ERP in Sinhala and English.
 *
 * Screens follow each person's choice; saved records never do.
 */
class LocalisationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function sinhala(): array
    {
        return json_decode((string) file_get_contents(lang_path('si.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------- choosing a language

    #[Test]
    public function the_choice_is_saved_on_the_person_and_follows_them(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'si'])->assertRedirect();

        $this->assertSame('si', $user->fresh()->locale);

        // A fresh request — any device — without a cookie still comes back in Sinhala.
        $this->actingAs($user->fresh())->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'si'));
    }

    #[Test]
    public function nobody_changes_language_until_they_choose(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', config('app.locale')));
    }

    #[Test]
    public function the_sign_in_screen_can_be_switched_before_signing_in(): void
    {
        $this->post(route('locale.update'), ['locale' => 'si'])
            ->assertCookie('erp_locale', 'si', encrypted: false);

        $this->withUnencryptedCookie('erp_locale', 'si')->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'si'));
    }

    #[Test]
    public function only_supported_languages_are_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->assertNull($user->fresh()->locale);
    }

    #[Test]
    public function statuses_and_messages_are_shown_in_the_chosen_language(): void
    {
        App::setLocale('si');

        $this->assertSame($this->sinhala()['Posted'], GoodsReceiptStatus::Posted->label());

        App::setLocale('en');

        $this->assertSame('Posted', GoodsReceiptStatus::Posted->label());
    }

    // ------------------------------------------------------------- records stay English

    #[Test]
    public function saved_record_text_is_english_whatever_the_screen_language(): void
    {
        // Pretend someone translated a record text into Sinhala — it must still be saved in English.
        Lang::addLines(['*.Reversal of goods receipt :reference' => 'ආපසු හැරවීම :reference'], 'si');
        Lang::addLines(['*.Dehusking' => 'ලෙලි ගැසීම'], 'si');

        App::setLocale('si');

        $this->assertSame('Reversal of goods receipt GRN-1', RecordText::of('Reversal of goods receipt :reference', ['reference' => 'GRN-1']));
        $this->assertSame('Dehusking production X', RecordText::of(':type production :reference', ['type' => ProductionType::Dehusking, 'reference' => 'X']));
        $this->assertSame('si', App::getLocale(), 'The screen language is restored afterwards.');
    }

    #[Test]
    public function a_goods_receipt_reversed_by_a_sinhala_user_is_recorded_in_english(): void
    {
        Lang::addLines(['*.Reversal of goods receipt :reference' => 'ආපසු හැරවීම :reference'], 'si');

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $coconut = Product::factory()->create(['primary_uom_id' => $nos->id]);
        $warehouse = Warehouse::factory()->default()->create();
        $user = User::factory()->admin()->create(['locale' => 'si']);

        App::setLocale('si');

        $receipt = app(SaveGoodsReceipt::class)->handle(null, [
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-15',
            'transport_cost' => '0', 'loading_cost' => '0', 'other_cost' => '0',
            'lines' => [['product_id' => $coconut->id, 'quantity' => '10', 'unit_price' => '100']],
        ], $user->id);
        app(PostGoodsReceipt::class)->handle($receipt, $user->id);
        app(ReverseGoodsReceipt::class)->handle($receipt->fresh(), $user->id);

        $reversal = InventoryTransaction::query()->latest('id')->firstOrFail();
        $this->assertStringStartsWith('Reversal of goods receipt ', (string) $reversal->notes);
    }

    #[Test]
    public function no_saved_description_or_note_is_built_with_the_screen_translator(): void
    {
        $offenders = [];

        foreach (['app/Actions', 'app/Services'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory)));

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match("/'(description|notes|narration|memo)'\s*=>.*\b__\(/", $line)) {
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Saved text must use App\Support\RecordText::of(), not __(): '.implode(', ', $offenders));
    }

    #[Test]
    public function no_record_text_has_a_sinhala_translation(): void
    {
        preg_match_all("/RecordText::of\('((?:[^'\\\\]|\\\\.)+)'/", implode("\n", array_map('file_get_contents', glob(app_path('{Actions,Services}/**/*.php'), GLOB_BRACE))), $matches);

        $this->assertNotEmpty($matches[1]);
        $this->assertSame([], array_values(array_intersect($matches[1], array_keys($this->sinhala()))));
    }

    // ------------------------------------------------------------- completeness

    #[Test]
    public function every_translated_screen_word_has_a_sinhala_entry(): void
    {
        $sinhala = $this->sinhala();
        $missing = [];

        // Step 1: everything except the Finance screens (step 2) and simple mode (own word list).
        $roots = ['pages', 'components', 'layouts'];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path("js/{$root}")));

            foreach ($files as $file) {
                $path = str_replace(resource_path('js').'/', '', $file->getPathname());

                if (! str_ends_with($path, '.vue') || str_contains($path, 'finance/') || str_contains(strtolower($path), 'kiosk') || str_starts_with($path, 'components/ui/')) {
                    continue;
                }

                preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)+)'/", (string) file_get_contents($file->getPathname()), $matches);

                foreach ($matches[1] as $key) {
                    $key = stripslashes($key);

                    if (! array_key_exists($key, $sinhala)) {
                        $missing[] = "{$path}: {$key}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, "Screen words with no Sinhala entry in lang/si.json:\n".implode("\n", $missing));
    }

    #[Test]
    public function every_status_and_type_name_has_a_sinhala_entry(): void
    {
        $sinhala = $this->sinhala();
        $missing = [];

        foreach (glob(app_path('Enums/*.php')) as $file) {
            $enum = 'App\\Enums\\'.basename($file, '.php');

            if (! method_exists($enum, 'label') || ! method_exists($enum, 'cases')) {
                continue;
            }

            App::setLocale('en');

            foreach ($enum::cases() as $case) {
                if (! array_key_exists($case->label(), $sinhala)) {
                    $missing[] = "{$enum}::{$case->name} ({$case->label()})";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }
}
