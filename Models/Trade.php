<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Trade extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'status',
    ];

    protected $appends = [
        'new_offers',
        'expiry_date',
        'status_translation',
        'is_expired',
        'remaining_time',
        'remaining_hours',
    ];

    /**
     * Get trades.
     *
     * @param $vendorId
     * @param $searchedText
     * @param $tradeId
     * @param $status
     * @param $perPage
     * @return LengthAwarePaginator paginator with result set
     */
    public static function getTrades(
        $vendorId = 0,
        $searchedText = null,
        $tradeId = 0,
        $status = ['live'],
        $fromDate = false,
        $toDate = false,
        $perPage = 0,
        $orderBy = false
    ) {
        $perPage = $perPage ? $perPage : config('constants.trades.trade_draft_listing_items_per_page');

        return self::with(
            'offers.inventory.product.packagingConditions',
            'offers.inventory.product.sizes',
            'offers.inventory.size',
            'offers.inventory.packagingCondition',
            'wants.product.sizes',
            'wants.product.packagingConditions',
            'wants.size',
            'wants.packagingCondition',
            'watchlistItem.watchlist',
        )
            ->whereHas('offers.inventory.product', function ($query) use ($searchedText) {
                $query->search($searchedText);
            })
            ->orWhereHas('wants.product', function ($query) use ($searchedText) {
                $query->search($searchedText);
            })
            ->byVendor($vendorId)
            ->byId($tradeId)
            ->byStatus($status)
            ->byOrder($orderBy)
            ->byStartDate($fromDate)
            ->byEndDate($toDate)
            ->paginate($perPage);
    }

    /**
     * Get remaining_hours field.
     */
    public function getRemainingHoursAttribute()
    {
        if ($this->created_at) {
            $diff = Carbon::now()->diffInHours($this->created_at->addDays(config('constants.trades.trade_expiry_days')), false);
            if ($diff < 0) {
                return 0;
            }

            return $diff;
        }

        return 0;
    }

    /**
     * Get the trade's watchlist.
     */
    public function watchlistItem()
    {
        return $this->morphOne(WatchlistItem::class, 'watchlist_itemable');
    }

    /**
     * Get remaining_time field.
     */
    public function getRemainingTimeAttribute()
    {
        if ($this->created_at) {
            $endDate = $this->created_at->addDays(config('constants.trades.trade_expiry_days'));
            $diff = Carbon::now('UTC')->diffInHours($endDate, false);
            if ($diff <= 0) {
                return 'expired';
            }

            return Carbon::now()->diff($endDate)->format('%dd %hh');
        }

        return null;
    }

    /**
     * Attach trade expire status.
     */
    public function getIsExpiredAttribute()
    {
        $nowDateTime = Carbon::now()->format('Y-m-d H:i:s');
        $expiryDate = Carbon::createFromFormat('Y-m-d H:i:s', $this->created_at->addDays(config('constants.trades.trade_expiry_days')));

        return $expiryDate->lt($nowDateTime);
    }

    /**
     * Attach trade status translation.
     */
    public function getStatusTranslationAttribute()
    {
        return 'trades.'.($this->status === config('constants.trades.statuses.completed') ? ($this->status.'.'.config('constants.trades.statuses.completed')) : $this->status);
    }

    /**
     * Attach trade expiry date.
     */
    public function getExpiryDateAttribute()
    {
        return $this->created_at ? $this->created_at->addDays(config('constants.trades.trade_expiry_days')) : null;
    }

    /**
     * Attach new offers with trade object.
     */
    public function getNewOffersAttribute()
    {
        return $this->submittedOffers()->count();
    }

    /**
     * Scope a query to find expired trades.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByExpiredTrades(Builder $query): Builder
    {
        $tradeExpiryDays = config('constants.trades.trade_expiry_days');

        return $query->whereIn('status', [
            config('constants.trades.statuses.live'),
        ])
        ->whereRaw('DATE_ADD(created_at, INTERVAL '.$tradeExpiryDays.' DAY) > date(now())');
    }

    /**
     * Scope a query to find trade list by start date.
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
     * Scope a query to find trade list by end date.
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
     * Get the submitted offers.
     */
    public function submittedOffers(): HasMany
    {
        return $this->hasMany(TradeSubmittedOffer::class, 'trade_id', 'id');
    }

    /**
     * Get the active offers.
     */
    public function activeOffers()
    {
        return $this->submittedOffers()->where([
            'status' => config('constants.trades.offer_statuses.open'),
        ]);
    }

    /**
     * Sort trade submitted offers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $orderBy
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderBySubmittedOffers(Builder $query, $orderBy = 'DESC'): Builder
    {
        return $query->with('submittedOffers', function ($query) use ($orderBy) {
            $query->orderBy('created_at', $orderBy);
        });
    }

    /**
     * Get offers items list attached with trade listing.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(TradeOffer::class, 'trade_id', 'id');
    }

    public function newOffersCount()
    {
        return $this->submittedOffers()->where('created_at', '>=', Carbon::today())->count();
    }

    /**
     * Get wants list attached with trade listing.
     */
    public function wants(): HasMany
    {
        return $this->hasMany(TradeWant::class, 'trade_id', 'id');
    }

    /**
     * Get vendor details attached with trade listing.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    /**
     * Scope a query to sort trade list.
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
     * Scope a query to find trade list by trade condition.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $condition
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCondition(Builder $query, $condition = false): Builder
    {
        if ($condition && ! empty($condition)) {
            return $query->whereHas('submittedOffers', function ($query) use ($condition) {
                $query->byCondition($condition);
            });
        }

        return $query;
    }

    /**
     * Scope a query to find trade list by submitted offer status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $status ['offer', 'counter offer']
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySubmittedOfferStatus(Builder $query, $status = false): Builder
    {
        if ($status && ! empty($status)) {
            return $query->whereHas('submittedOffers', function ($query) use ($status) {
                $query->byStatus($status);
            });
        }

        return $query;
    }

    /**
     * Scope a query to find trade list by type (send/received by user).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type ['sent', 'received']
     * @param  string  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySubmittedOfferType(Builder $query, $type = false, $userId = 0): Builder
    {
        if ($type && $userId) {
            return $query->whereHas('submittedOffers', function ($query) use ($type, $userId) {
                switch ($type) {
                    case config('constants.trades.offer_types.sent'):
                        $query->sentBy($userId);
                        break;
                    case config('constants.trades.offer_types.received'):
                        $query->receivedBy($userId);
                        break;
                }
            });
        }

        return $query;
    }

    /**
     * Scope a query to search by type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeType($query, $type)
    {
        // I need this function to make morph work with watchlist items
        return $query;
    }

    /**
     * Scope a query to find trade list by status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $status ['live', 'draft', 'expired', 'completed']
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeMultipleStatus(Builder $query, $status = []): Builder
    {
        // I need this function to make morph work with watchlist items
        return (is_array($status) && ! empty($status) && $status !== '') ?
            $query->whereIn('status', $status) : $query;
    }

    /**
     * Scope a query to find trade list by status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $status ['live', 'draft', 'expired', 'completed']
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus(Builder $query, $status = []): Builder
    {
        return (is_array($status) && ! empty($status) && $status !== '') ?
            $query->whereIn('status', $status) : $query;
    }

    /**
     * Scope a query to find trade list by dates.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $fromDate
     * @param  string  $toDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCreatedAt(Builder $query, $fromDate = false, $toDate = false): Builder
    {
        if ($fromDate) {
            $query->where('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->where('created_at', '<=', $toDate);
        }

        return $query;
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
            $query->where('vendor_id', $vendorId) : $query;
    }

    /**
     * Scope a query to find trade list by trade id.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int $tradeId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeById(Builder $query, $tradeId): Builder
    {
        return ($tradeId) ?
            $query->where('id', $tradeId) : $query;
    }

    /**
     * Scope a query to find trade list with offer items by inventory id.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int $inventoryId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByInventory(Builder $query, $InventoryId): Builder
    {
        return $query->whereHas('offers', function ($query) use ($InventoryId) {
            $query->byInventory($InventoryId);
        });
    }

    /**
     * Scope a query to find trade list by applied filters.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array $filters - ['status', 'vendor_id']
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $query->when($filters['status'] ?? null, function ($query, $status) {
            $query->byStatus(explode(',', $status));
        })
        ->when($filters['vendor_id'] ?? null, function ($query, $vendorId) {
            $query->byVendor($vendorId);
        });

        return $query;
    }

    /**
     * Refund both orders of trade.
     * @return array
     */
    public function refund(): array
    {
        $refundResponse = [
            'success' => true,
            'errors' => [],
        ];
        $this->loadMissing('orders');
        foreach ($this->orders as $order) {
            $refunded = $order->refundFullOrder();
            if (! $refunded['success']) {
                $refundResponse['success'] = false;
                $refundResponse['errors'][] = $refunded['error'];
            }
        }
        if ($refundResponse['success']) {
            $this->status = config('constants.trades.statuses.refunded');
            $this->save();
        }

        return $refundResponse;
    }

    /**
     * Get Both Orders of Trade.
     * @return HasMany
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'orderable_id', 'id')->where('orderable_type', self::class);
    }
}
