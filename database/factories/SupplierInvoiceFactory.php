<?php

namespace Database\Factories;

use App\Enums\SupplierInvoiceStatus;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoice>
 */
class SupplierInvoiceFactory extends Factory
{
    protected $model = SupplierInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_invoice_number' => 'PINV-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'supplier_id' => Supplier::factory(),
            'supplier_invoice_number' => 'INV-'.$this->faker->unique()->numberBetween(1000, 999999),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'fiscal_period_id' => null,
            'status' => SupplierInvoiceStatus::Draft,
            'description' => null,
            'created_by' => null,
        ];
    }
}
