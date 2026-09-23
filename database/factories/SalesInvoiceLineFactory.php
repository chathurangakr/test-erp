<?php

namespace Database\Factories;

use App\Models\DeliveryLine;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInvoiceLine>
 */
class SalesInvoiceLineFactory extends Factory
{
    protected $model = SalesInvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_invoice_id' => SalesInvoice::factory(),
            'delivery_line_id' => DeliveryLine::factory(),
            'product_id' => Product::factory(),
            'description' => null,
            'amount' => '1000.000000',
        ];
    }

    /** Bill part of a delivered line. */
    public function forDeliveryLine(DeliveryLine $line, string $amount): static
    {
        return $this->state(fn (): array => [
            'delivery_line_id' => $line->id,
            'product_id' => $line->product_id,
            'amount' => $amount,
        ]);
    }
}
