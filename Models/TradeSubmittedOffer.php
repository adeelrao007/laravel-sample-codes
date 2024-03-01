<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TradeSubmittedOffer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'trade_id',
        'shipping_address_id',
        'billing_address_id',
        'price',
        'shipping_fee',
        'processing_fee',
        'cash_added',
        'tax',
        'total',
        'customer_vault_id',
        'sent_by_id',
        'received_by_id',
        'type',
        'card_details',
        'trade_fee',
        'cash_type',
        'condition',
        'parent_id',
        'status',
    ];

    protected $appends = [
        'offer_type',
        'offer_type_translation',
        'condition_translation',
        'offer_expiry',
        'theirs_items',
        'yours_items',
        'latest_offer',
    ];

    /**
     * Get offers latest child.
     */
    public function getLatestOfferAttribute()
    {
        return self::with(
            'trade.offers.inventory.size',
            'trade.offers.inventory.packagingCondition',
            'trade.offers.inventory.product.category',
            'trade.wants.product.sizes',
            'trade.wants.product.packagingConditions',
            'trade.wants.size',
            'trade.wants.packagingCondition',
            'shippingAddress',
            'billingAddress'
        )->where('parent_id', $this->id)->latest()->first();
    }

    /**
     * Get offers latest child.
     */
    public function offer_history()
    {
        if ($this->parent_id) {
            return $this->hasMany(self::class, 'parent_id', 'parent_id')->orderBy('created_at', 'desc');
        } else {
            return $this->hasMany(self::class, 'parent_id', 'id')->orderBy('created_at', 'desc');
        }
    }

    public function parentOffer()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function getTheirsItemsAttribute()
    {
        $theirVendorId = (data_get(auth()->user(), 'vendor.id') === $this->sent_by_id) ? $this->received_by_id : $this->sent_by_id;

        $query = $this->theirs_items = $this->items()->with([
            'inventory.size',
            'inventory.packagingCondition',
            'inventory.product.category',
        ])->where([
            'vendor_id' => $theirVendorId,
            'trade_submitted_offer_id' => $this->id,
        ]);

        return $query->get();
    }

    public function getYoursItemsAttribute()
    {
        $yourVendorId = (data_get(auth()->user(), 'vendor.id') === $this->sent_by_id) ? $this->sent_by_id : $this->received_by_id;

        return $this->yours_items = $this->items()->with([
            'inventory.size',
            'inventory.packagingCondition',
            'inventory.product.category',
        ])->where([
            'vendor_id' => $yourVendorId,
            'trade_submitted_offer_id' => $this->id,
        ])->get();
    }

    /**
     * Attach offer expiry date.
     */
    public function getOfferExpiryAttribute()
    {
        return $this->created_at ? $this->created_at->addDays(config('constants.trades.offer_expiry_days')) : null;
    }

    /**
     * Attach offer type as per user.
     */
    public function getOfferTypeAttribute()
    {
        return (data_get(auth()->user(), 'vendor.id') === $this->sent_by_id) ? 'sent' : 'received';
    }

    /**
     * Attach offer type as per user for translation.
     */
    public function getOfferTypeTranslationAttribute()
    {
        $counterOffer = ($this->type === config('constants.trades.counter_offer')) ? 'counter_' : '';

        return (data_get(auth()->user(), 'vendor.id') === $this->sent_by_id) ?
            'trades.'.$counterOffer.'offer_sent' : 'trades.'.$counterOffer.'offer_received';
    }

    /**
     * Attach offer condition translation.
     */
    public function getConditionTranslationAttribute()
    {
        return 'trades.'.$this->condition.'_trade';
    }

    /**
     * Scope a query to find offer by trade id.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $tradeId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTrade(Builder $query, $tradeId = false): Builder
    {
        return ($tradeId) ?
            $query->where('trade_id', $tradeId) : $query;
    }

    /**
     * Scope a query to find expired offers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByExpiredOffers(Builder $query): Builder
    {
        $offerExpiryDays = config('constants.trades.offer_expiry_days');

        return $query->whereIn('status', [
            config('constants.trades.offer_statuses.open'),
        ])
        ->whereRaw('DATE_ADD(created_at, INTERVAL '.$offerExpiryDays.' DAY) > date(now())');
    }

    /**
     * Scope a query to find offer by start date.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $startDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStartDate(Builder $query, $startDate = false): Builder
    {
        return ($startDate) ?
            $query->where('created_at', '>=', $startDate) : $query;
    }

    /**
     * Scope a query to find only parent offers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByParent(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope a query to find offer list by end date.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEndDate(Builder $query, $endDate = false): Builder
    {
        return ($endDate) ?
            $query->where('created_at', '<=', $endDate) : $query;
    }

    /**
     * Scope a query to sort offer list.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $orderBy
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByOrder(Builder $query, $orderBy = false): Builder
    {
        switch ($orderBy) {
            case config('constants.trades.order.recent_to_oldest'):
                return $query->orderBy('created_at', 'DESC');
            case config('constants.trades.order.oldest_to_recent'):
                return $query->orderBy('created_at', 'ASC');
            default:
                return $query->orderBy('created_at', 'DESC');
        }
    }

    /**
     * Scope a query to find trade list by status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $status ['live', 'draft', 'expired', 'completed']
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByLiveTrade(Builder $query, $status = ['live']): Builder
    {
        return (is_array($status) && ! empty($status) && $status !== '') ?
                    $query->whereHas('trade', function ($query) use ($status) {
                        $query->byStatus($status);
                    }) : $query;
    }

    /**
     * Get trade attached with received offer.
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id', 'id');
    }

    /**
     * Scope a query to find trade list by vendor id.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int $vendorId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByVendor(Builder $query, $vendorId): Builder
    {
        return ($vendorId) ?
            $query->whereHas('trade', function ($query) use ($vendorId) {
                $query->byVendor($vendorId);
            }) : $query;
    }

    /**
     * Get shipping address attached with received offer.
     */
    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id', 'id');
    }

    /**
     * Get billing address attached with received offer.
     */
    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id', 'id');
    }

    /**
     * Get sent by user details attached with received offer.
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id', 'id');
    }

    /**
     * Get received by user details attached with received offer.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id', 'id');
    }

    /**
     * Get sent by vendor details attached with received offer.
     */
    public function sentByVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'sent_by_id', 'id');
    }

    /**
     * Get received by vendor details attached with received offer.
     */
    public function receivedByVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'received_by_id', 'id');
    }

    /**
     * Get items with received offer.
     */
    public function items(): HasMany
    {
        return $this->hasMany(TradeSubmittedOfferItem::class, 'trade_submitted_offer_id', 'id');
    }

    /**
     * Scope a query to find product by name/category.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int $search
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByProduct(Builder $query, $search): Builder
    {
        return (! $search) ? $query :
            $query->whereHas('items.inventory.product', function ($query) use ($search) {
                $query->search($search);
            });
    }

    /**
     * Scope a query to find trade submitted offer by status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $status ('offer', 'counter offer')
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus(Builder $query, $status = []): Builder
    {
        return ($status && ! empty($status)) ?
            $query->whereIn('status', $status) : $query;
    }

    /**
     * Scope a query to find trade offer sent by user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSentBy(Builder $query, $userId = 0): Builder
    {
        return ($userId) ?
            $query->where('sent_by_id', $userId) : $query;
    }

    /**
     * Scope a query to find trade offer received by user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReceivedBy(Builder $query, $userId = 0): Builder
    {
        return ($userId) ?
            $query->where('received_by_id', $userId) : $query;
    }

    /**
     * Scope a query to find all trade offers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser(Builder $query, $userId = 0): Builder
    {
        return ($userId) ?
            $query->where('received_by_id', $userId)
                ->orWhere('sent_by_id', $userId) : $query;
    }

    /**
     * Scope a query to find trade offer by id.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeById(Builder $query, $id = 0): Builder
    {
        return ($id) ?
            $query->where('id', $id) : $query;
    }

    /**
     * Scope a query to find offers list by condition.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $condition
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCondition(Builder $query, $condition = false): Builder
    {
        if ($condition && ! empty($condition)) {
            $query->whereIn('condition', $condition);
        }

        return $query;
    }

    /**
     * Scope a query to find offers list by type (offer/counter offer).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType(Builder $query, $type = false): Builder
    {
        if ($type) {
            if (in_array($type, [config('constants.trades.offer_types.counter_sent'), config('constants.trades.offer_types.counter_received')])) {
                $query->where('type', '=', config('constants.trades.counter_offer'));
                $query->when(request()->user() && data_get(request()->user(), 'vendor.id') && $type === config('constants.trades.offer_types.counter_sent'), function ($q) {
                    $q->where('sent_by_id', '=', data_get(request()->user(), 'vendor.id'));
                })->when(request()->user() && data_get(request()->user(), 'vendor.id') && $type === config('constants.trades.offer_types.counter_received'), function ($q) {
                    $q->where('received_by_id', '=', data_get(request()->user(), 'vendor.id'));
                });
            } elseif ($type != 'all') {
                $query->where('type', '=', $type);
            }
        }

        return $query;
    }

    /**
     * Scope a query to find trade list by receivables (send/received by user).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type ['sent', 'received']
     * @param  string  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByReceivables(Builder $query, $type = false, $userId = 0): Builder
    {
        if ($type && $userId) {
            switch ($type) {
                case config('constants.trades.offer_types.sent'):
                    return $query->sentBy($userId)->byType([config('constants.trades.offer')]);
                case config('constants.trades.offer_types.received'):
                    return $query->receivedBy($userId)->byType([config('constants.trades.offer')]);
                case config('constants.trades.offer_types.counter_sent'):
                    return $query->sentBy($userId)->byType([config('constants.trades.counter_offer')]);
                case config('constants.trades.offer_types.counter_received'):
                    return $query->receivedBy($userId)->byType([config('constants.trades.counter_offer')]);
                case config('constants.trades.offer_types.all'):
                    return $query->byUser($userId);
            }
        }

        return $query;
    }
}
