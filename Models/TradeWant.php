<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeWant extends Model
{
    use HasFactory;

    protected $fillable = [
        'trade_id',
        'product_id',
        'size_id',
        'packaging_condition_id',
        'year',
        'quantity',
    ];

    /**
     * Get product details.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    /**
     * Get attached size details.
     */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id', 'id');
    }

    /**
     * Get attached packaging condition details.
     */
    public function packagingCondition(): BelongsTo
    {
        return $this->belongsTo(PackagingCondition::class, 'packaging_condition_id', 'id');
    }
}
