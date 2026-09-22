<?php

namespace App\Models;

use Database\Factories\ProductAccountMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which accounts one product's movements post to.
 *
 * The only place a product is connected to the chart of accounts. Nothing in purchasing,
 * sales, production or inventory knows an account code.
 */
class ProductAccountMapping extends Model
{
    /** @use HasFactory<ProductAccountMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'inventory_account_id',
        'cogs_account_id',
        'revenue_account_id',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    /**
     * Reserved for sales invoicing. No revenue journal is created in this phase.
     *
     * @return BelongsTo<Account, $this>
     */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }
}
