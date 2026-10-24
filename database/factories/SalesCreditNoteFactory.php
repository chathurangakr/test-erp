<?php

namespace Database\Factories;

use App\Enums\SalesCreditNoteKind;
use App\Enums\SalesCreditNoteStatus;
use App\Models\Customer;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A posted discount of 1,000 on an invoice. Real credit notes are written by
 * RecordSalesCreditNote, with their journal.
 *
 * @extends Factory<SalesCreditNote>
 */
class SalesCreditNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_note_number' => 'CRN-20260920-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'credit_date' => '2026-09-20',
            'customer_id' => Customer::factory(),
            'sales_invoice_id' => fn (array $attributes) => SalesInvoice::factory()->create(['customer_id' => $attributes['customer_id']])->id,
            'kind' => SalesCreditNoteKind::Discount,
            'amount' => '1000',
            'reason' => 'Agreed price reduction',
            'status' => SalesCreditNoteStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
