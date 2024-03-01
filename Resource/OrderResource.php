<?php

namespace App\Http\Resources;

use App\Models\Auction;
use App\Models\AuctionItem;
use App\Models\ListingItem;
use App\Models\Trade;
use App\Models\TradeListingItem;
use App\Models\TradeSubmittedOffer;
use App\Models\TradeSubmittedOfferItem;
use App\Models\WantedListItem;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Converts the object into the readable array.
     * @param request
     * @return array
     */
    public function toArray($request)
    {
        $userId = data_get(request()->user(), 'vendor.id');
        $items = [];
        $notes = collect([]);
        $tradeDetails = null;
        $acceptedOfferYour = null;
        $acceptedOfferTheir = null;
        if (! empty($this->items) && $this->items->count() > 0) {
            $orderableListingItem = $this->items[0]->orderableListingItem()->loadMissing(['inventory.vendor', 'inventory.packagingCondition', 'inventory.product.category']);
            if ($orderableListingItem instanceof TradeSubmittedOfferItem) {
                $tradeDetails = TradeSubmittedOffer::with(
                    'trade.offers.inventory.size',
                    'trade.offers.inventory.packagingCondition',
                    'trade.offers.inventory.product.category',
                    'shippingAddress',
                    'billingAddress',
                )->find($this->items[0]->orderableListingItem()->loadMissing(['inventory.vendor'])->trade_submitted_offer_id);
                $acceptedOfferYour = TradeSubmittedOffer::with(
                    'trade.offers.inventory.size',
                    'trade.offers.inventory.packagingCondition',
                    'trade.offers.inventory.product.category',
                    'trade.wants.product.sizes',
                    'trade.wants.product.packagingConditions',
                    'trade.wants.size',
                    'trade.wants.packagingCondition',
                    'shippingAddress',
                    'billingAddress'
                )->where([
                    'trade_id' => $tradeDetails->trade_id,
                    'sent_by_id' => $userId,
                    'status' => 'Accepted',
                ])->orderBy('created_at', 'desc')->first();
                $acceptedOfferTheir = TradeSubmittedOffer::with(
                    'trade.offers.inventory.size',
                    'trade.offers.inventory.packagingCondition',
                    'trade.offers.inventory.product.category',
                    'trade.wants.product.sizes',
                    'trade.wants.product.packagingConditions',
                    'trade.wants.size',
                    'trade.wants.packagingCondition',
                    'shippingAddress',
                    'billingAddress'
                )->where([
                    'trade_id' => $tradeDetails->trade_id,
                    'status' => 'Accepted',
                ])
                ->where('sent_by_id', '!=', $userId)
                ->orderBy('created_at', 'desc')->first();
            }
            foreach ($this->items as $item) {
                $orderableListingItem = $item->orderableListingItem()->loadMissing(['inventory.vendor', 'inventory.packagingCondition', 'inventory.product.category']);
                if ($item->order_itemable_type === 'App\\Models\\Auction') {
                    $auctionItems = $item->orderItemable->auctionItems;
                    $item->orderItemable->inventory = count($auctionItems) > 0 ? $auctionItems[0] : null;
                    $item->product_name = $auctionItems[0]->name;
                } elseif (
                    $orderableListingItem instanceof ListingItem
                    || $orderableListingItem instanceof AuctionItem
                ) {
                    $item['inventory'] = $orderableListingItem->inventory;
                    $item->product_name = $orderableListingItem?->inventory?->name;
                } elseif ($orderableListingItem instanceof TradeSubmittedOfferItem) {
                    $item['inventory'] = $orderableListingItem->inventory;
                    $item->product_name = $orderableListingItem?->inventory?->product?->name;
                } elseif ($item->order_itemable_type === 'App\\Models\\WantedListItem') {
                    $item->orderItemable->inventory = [
                        'sku' => $item->orderItemable->product_sku,
                        'name' => $item->orderItemable->product_name,
                        'size' => $item->orderItemable->product_size,
                    ];
                    $item->product_name = $item->orderItemable->product_name;
                } elseif ($item->order_itemable_type === 'App\\Models\\TradeListingItem') {
                    $item->orderItemable->inventory = [
                        'sku' => $item->orderItemable->product_sku,
                        'name' => $item->orderItemable->product_name,
                        'size' => $item->orderItemable->product_size,
                        'colorway' => $item->orderItemable->product_color,
                        'category' => $item->orderItemable->product_category,
                    ];
                    $item->product_name = $item->orderItemable->product_name;
                }
                array_push($items, $item);

                if ($item->notes->count() > 0) {
                    $notes = $notes->merge($item->notes);
                }
            }
        }

        return [
            'id' => $this->id,
            'customer' => $this->customer,
            'client_ip' => $this->client_ip,
            'order_id' => $this->order_id,
            'billing_address' => $this->billingAddress,
            'shipping_address' => $this->shippingAddress,
            'items' => $items,
            'item_keys' => count($items) > 1 ? $this->getOrderItemsMappingToDisplay() : null,
            'notes' => $this->notes->merge($notes)->sortBy('created_at')->all(),
            'quantity' => $this->quantity,
            'status' => $this->status,
            'total' => $this->total,
            'transaction' => $this->transaction,
            'type' => $this->type->label,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'trade_cash_paid' => $this->tradeCashPaidTransaction,
            'vendors' => $this->vendors,
            'trade' => $tradeDetails,
            'accepted_offer_your' => $acceptedOfferYour,
            'accepted_offer_their' => $acceptedOfferTheir,
            'payment_method' => $this->billingAddress->defaultPaymentMethod[0] ?? null,
        ];
    }
}
