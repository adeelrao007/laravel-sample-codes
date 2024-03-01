<?php

namespace App\Http\Controllers;

use App\Mail\Trade\CounterOfferReceived;
use App\Mail\Trade\CounterOfferSent;
use App\Mail\Trade\OfferAccepted;
use App\Mail\Trade\OfferDeclined;
use App\Mail\Trade\OfferDeleted;
use App\Mail\Trade\OfferReceived;
use App\Mail\Trade\OfferRestored;
use App\Mail\Trade\OfferSent;
use App\Mail\Trade\TradeDeleted;
use App\Mail\Trade\TradeListingCreated;
use App\Mail\Trade\TradeRelisted;
use App\Mail\Trade\TradeUpdated;
use App\Models\ListingItemOrder;
use App\Models\Product;
use App\Models\Size;
use App\Models\Trade;
use App\Models\TradeDismiss;
use App\Models\TradeOffer;
use App\Models\TradeSubmittedOffer;
use App\Models\TradeWant;
use App\Models\Vendor;
use App\Services\Payments\AcceptOfferService;
use App\Services\Traits\Commission;
use App\Services\Traits\HandleOrderItemStatus;
use App\Traits\RespondsWithJson;
use App\Traits\TradeTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

class TradesController extends Controller
{
    use TradeTrait, RespondsWithJson, Commission, HandleOrderItemStatus;

    private AcceptOfferService $acceptOfferService;

    public function __construct(AcceptOfferService $acceptOfferService)
    {
        $this->acceptOfferService = $acceptOfferService;
    }

    /**
     * Get Vendor want items
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/vendor/want-items",
     *      operationId="getVendorWantItems",
     *      summary="Get vendor want items",
     *      description="Returns list of vendor want items with pagination options. For combination types other filters are not required except page no",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="page",
     *          description="Page no to be displayed",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="category",
     *          description="Search category",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="size_types",
     *          description="Size type of want items (comma separated string)",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="sizes",
     *          description="Sizes of want items (comma separated string)",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="sort_by",
     *          description="Sorting of want items (Price - Low to High/High to Low, Size - Small to Large/Large to Small)",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="type",
     *          description="Return previous want items list as a combinations/general_items",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="results", type="object" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getVendorWantItems(Request $request): JsonResponse
    {
        $request->validate([
            'category' => 'string|nullable',
            'size_types' => 'string|nullable',
            'sizes' => 'string|nullable',
            'sort_by' => 'string|nullable',
            'type' => 'string|required|in:general_items,combinations',
        ]);

        $category = data_get($request, 'category', false);
        $sizeTypes = data_get($request, 'size_types', false);
        $sizes = data_get($request, 'sizes', false);
        $sortBy = data_get($request, 'sort_by', false);
        $pageItems = data_get($request, 'perPage', config('constants.trades.general_want_items_per_page'));
        $pageNo = data_get($request, 'page', 1);

        $orderByColumn = Str::contains($sortBy, 'date') ? 'created_at' : null;
        $sortByColumn = Str::contains($sortBy, 'price') ? 'product.retail_price' : null;
        $sortOrder = $sortBy ? explode('_', $sortBy)[1] : null;

        $wantItemsFunc = [
            config('constants.trades.type_combination')   => 'App\Models\WantCombination::getVendorWantCombinations',
            config('constants.trades.type_general_items') => 'App\Models\WantItem::getVendorWantItems',
        ];

        $vendor = $request->user()->vendor;
        if (! $vendor) {
            return $this->error('error.something_went_wrong');
        }

        [$query, $data] = $wantItemsFunc[$request->get('type')](
            $vendor,
            $category,
            $sizeTypes,
            $sizes,
            $orderByColumn,
            $sortOrder,
            $sortByColumn,
            $pageItems,
            $pageNo
        );

        $sortedCollection = $sortByColumn ? $data->sortBy([[$sortByColumn, $sortOrder]]) : $data;
        $paginatedItems = $query->paginate($pageItems);

        //set per page items
        $perPageItems = $paginatedItems->perPage();

        return $this->success(
            '',
            new LengthAwarePaginator(
                array_values(($sortedCollection->forPage($pageNo, $perPageItems))->toArray()),
                $paginatedItems->total(),
                $perPageItems,
                $pageNo
            )
        );
    }

    /**
     * Get Single Trade
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/{id}/{vendor_id}",
     *      operationId="getSingleTrade",
     *      summary="Get Single Trade",
     *      description="Returns trade information",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade ID",
     *          in="path",
     *          required=true,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="vendor_id",
     *          description="Vendor ID",
     *          in="path",
     *          required=false,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(ref="#/components/schemas/TradeResource")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getSingleTrade(Request $request, $id)
    {
        $tradeData = Trade::with(
            'offers.inventory.product',
            'offers.inventory.size',
            'offers.inventory.packagingCondition',
            'wants.product.sizes',
            'wants.product.packagingConditions',
            'wants.size',
            'wants.packagingCondition',
            'submittedOffers.items.inventory.size',
            'submittedOffers.items.inventory.packagingCondition',
            'submittedOffers.items.inventory.product.category',
            'watchlistItem.watchlist',
        )
        ->orderBySubmittedOffers()
        ->byVendor($request->vendor_id)
        ->byId($id)
        ->firstOrFail();

        return $this->success('', $tradeData);
    }

    /**
     * Get Trades
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades",
     *      operationId="getTrades",
     *      summary="Get Trades",
     *      description="Returns list of trades",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="status",
     *          description="Trade list status (draft, live etc)",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="trade_id",
     *          description="If passed only single trade is returned",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="search",
     *          description="Search Keyword",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="order_by",
     *          description="Order trades list",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="from_date",
     *          description="Filter list using from date",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="to_date",
     *          description="Filter list using to date",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="page",
     *          description="Page no to be displayed",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="per_page",
     *          description="Per Page items to be displayed",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(ref="#/components/schemas/TradeResource")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getTrades(Request $request)
    {
        $status = ($request->status) ? explode(',', $request->status) : ['live'];
        $trades = Trade::getTrades(
            data_get($request->user(), 'vendor.id'),
            $request->search,
            (int) $request->trade_id,
            $status,
            $request->from_date,
            $request->to_date,
            $request->per_page,
            $request->order_by
        );

        return $this->success('', $trades);
    }

    /**
     * Create Trade.
     *
     * @OA\Post(
     *      path="/api/trades",
     *      operationId="createTrade",
     *      tags={"Trades"},
     *      summary="Create new trade",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns new Trade",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"status"},
     *              @OA\Property(
     *                      property="offer_items",
     *                      type="array",
     *                      description="Offer Items with quantity",
     *                      @OA\Items(
     *                           @OA\Property(
     *                                      property="inventory_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="quantity",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                      ),
     *                ),
     *                @OA\Property(
     *                      property="want_items",
     *                      type="array",
     *                      description="Want Items with attributes like size, packaging condition etc",
     *                      @OA\Items(
     *                           @OA\Property(
     *                                      property="product_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="quantity",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="size_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="packaging_condition_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="year",
     *                                      type="Integer",
     *                                      example="2022"
     *                           ),
     *                      ),
     *                ),
     *                @OA\Property(
     *                      property="status",
     *                      type="String",
     *                      description="Trade status",
     *                      example="draft"
     *                )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="results", type="object" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Uprocessable Entity",
     *          @OA\JsonContent(ref="#/components/schemas/UnprocessableEntityResponse")
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return object
     * @throws Exception
     */
    public function createTrade(Request $request)
    {
        $userId = data_get($request->user(), 'vendor.id');

        $request->validate([
            'status' => 'required|in:draft,live',
        ]);

        $offerItems = $request->offer_items;
        $wantItems = $request->want_items;
        $status = $request->status;

        DB::beginTransaction();

        if (! empty($offerItems)) {
            $offerQuantity = $this->checkOfferItemsValidity($offerItems, $userId);

            if ($offerQuantity > config('constants.trades.allowed_offer_quantity')) {
                return $this->error('trades.total_quantities_offer_limit_'.config('constants.trades.allowed_offer_quantity'), 422);
            }
        }

        if (! empty($wantItems)) {
            $wantsQuantity = $this->checkWantItemsValidity($wantItems);

            if ($wantsQuantity > config('constants.trades.allowed_want_quantity')) {
                return $this->error('trades.total_quantities_want_limit_'.config('constants.trades.allowed_want_quantity'), 422);
            }
        }

        try {
            $trade = $this->createNewTrade(data_get($request->user(), 'vendor.id'), $status, $offerItems, $wantItems);
            if ($status !== 'draft') {
                $payload = $this->getTradeEmailPayload($trade->id);
                Mail::queue(new TradeListingCreated($request->user()->email, $payload));
            }

            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error('error.something_went_wrong');
        }

        return $this->success('', Trade::with('offers', 'wants')->find($trade->id));
    }

    /**
     * Update Trade.
     *
     * @OA\Put(
     *      path="/api/trades/{id}",
     *      operationId="updateTrade",
     *      tags={"Trades"},
     *      summary="Update trade",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns updated Trade",
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade ID",
     *          in="query",
     *          required=true,
     *          @OA\Schema(
     *              type="number"
     *          )
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"status"},
     *              @OA\Property(
     *                      property="offer_items",
     *                      type="array",
     *                      description="Offer Items with quantity",
     *                      @OA\Items(
     *                           @OA\Property(
     *                                      property="inventory_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="quantity",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                      ),
     *                ),
     *                @OA\Property(
     *                      property="want_items",
     *                      type="array",
     *                      description="Want Items with attributes like size, color etc",
     *                      @OA\Items(
     *                           @OA\Property(
     *                                      property="product_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="quantity",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="size_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="packaging_condition_id",
     *                                      type="Integer",
     *                                      example="1"
     *                           ),
     *                           @OA\Property(
     *                                      property="year",
     *                                      type="Integer",
     *                                      example="2022"
     *                           ),
     *                      ),
     *                ),
     *                @OA\Property(
     *                      property="status",
     *                      type="String",
     *                      description="Vendor trade status",
     *                      example="draft"
     *                )
     *          )
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="Successful operation",
     *          @OA\JsonContent(ref="#/components/schemas/TradeResource")
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Uprocessable Entity",
     *          @OA\JsonContent(ref="#/components/schemas/UnprocessableEntityResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @param $tradeId
     * @return object
     */
    public function updateTrade(Request $request, $tradeId): object
    {
        $trade = Trade::where([
            'vendor_id' => data_get($request->user(), 'vendor.id'),
            'id' => $tradeId,
        ])->firstOrFail();

        $userId = data_get($request->user(), 'vendor.id');

        $request->validate([
            'status' => 'required|in:draft,live',
        ]);

        $offerItems = $request->offer_items;
        $wantItems = $request->want_items;
        $status = $request->status;

        DB::beginTransaction();

        if (! empty($offerItems)) {
            $offerQuantity = $this->checkOfferItemsValidity($offerItems, $userId);

            if ($offerQuantity > config('constants.trades.allowed_offer_quantity')) {
                return $this->error('trades.total_quantities_limit_'.config('constants.trades.allowed_offer_quantity'), 422);
            }
        }

        if (! empty($wantItems)) {
            $wantsQuantity = $this->checkWantItemsValidity($wantItems);

            if ($wantsQuantity > config('constants.trades.allowed_want_quantity')) {
                return $this->error('trades.total_quantities_want_limit_'.config('constants.trades.allowed_want_quantity'), 422);
            }
        }

        try {
            $this->updateUserTrade($tradeId, $userId, $status, $offerItems, $wantItems);

            if ($trade->status === 'draft' && $request->status === 'live') {
                $payload = $this->getTradeEmailPayload($trade->id);
                Mail::queue(new TradeListingCreated($request->user()->email, $payload));
            } else {
                $payload = $this->getTradeEmailPayload($tradeId);
                Mail::queue(new TradeUpdated($request->user()->email, $payload));
            }

            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error('error.something_went_wrong');
        }

        return $this->success('', Trade::with('offers', 'wants')->find($tradeId));
    }

    /**
     * Delete multiple trades.
     *
     * @OA\Delete(
     *      path="/api/trades/multiple",
     *      operationId="deleteMultipleTrades",
     *      tags={"Trades"},
     *      summary="Delete multiple trades",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns Success/Error",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  required={"trade_ids"},
     *                  @OA\Property(
     *                      property="trade_ids",
     *                      type="String",
     *                      example="1,2,3",
     *                      description="Trade list Ids (comma separated multiple trade ids)"
     *                  )
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Server error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return object
     * @throws Exception
     */
    public function deleteMultipleTrades(Request $request): object
    {
        $request->validate([
            'trade_ids' => 'string|required',
        ]);

        $vendorId = data_get($request->user(), 'vendor.id');
        $tradeIds = explode(',', $request->trade_ids);

        try {
            DB::beginTransaction();
            foreach ($tradeIds as $tradeId) {
                $trade = Trade::where([
                    'vendor_id' => $vendorId,
                    'id' => trim($tradeId),
                ])->firstOrFail();
                //to control inventory stock
                $tradeOfferItems = TradeOffer::where([
                    'trade_id' => $tradeId,
                ])->get();

                foreach ($tradeOfferItems as $offerItem) {
                    //restore inventory stock
                    $this->updateInventoryStock($offerItem->inventory_id, true);
                }

                Trade::where([
                    'vendor_id' => $vendorId,
                    'id' => trim($tradeId),
                ])->firstOrFail()->delete();

                //delete all submitted offers when trade is updated
                $this->deleteOffers($vendorId, $trade->submittedOffers()->get());

                $payload = $this->getTradeEmailPayload((int) $tradeId);
                Mail::queue(new TradeDeleted($request->user()->email, $payload));
            }
            DB::commit();

            return $this->success('', ['status' => 'trades.success'], 200);
        } catch (Exception $e) {
            DB::rollBack();
            report($e);

            return $this->error('error.something_went_wrong');
        }
    }

    /**
     * To get the filters for trade browse page using table values.
     *
     ** @OA\Get(
     *      path="/api/trades/filters",
     *      summary="Get Trade page filters",
     *      description="Returns filters",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="categories",
     *          description="Comma separated categories",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="size_types",
     *          description="Comma separated size types",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="result", type="object", items="['size_types', 'sizes', 'priceRange']" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Server error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      )
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getFilters(Request $request): JsonResponse
    {
        try {
            // fetch all size types
            $sizeTypes = Size::select('type')->where('enabled', 1)->distinct()->orderBy('type')->get();
            $sizes = [];

            // fetch all sizes as per categories selection
            $request->whenHas('categories', function ($categories) use (&$sizes) {
                $categoriesArray = explode(',', $categories);
                foreach ($categoriesArray as $category) {
                    $sizes[$category] = Size::getTradeOfferItemsSizeByCategoryName($category);
                }
            });

            // filter sizes as per selected size types
            if (! empty($sizes)) {
                $request->whenHas('size_types', function ($selectedSizeTypes) use (&$sizes) {
                    $selectedSizeTypes = ($selectedSizeTypes) ? explode(',', $selectedSizeTypes) : [];
                    if (! empty($selectedSizeTypes)) {
                        foreach ($sizes as $index => $size) {
                            if (! empty($size)) {
                                $sizes[$index] = $size->filter(function ($size) use ($selectedSizeTypes) {
                                    return in_array($size['type'], $selectedSizeTypes);
                                });
                            }
                        }
                    }
                });
            }

            // select price range from product inventories
            // TODO - may be selected from Trades as the sum of all trade offer items
            $priceRange = Product::join('inventories', 'products.id', '=', 'inventories.product_id')
            ->where('inventories.type', 'selling')
            ->selectRaw('min(inventories.sale_price) as min, max(inventories.sale_price) as max')
            ->first();

            $filters = [
                'size_types'  => $sizeTypes->pluck('type'),
                'sizes'  => $sizes,
                'priceRange' => [($priceRange->min ? $priceRange->min : 0), ($priceRange->max ? $priceRange->max : 0)],
            ];

            return $this->success('', $filters);
        } catch (Exception $e) {
            report($e);

            return $this->error('error.some_error_occurred');
        }
    }

    /**
     * To search the trade offers based on filters.
     *
     ** @OA\Post(
     *      path="/api/trades/offers",
     *      summary="Search trade offers based on filters",
     *      description="Returns trade offer items",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="category",
     *                      type="string",
     *                      description="category"
     *                  ),
     *                  @OA\Property(
     *                      property="size_type",
     *                      type="string",
     *                      description="size type"
     *                  ),
     *                  @OA\Property(
     *                      property="gender",
     *                      type="string",
     *                      description="gender"
     *                  ),
     *                  @OA\Property(
     *                      property="product_type",
     *                      type="string",
     *                      description="product type"
     *                  ),
     *                  @OA\Property(
     *                      property="status",
     *                      type="string",
     *                      description="trade status"
     *                  ),
     *                  @OA\Property(
     *                      property="sizes",
     *                      type="string",
     *                      description="Comma separated sizes"
     *                  ),
     *                  @OA\Property(
     *                      property="searched_text",
     *                      type="string",
     *                      description="Searched text"
     *                  ),
     *                  @OA\Property(
     *                      property="sort_by",
     *                      type="string",
     *                      description="Sort by"
     *                  ),
     *                  @OA\Property(
     *                      property="trade_total_items",
     *                      type="string",
     *                      description="Trade total items"
     *                  ),
     *                  @OA\Property(
     *                      property="trade_type",
     *                      type="string",
     *                      description="Trade type recently_viewed, ending_soon, newly_listed, single_item, search_results, All"
     *                  ),
     *                  @OA\Property(
     *                      property="price_min",
     *                      type="number",
     *                      description="Minimum price"
     *                  ),
     *                  @OA\Property(
     *                      property="price_max",
     *                      type="number",
     *                      description="Maximum price"
     *                  ),
     *                  @OA\Property(
     *                      property="limit",
     *                      type="number",
     *                      description="Number of Trades to Return"
     *                  )
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="result", type="object"),
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Server error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      )
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getTradeOffers(Request $request): JsonResponse
    {
        try {
            $expiryDays = config('constants.trades.trade_expiry_days');
            $endDateInterval = 'DATE_ADD(DATE(trades.created_at), INTERVAL '.$expiryDays.' DAY)';
            $createDate = 'DATE(trades.created_at)';
            $totalItems = 'COUNT(trade_offers.id)';

            $tradesQuery = Trade::query()
                ->with(
                    'offers.inventory.size',
                    'offers.inventory.packagingCondition',
                    'offers.inventory.product.category'
                )
                ->groupBy('trade_offers.id')
                ->selectRaw("trades.*,{$endDateInterval} as end_date, {$createDate} as create_date, {$expiryDays} as timeLimit, {$totalItems} as totalItems")
                ->join('trade_offers', 'trades.id', '=', 'trade_offers.trade_id');

            // filter trade items as per selected category
            if ($request->category && $request->category !== 'all') {
                $tradesQuery->whereIn('categories.name', [$request->category]);
            }

            // filter trade items as per selected sizes
            if ($request->sizes) {
                $tradesQuery->whereIn('sizes.size', explode(',', $request->sizes));
            }

            // filter trade items as per selected size types
            if ($request->size_type) {
                $tradesQuery->whereIn('sizes.type', [$request->size_type]);
            }

            // filter trade items as per searched text
            if ($request->searched_text) {
                $tradesQuery->where('products.name', 'LIKE', "%{$request->searched_text}%");
            }

            // filter trade items as per product gender
            if ($request->gender) {
                $tradesQuery->whereIn('products.gender', [$request->gender]);
            }

            // filter trade items as per selected trade statuses
            if ($request->status) {
                $tradesQuery->whereIn('trades.status', explode(',', $request->status));
            }

            // filter trade items as per selected brands
            if ($request->brands) {
                $tradesQuery->whereIn('products.brand', explode(',', $request->brands));
            }

            // filter trade items as per product max release year
            if ($request->maxYear) {
                $tradesQuery->where('products.release_year', '<=', $request->maxYear);
            }

            // filter trade items as per product min release year
            if ($request->minYear) {
                $tradesQuery->where('products.release_year', '>=', $request->minYear);
            }

            // filter trade items as per minimum price
            if ($request->price_min) {
                $tradesQuery->where('products.retail_price', '>=', $request->price_min);
            }

            // filter trade items as per maximum price
            if ($request->price_max) {
                $tradesQuery->where('products.retail_price', '<=', $request->price_max);
            }

            $tradesQuery->join('inventories', 'inventories.id', '=', 'inventory_id')
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->join('sizes', 'sizes.id', '=', 'inventories.size_id');

            // sort trade items as per sort type
            $request->whenHas('sort_by', function ($sortBy) use (&$tradesQuery, $endDateInterval) {
                switch ($sortBy) {
                    case 'end_date_ascending':
                        $tradesQuery->orderByRaw($endDateInterval.' asc');
                        break;
                    case 'end_date_descending':
                        $tradesQuery->orderByRaw($endDateInterval.' desc');
                        break;
                    case 'most_viewed':
                        $tradesQuery->orderBy('trades.views', 'desc');
                        break;
                    default: //relevance
                        $tradesQuery->orderByRaw($endDateInterval.' desc');
                        break;
                }
            })
            // filter trade items as total offer items
            ->whenHas('trade_total_items', function ($tradeTotalItems) use (&$tradesQuery, $totalItems) {
                $tradeItemNumber = 1;
                switch ($tradeTotalItems) {
                    case 'two':
                        $tradeItemNumber = 2;
                        break;
                    case 'three':
                        $tradeItemNumber = 3;
                        break;
                    default:
                        break;
                }
                $tradesQuery->whereRaw("(SELECT {$totalItems} FROM trades AS T JOIN trade_offers AS TrO ON TrO.trade_id = T.id WHERE T.id = trades.id) = {$tradeItemNumber}");
            });

            //$query .= " WHERE $endDateInterval > now() ";

            $recentlyViewedQuery = clone $tradesQuery;
            $recentlyViewedQuery->orderBy('last_viewed', 'desc');
            $endingSoonQuery = clone $tradesQuery;
            $endingSoonQuery->orderByRaw($endDateInterval.' desc');
            $newlyListedQuery = clone $tradesQuery;
            $newlyListedQuery->orderByRaw($createDate.' desc');
            $singleItemQuery = clone $tradesQuery;
            $singleItemQuery->orderByRaw($createDate.' desc');
            $searchResultsQuery = clone $tradesQuery;

            $products = [];

            // return trades/products as per selected section
            if ($request->trade_type === 'All') {
                $tradesQuery->limit($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                $recentlyViewedQuery->limit($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                $endingSoonQuery->limit($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                $newlyListedQuery->limit($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                $singleItemQuery->limit($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                $products = [
                    'recently_viewed' => $recentlyViewedQuery->get(),
                    'ending_soon' => $endingSoonQuery->get(),
                    'newly_listed' => $newlyListedQuery->get(),
                    'single_item' => $singleItemQuery->get(),
                ];
            } else {
                // single section is selected (searched or selects view more for single section)
                switch ($request->trade_type) {
                    case 'recently_viewed':
                        $products = $recentlyViewedQuery->paginate($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                        break;
                    case 'ending_soon':
                        $products = $endingSoonQuery->paginate($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                        break;
                    case 'newly_listed':
                        $products = $newlyListedQuery->paginate($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                        break;
                    case 'single_item':
                        $products = $singleItemQuery->paginate($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                        break;
                    case 'search_results':
                        $products = $searchResultsQuery->paginate($request->limit ?? config('constants.trades.trade_offer_items_per_page'));
                        break;
                }

                $products = [$request->trade_type => $products];
            }

            return $this->success('', $products);
        } catch (Exception $e) {
            report($e);

            return $this->error('error.some_error_occurred');
        }
    }

    /**
     * To get offer items on search input.
     *
     ** @OA\Get(
     *      path="/api/trades/offer-items",
     *      summary="Get Trade offer items on search input",
     *      description="Returns offer items",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="searched_text",
     *          description="Text entered by user",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="result", type="object" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Something went wrong. Please try again later!",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      )
     * )
     * @return JsonResponse
     */
    public function searchOfferItems(Request $request): JsonResponse
    {
        try {
            $offerItems = TradeOffer::take(config('constants.vendor_trade.total_offer_items_searched'))
            ->with(
                ['inventory' => function ($query) use ($request) {
                    $query->productsByName($request->searched_text);
                }],
            )->onlyLiveTrades();

            return $this->success('', $offerItems);
        } catch (Exception $e) {
            report($e);

            return $this->error('error.some_error_occurred');
        }
    }

    /**
     * To get best matches and interested in your listing items.
     *
     ** @OA\Get(
     *      path="/api/trades/matching",
     *      summary="Get best matches and interested in your listing items",
     *      description="Returns listings",
     *      security={{ "bearerAuth": {} }},
     *      tags={"Trades"},
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="result", type="object" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Server error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      )
     * )
     * @return JsonResponse
     */
    public function getMatchingTrades(Request $request): JsonResponse
    {
        try {
            $bestMatches = [];

            if (! data_get($request->user(), 'vendor.id')) {
                return $this->error('error.some_error_occurred');
            }

            /**
             * TODO ITEMS LATER
             * - Add want items to matching
             * - Convert custom query to eloquent.
             */
            //exact match query (like all offer items matching all want items for both vendors)

            $query = "SELECT trades.id, userTradeItems.*, othersTradeItems.*
            FROM trades
            JOIN (
                SELECT GROUP_CONCAT(DISTINCT inventories.product_id ORDER BY inventories.product_id SEPARATOR ',') as offerItemsString,
                GROUP_CONCAT(DISTINCT trade_wants.product_id ORDER BY trade_wants.product_id SEPARATOR ',') as wantItemsString, trades.id as myTradeId
                FROM trades
                JOIN trade_wants ON trade_wants.trade_id = trades.id
                JOIN trade_offers ON trade_offers.trade_id = trades.id
                JOIN inventories ON inventories.id = inventory_id
                WHERE trades.vendor_id = ".data_get($request->user(), 'vendor.id')."
                GROUP BY trades.id
            ) as userTradeItems ON userTradeItems.myTradeId = trades.id
            JOIN (
                SELECT GROUP_CONCAT(DISTINCT inventories.product_id ORDER BY inventories.product_id SEPARATOR ',') as offerItemsString,
                GROUP_CONCAT(DISTINCT trade_wants.product_id ORDER BY trade_wants.product_id SEPARATOR ',') as wantItemsString, trades.id as matchingTradeId
                FROM trades
                JOIN trade_wants ON trade_wants.trade_id = trades.id
                JOIN trade_offers ON trade_offers.trade_id = trades.id
                JOIN inventories ON inventories.id = inventory_id
                WHERE trades.vendor_id != ".data_get($request->user(), 'vendor.id').'
                GROUP BY trades.id
            ) as othersTradeItems ON othersTradeItems.offerItemsString = userTradeItems.wantItemsString AND userTradeItems.offerItemsString = othersTradeItems.wantItemsString
            WHERE othersTradeItems.matchingTradeId NOT IN (SELECT trade_id FROM trades_dismissed WHERE vendor_id = '.data_get($request->user(), 'vendor.id').')
             ';

            $matchedTrades = DB::select($query);

            foreach ($matchedTrades as $trade) {
                $bestMatches[] = [
                     'theirs' => Trade::with(
                         'offers.inventory.product',
                         'offers.inventory.size',
                         'offers.inventory.packagingCondition',
                         'wants.product',
                         'wants.size',
                         'wants.packagingCondition',
                     )
                    ->byId($trade->matchingTradeId)
                    ->first(),
                    'yours' => Trade::with(
                        'offers.inventory.product',
                        'offers.inventory.size',
                        'offers.inventory.packagingCondition',
                        'wants.product',
                        'wants.size',
                        'wants.packagingCondition',
                    )
                    ->byId($trade->myTradeId)
                    ->first(),
                 ];
            }

            $interestedInYourListing = $this->getInterestedInYourTradeListings($request);

            return $this->success('', ['best_matches' => $bestMatches, 'interested_in_your_listing' => $interestedInYourListing]);
        } catch (Exception $e) {
            report($e);

            return $this->error('error.some_error_occurred');
        }
    }

    /**
     * Save Offer on trade.
     *
     * @OA\Post(
     *      path="/api/trades/{id}/offer",
     *      operationId="saveOffer",
     *      tags={"Trades"},
     *      summary="Save Offer on trade.",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns offer data",
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade ID",
     *          in="query",
     *          required=true,
     *          @OA\Schema(
     *              type="number"
     *          )
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"price", "shipping_fee", "processing_fee", "tax", "total", "billing_address", "shipping_address"},
     *              @OA\Property(
     *                 property="price",
     *                 type="Integer",
     *                 description="price of items",
     *              ),
     *              @OA\Property(
     *                  property="cash_added",
     *                  type="Integer",
     *                  description="cash added",
     *              ),
     *              @OA\Property(
     *                  property="offer_type",
     *                  type="String",
     *                  description="offer/counter-offer",
     *              ),
     *              @OA\Property(
     *                  property="their_vendor_id",
     *                  type="Integer",
     *                  description="1",
     *              ),
     *              @OA\Property(
     *                  property="shipping_fee",
     *                  type="Integer",
     *                  description="shipping fee",
     *              ),
     *              @OA\Property(
     *                  property="processing_fee",
     *                  type="Integer",
     *                  description="processing fee",
     *              ),
     *              @OA\Property(
     *                  property="tax",
     *                  type="Integer",
     *                  description="tax",
     *              ),
     *              @OA\Property(
     *                  property="total",
     *                  type="Integer",
     *                  description="total",
     *              ),
     *              @OA\Property(
     *                      property="billing_address",
     *                      type="array",
     *                      description="Billing Address",
     *                      @OA\Items(
     *                           @OA\Property(
     *                              property="first_name",
     *                              type="String",
     *                              example="John"
     *                           ),
     *                           @OA\Property(
     *                              property="last_name",
     *                              type="String",
     *                              example="Doe"
     *                           ),
     *                           @OA\Property(
     *                              property="email",
     *                              type="String",
     *                              example="john.doe@gmail.com"
     *                           ),
     *                           @OA\Property(
     *                              property="address_line_1",
     *                              type="Text",
     *                              example="Street 2, Downtown"
     *                           ),
     *                           @OA\Property(
     *                              property="city",
     *                              type="String",
     *                              example="Calgary"
     *                           ),
     *                           @OA\Property(
     *                              property="state",
     *                              type="String",
     *                              example="Alberta"
     *                           ),
     *                           @OA\Property(
     *                              property="zip",
     *                              type="String",
     *                              example="T3S"
     *                           ),
     *                           @OA\Property(
     *                              property="country",
     *                              type="String",
     *                              example="Canada"
     *                           ),
     *                        ),
     *                      ),
     *                       @OA\Property(
     *                         property="shipping_address",
     *                         type="array",
     *                         description="Shipping Address",
     *                         @OA\Items(
     *                           @OA\Property(
     *                              property="first_name",
     *                              type="String",
     *                              example="John"
     *                           ),
     *                           @OA\Property(
     *                              property="last_name",
     *                              type="String",
     *                              example="Doe"
     *                           ),
     *                           @OA\Property(
     *                              property="email",
     *                              type="String",
     *                              example="john.doe@gmail.com"
     *                           ),
     *                           @OA\Property(
     *                              property="address_line_1",
     *                              type="Text",
     *                              example="Street 2, Downtown"
     *                           ),
     *                           @OA\Property(
     *                              property="city",
     *                              type="String",
     *                              example="Calgary"
     *                           ),
     *                           @OA\Property(
     *                              property="state",
     *                              type="String",
     *                              example="Alberta"
     *                           ),
     *                           @OA\Property(
     *                              property="zip",
     *                              type="String",
     *                              example="T3S"
     *                           ),
     *                           @OA\Property(
     *                              property="country",
     *                              type="String",
     *                              example="Canada"
     *                           ),
     *                        ),
     *                      ),
     *                      @OA\Property(
     *                         property="your_items",
     *                         type="array",
     *                         description="Your Offered Items",
     *                         @OA\Items(
     *                           @OA\Property(
     *                              property="inventory_id",
     *                              type="Integer",
     *                              example="1"
     *                           ),
     *                           @OA\Property(
     *                              property="product_id",
     *                              type="Integer",
     *                              example="2"
     *                           ),
     *                           @OA\Property(
     *                              property="size_id",
     *                              type="Integer",
     *                              example="3"
     *                           ),
     *                           @OA\Property(
     *                              property="packaging_condition_id",
     *                              type="Integer",
     *                              example="4"
     *                           ),
     *                           @OA\Property(
     *                              property="sale_price",
     *                              type="Integer",
     *                              example="54000"
     *                           ),
     *                           @OA\Property(
     *                              property="quantity",
     *                              type="Integer",
     *                              example="2"
     *                           ),
     *                        ),
     *                      ),
     *                ),
     *          ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="result", type="object" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Uprocessable Entity",
     *          @OA\JsonContent(ref="#/components/schemas/UnprocessableEntityResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return object
     * @throws Exception
     */
    public function saveOffer(Request $request, $id)
    {
        $vendorId = data_get($request->user(), 'vendor.id');

        if (! $vendorId) {
            return $this->error('error.unauthorized', 401);
        }

        $request->validate([
            'price' => 'required|numeric',
            'shipping_fee' => 'numeric',
            'processing_fee' => 'numeric',
            'trade_fee' => 'numeric',
            'tax' => 'numeric',
            'card_details' => 'required|string',
            'payment_method' => 'required',
            'total' => 'required|numeric',
            'billing_address' => 'nullable',
            'shipping_address' => 'nullable',
            'parent_id' => 'nullable',
            'your_items' => 'required',
            'their_items' => 'required',
            'condition' => 'required|in:poor,fair,excellent',
            'offer_type' => 'required|string|in:offer,counter offer',
            'their_vendor_id' => 'numeric',
        ]);

        $trade = Trade::findOrFail($id);

        try {
            DB::beginTransaction();

            $billingAddress = $this->saveAddress($request->user()->id, $request->billing_address);
            $shippingAddress = $this->saveAddress($request->user()->id, $request->shipping_address);

            $this->acceptOfferService->getOrCreateCustomerVault($request, $billingAddress);
            $userCustomerVaultId = $this->acceptOfferService->getUserCustomerVaultId($request->toArray());

            $vendorsLastSubmittedOffer = TradeSubmittedOffer::with('items')->where([
                'sent_by_id' => $vendorId,
                'trade_id' => $id,
              ])->orderBy('id', 'desc')->first();
            if ($vendorsLastSubmittedOffer) {
                foreach ($vendorsLastSubmittedOffer->yours_items as $item) {
                    //restore inventory stock
                    $this->updateInventoryStock($item->inventory_id, true);
                }
            } elseif ($trade->vendor_id === $vendorId) {
                //to control inventory stock
                $tradeOfferItems = TradeOffer::where([
                    'trade_id' => $id,
                ])->get();
                foreach ($tradeOfferItems as $offerItem) {
                    //restore inventory stock
                    $this->updateInventoryStock($offerItem->inventory_id, true);
                }
            }

            $tradeSubmittedOffer = $trade->submittedOffers()->create([
                'parent_id' => $request->parent_id,
                'price' => $request->price,
                'shipping_fee' => $request->shipping_fee,
                'processing_fee' => $request->processing_fee,
                'trade_fee' => $request->trade_fee,
                'tax' => $request->tax,
                'card_details' => $request->card_details,
                'cash_added' => $request->cash_added,
                'cash_type' => ($request->cash_added ? $request->cash_type : null),
                'condition' => $request->condition,
                'total' => $request->total,
                'customer_vault_id' => $userCustomerVaultId,
                'billing_address_id' => ($billingAddress) ? $billingAddress->id : $billingAddress,
                'shipping_address_id' => ($shippingAddress) ? $shippingAddress->id : $shippingAddress,
                'sent_by_id' => $vendorId,
                'received_by_id' => $request->their_vendor_id,
                'type' => $request->offer_type,
            ]);

            $this->saveYourOfferItems($tradeSubmittedOffer, $request->your_items, $vendorId);
            $this->saveTheirOfferItems($tradeSubmittedOffer, $request->their_items, $request->their_vendor_id);

            $theirUserDetails = Vendor::with('user')->findOrFail($request->their_vendor_id)->toArray();

            $payload = $this->getTradeOfferEmailPayload($tradeSubmittedOffer->id);
            $offerSentMail = ($request->offer_type === 'offer') ? new OfferSent($request->user()->email, $payload)
                                                                : new CounterOfferSent($request->user()->email, $payload);

            $payload = $this->getTradeOfferEmailPayload($tradeSubmittedOffer->id);
            $offerReceivedMail = ($request->offer_type === 'offer') ? new OfferReceived($theirUserDetails['user']['email'], $payload)
                                                                    : new CounterOfferReceived($theirUserDetails['user']['email'], $payload);

            Mail::queue($offerSentMail);
            Mail::queue($offerReceivedMail);

            DB::commit();

            return $this->success('', [
                'submitted_offer' => TradeSubmittedOffer::with(
                    'trade.offers.inventory.product',
                    'trade.offers.inventory.size',
                    'trade.offers.inventory.packagingCondition',
                    'shippingAddress',
                    'billingAddress',
                    'items',
                    'items.inventory.product',
                    'items.inventory.size',
                    'items.inventory.packagingCondition',
                    'sentBy',
                    'receivedBy'
                )->findOrFail($tradeSubmittedOffer->id),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            report($e);

            return $this->error('error.something_went_wrong');
        }
    }

    /**
     * Accept Offer on trade.
     *
     * @OA\Post(
     *      path="/api/trades/{id}/accept-offer",
     *      operationId="acceptOffer",
     *      tags={"Trades"},
     *      summary="Accept Offer on trade.",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns offer data",
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade ID",
     *          in="query",
     *          required=true,
     *          @OA\Schema(
     *              type="number"
     *          )
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"price", "shipping_fee", "processing_fee", "tax", "total", "billing_address", "shipping_address"},
     *              @OA\Property(
     *                 property="price",
     *                 type="Integer",
     *                 description="price of items",
     *              ),
     *              @OA\Property(
     *                  property="cash_added",
     *                  type="Integer",
     *                  description="cash added",
     *              ),
     *              @OA\Property(
     *                  property="offer_type",
     *                  type="String",
     *                  description="accept",
     *              ),
     *              @OA\Property(
     *                  property="their_vendor_id",
     *                  type="Integer",
     *                  description="1",
     *              ),
     *              @OA\Property(
     *                  property="shipping_fee",
     *                  type="Integer",
     *                  description="shipping fee",
     *              ),
     *              @OA\Property(
     *                  property="processing_fee",
     *                  type="Integer",
     *                  description="processing fee",
     *              ),
     *              @OA\Property(
     *                  property="tax",
     *                  type="Integer",
     *                  description="tax",
     *              ),
     *              @OA\Property(
     *                  property="total",
     *                  type="Integer",
     *                  description="total",
     *              ),
     *              @OA\Property(
     *                      property="billing_address",
     *                      type="array",
     *                      description="Billing Address",
     *                      @OA\Items(
     *                           @OA\Property(
     *                              property="first_name",
     *                              type="String",
     *                              example="John"
     *                           ),
     *                           @OA\Property(
     *                              property="last_name",
     *                              type="String",
     *                              example="Doe"
     *                           ),
     *                           @OA\Property(
     *                              property="email",
     *                              type="String",
     *                              example="john.doe@gmail.com"
     *                           ),
     *                           @OA\Property(
     *                              property="address_line_1",
     *                              type="Text",
     *                              example="Street 2, Downtown"
     *                           ),
     *                           @OA\Property(
     *                              property="city",
     *                              type="String",
     *                              example="Calgary"
     *                           ),
     *                           @OA\Property(
     *                              property="state",
     *                              type="String",
     *                              example="Alberta"
     *                           ),
     *                           @OA\Property(
     *                              property="zip",
     *                              type="String",
     *                              example="T3S"
     *                           ),
     *                           @OA\Property(
     *                              property="country",
     *                              type="String",
     *                              example="Canada"
     *                           ),
     *                        ),
     *                      ),
     *                       @OA\Property(
     *                         property="shipping_address",
     *                         type="array",
     *                         description="Shipping Address",
     *                         @OA\Items(
     *                           @OA\Property(
     *                              property="first_name",
     *                              type="String",
     *                              example="John"
     *                           ),
     *                           @OA\Property(
     *                              property="last_name",
     *                              type="String",
     *                              example="Doe"
     *                           ),
     *                           @OA\Property(
     *                              property="email",
     *                              type="String",
     *                              example="john.doe@gmail.com"
     *                           ),
     *                           @OA\Property(
     *                              property="address_line_1",
     *                              type="Text",
     *                              example="Street 2, Downtown"
     *                           ),
     *                           @OA\Property(
     *                              property="city",
     *                              type="String",
     *                              example="Calgary"
     *                           ),
     *                           @OA\Property(
     *                              property="state",
     *                              type="String",
     *                              example="Alberta"
     *                           ),
     *                           @OA\Property(
     *                              property="zip",
     *                              type="String",
     *                              example="T3S"
     *                           ),
     *                           @OA\Property(
     *                              property="country",
     *                              type="String",
     *                              example="Canada"
     *                           ),
     *                        ),
     *                      ),
     *                      @OA\Property(
     *                         property="your_items",
     *                         type="array",
     *                         description="Your Offered Items",
     *                         @OA\Items(
     *                           @OA\Property(
     *                              property="inventory_id",
     *                              type="Integer",
     *                              example="1"
     *                           ),
     *                           @OA\Property(
     *                              property="product_id",
     *                              type="Integer",
     *                              example="2"
     *                           ),
     *                           @OA\Property(
     *                              property="size_id",
     *                              type="Integer",
     *                              example="3"
     *                           ),
     *                           @OA\Property(
     *                              property="packaging_condition_id",
     *                              type="Integer",
     *                              example="4"
     *                           ),
     *                           @OA\Property(
     *                              property="sale_price",
     *                              type="Integer",
     *                              example="54000"
     *                           ),
     *                           @OA\Property(
     *                              property="quantity",
     *                              type="Integer",
     *                              example="2"
     *                           ),
     *                        ),
     *                      ),
     *                ),
     *          ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="result", type="object" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Uprocessable Entity",
     *          @OA\JsonContent(ref="#/components/schemas/UnprocessableEntityResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return object
     * @throws Exception
     */
    public function acceptOffer(Request $request, $id)
    {
        $vendorId = data_get($request->user(), 'vendor.id');

        if (! $vendorId) {
            return $this->error('error.unauthorized', 401);
        }

        $request->validate([
            'price' => 'required|numeric',
            'shipping_fee' => 'numeric',
            'processing_fee' => 'numeric',
            'trade_fee' => 'numeric',
            'tax' => 'numeric',
            'card_details' => 'required|string',
            'total' => 'required|numeric',
            'billing_address' => 'nullable',
            'shipping_address' => 'nullable',
            'parent_id' => 'nullable',
            'your_items' => 'required',
            'their_items' => 'required',
            'condition' => 'required|in:poor,fair,excellent',
            'offer_type' => 'required|string|in:accept',
            'their_vendor_id' => 'numeric',
        ]);

        try {
            DB::beginTransaction();
            $trade = Trade::findOrFail($id);
            $trade->update([
                'status' => config('constants.trades.statuses.completed'),
            ]);

            $billingAddress = $this->saveAddress($request->user()->id, $request->billing_address);
            $shippingAddress = $this->saveAddress($request->user()->id, $request->shipping_address);

            $this->acceptOfferService->getOrCreateCustomerVault($request, $billingAddress);
            $userCustomerVaultId = $this->acceptOfferService->getUserCustomerVaultId($request->toArray());

            $tradeSubmittedOffer = $trade->submittedOffers()->create([
                'parent_id' => $request->parent_id,
                'price' => $request->price,
                'shipping_fee' => $request->shipping_fee,
                'processing_fee' => $request->processing_fee,
                'trade_fee' => $request->trade_fee,
                'tax' => $request->tax,
                'card_details' => $request->card_details,
                'cash_added' => $request->cash_added,
                'cash_type' => ($request->cash_added ? $request->cash_type : null),
                'condition' => $request->condition,
                'total' => $request->total,
                'customer_vault_id' => $userCustomerVaultId,
                'billing_address_id' => ($billingAddress) ? $billingAddress->id : $billingAddress,
                'shipping_address_id' => ($shippingAddress) ? $shippingAddress->id : $shippingAddress,
                'sent_by_id' => $vendorId,
                'received_by_id' => $request->their_vendor_id,
                'type' => $request->trade['type'],
                'status' => config('constants.trades.offer_statuses.accepted'),
            ]);

            $tradeSubmittedOffer->billing_address = $billingAddress->toArray();
            $tradeSubmittedOffer->shipping_address = $shippingAddress->toArray();

            // updated previous offer status to accepted
            TradeSubmittedOffer::findOrFail($request->latest_offer['id'])
                ->update([
                    'status' => config('constants.trades.offer_statuses.accepted'),
                ]);

            $this->saveYourOfferItems($tradeSubmittedOffer, $request->your_items, $vendorId);
            $this->saveTheirOfferItems($tradeSubmittedOffer, $request->their_items, $request->their_vendor_id);

            $offerSubmitted = TradeSubmittedOffer::with([
                'shippingAddress',
                'billingAddress',
                'sentByVendor.user',
                'receivedByVendor.user',
            ])->find($tradeSubmittedOffer->id)->toArray();
            $this->createTradeOrder($request, $this->acceptOfferService, $offerSubmitted, $request->latest_offer);

            $theirUserDetails = Vendor::with('user')->findOrFail($request->their_vendor_id)->toArray();
            $payload = $this->getTradeOfferEmailPayload($tradeSubmittedOffer->id);
            Mail::queue(new OfferAccepted($theirUserDetails['user']['email'], $payload));

            DB::commit();

            return $this->success('', [
                'submitted_offer' => TradeSubmittedOffer::with(
                    'trade.offers.inventory.product',
                    'trade.offers.inventory.size',
                    'trade.offers.inventory.packagingCondition',
                    'shippingAddress',
                    'billingAddress',
                    'items',
                    'items.inventory.product',
                    'items.inventory.size',
                    'items.inventory.packagingCondition',
                    'sentBy',
                    'receivedBy'
                )->findOrFail($tradeSubmittedOffer->id),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            report($e);

            return $this->error('error.something_went_wrong');
        }
    }

    /**
     * Get Trades Dashboard Summary
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/dashboard-summary",
     *      operationId="getDashboardSummary",
     *      summary="Get Trades Dashboard Summary",
     *      description="Returns list of trades",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="page",
     *          description="Page no to be displayed",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="take",
     *          description="Per Page items to be displayed",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="status",
     *          description="Comma separated statuses",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="from_date",
     *          description="From date filter",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="to_date",
     *          description="To date filter",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="order_by",
     *          description="Order by filter",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(ref="#/components/schemas/TradeResource")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return array
     * @throws Exception
     */
    public function getDashboardSummary(Request $request)
    {
        $userId = data_get($request->user(), 'vendor.id');
        $orderBy = $request->input('order_by', config('constants.trades.dashboard_summary.default_trade_order'));

        return $this->success('', Trade::with(
            'newOffersCount',
            'offers'
        )
                ->byVendor($userId)
                ->byStatus(explode($request->status, ','))
                ->byCreatedAt($request->from_date, $request->to_date)
                ->orderBy('created_at', $orderBy)
                ->paginate($request->input('take', config('constants.trades.dashboard_summary.default_trade'))));
    }

    /**
     * Get offer details
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/offer/{id}",
     *      operationId="getOffer",
     *      summary="Get offer details",
     *      description="Returns offer details",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="id",
     *          description="Offer id",
     *          required=false,
     *          in="path",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="results", type="object" ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getOffer(Request $request, $offerId)
    {
        return $this->success('', TradeSubmittedOffer::with(
            'trade.offers.inventory.size',
            'trade.offers.inventory.packagingCondition',
            'trade.offers.inventory.product.category',
            'trade.wants.product.sizes',
            'trade.wants.product.packagingConditions',
            'trade.wants.size',
            'trade.wants.packagingCondition',
            'shippingAddress',
            'billingAddress',
            'parentOffer',
            'offer_history',
            'parentOffer',
        )
        ->byId($offerId)
        ->byUser(data_get($request->user(), 'vendor.id'))
        ->withTrashed()
        ->firstOrFail());
    }

    /**
     * Get User submitted offers
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/submitted-offers",
     *      operationId="getSubmittedOffers",
     *      summary="Get users submitted offers",
     *      description="Returns list of offers",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="page",
     *          description="Page no to be displayed",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="per_page",
     *          description="Per Page items to be displayed",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="status",
     *          description="Comma separated statuses",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="condition",
     *          description="Offer condition filter (fair, poor, excellent)",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="type",
     *          description="Offer type filter",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="search",
     *          description="Search Keyword",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="order_by",
     *          description="Order offers list",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="from_date",
     *          description="Filter list using from date",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="to_date",
     *          description="Filter list using to date",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Parameter(
     *          name="active_offers",
     *          description="Select only active offers",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(ref="#/components/schemas/TradeResource")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getSubmittedOffers(Request $request)
    {
        $userId = data_get($request->user(), 'vendor.id');
        $orderBy = $request->input('order_by', config('constants.trades.order.recent_to_oldest'));
        $condition = ($request->condition) ? explode(',', $request->condition) : [];
        $status = ($request->status) ? explode(',', $request->status) : [];
        $search = $request->search ?? null;
        $activeOffers = $request->active_offers ?? false;

        $submittedOffersQuery = TradeSubmittedOffer::with(
            'trade.offers.inventory.size',
            'trade.offers.inventory.packagingCondition',
            'trade.offers.inventory.product.category',
            'shippingAddress',
            'billingAddress',
            'offer_history'
        )
            ->byStatus($status)
            ->byType($request->type)
            ->byProduct($search)
            ->byReceivables($request->type, $userId) // type is send/received
            ->byCondition($condition)
            ->byOrder($orderBy)
            ->byTrade($request->trade_id)
            ->byLiveTrade()
            ->byStartDate($request->from_date)
            ->byEndDate($request->to_date);
        if (! $activeOffers) {
            $submittedOffersQuery->withTrashed();
        }
        $submittedOffers = $submittedOffersQuery->groupBy('trade_id')
            ->paginate($request->input('per_page', config('constants.trades.dashboard_summary.default_offers')));

        return $this->success('', $submittedOffers);
    }

    /**
     * Deactivate multiple trade offers
     * -------------------------------.
     ** @OA\Post(
     *      path="/api/trades/offers/deactivate",
     *      operationId="deactivateMultipleTradeOffers",
     *      summary="Deactivate multiple trade offers",
     *      description="Success/Unauthenticated",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="offer_ids",
     *          description="Comma separated statuses",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success"
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return array
     * @throws Exception
     */
    public function deactivateMultipleTradeOffers(Request $request)
    {
        $request->validate([
            'offer_ids' => 'string|required',
        ]);

        $offerIds = explode(',', $request->offer_ids);

        try {
            DB::beginTransaction();
            foreach ($offerIds as $offerId) {
                $tradeSubmittedOffer = TradeSubmittedOffer::findOrFail(trim($offerId));

                $this->updateInventoryStocksAgainstOffers(
                    $tradeSubmittedOffer,
                    $tradeSubmittedOffer->theirs_items[0]['vendor_id'],
                    $tradeSubmittedOffer->yours_items[0]['vendor_id']
                );

                $tradeSubmittedOffer->delete();
                TradeSubmittedOffer::where(['id' => $offerId])->update([
                    'status' => config('constants.trades.offer_statuses.deleted'),
                ]);
                $userId = $tradeSubmittedOffer->theirs_items[0]['vendor_id'] !== data_get($request->user(), 'vendor.id') ?
                    $tradeSubmittedOffer->theirs_items[0]['vendor_id'] : $tradeSubmittedOffer->yours_items[0]['vendor_id'];
                $theirUserDetails = Vendor::with('user')->findOrFail($userId)->toArray();
                $payload = $this->getTradeOfferEmailPayload($offerId);
                Mail::queue(new OfferDeleted($theirUserDetails['user']['email'], $payload));
            }
            DB::commit();

            return $this->success('', ['status' => 'trades.success'], 200);
        } catch (Exception $e) {
            DB::rollBack();
            report($e);

            return $this->error('error.some_error_occurred');
        }
    }

    /**
     * Activate multiple trade offers
     * -------------------------------.
     ** @OA\Post(
     *      path="/api/trades/offers/activate",
     *      operationId="activateMultipleTradeOffers",
     *      summary="Activate multiple trade offers",
     *      description="Success/Unauthenticated",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="offer_ids",
     *          description="Comma separated statuses",
     *          required=false,
     *          in="query",
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success"
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return array
     * @throws Exception
     */
    public function activateMultipleTradeOffers(Request $request)
    {
        $request->validate([
            'offer_ids' => 'string|required',
        ]);

        $offerIds = explode(',', $request->offer_ids);

        try {
            DB::beginTransaction();
            foreach ($offerIds as $offerId) {
                $tradeSubmittedOffer = TradeSubmittedOffer::withTrashed()->find(trim($offerId));

                $this->updateInventoryStocksAgainstOffers(
                    $tradeSubmittedOffer,
                    $tradeSubmittedOffer->theirs_items[0]['vendor_id'],
                    $tradeSubmittedOffer->yours_items[0]['vendor_id'],
                    false
                );
                $tradeSubmittedOffer->restore();
                $tradeSubmittedOffer->update([
                    'status' => config('constants.trades.offer_statuses.open'),
                ]);

                $userId = $tradeSubmittedOffer->theirs_items[0]['vendor_id'] !== data_get($request->user(), 'vendor.id') ?
                    $tradeSubmittedOffer->theirs_items[0]['vendor_id'] : $tradeSubmittedOffer->yours_items[0]['vendor_id'];
                $theirUserDetails = Vendor::with('user')->findOrFail($userId)->toArray();
                $payload = $this->getTradeOfferEmailPayload($offerId);
                Mail::queue(new OfferRestored($theirUserDetails['user']['email'], $payload));
            }
            DB::commit();

            return $this->success('', ['status' => 'trades.success'], 200);
        } catch (Exception $e) {
            DB::rollBack();
            report($e);

            return $this->error('error.some_error_occurred');
        }
    }

    /**
     * Update Trade Status.
     *
     * @OA\Put(
     *      path="/api/trades/{id}/status",
     *      operationId="updateStatus",
     *      tags={"Trades"},
     *      summary="Update trade status",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns success",
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade ID",
     *          in="path",
     *          required=true,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"status"},
     *                @OA\Property(
     *                      property="status",
     *                      type="String",
     *                      description="Vendor trade status",
     *                      example="de-listed"
     *                )
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Uprocessable Entity",
     *          @OA\JsonContent(ref="#/components/schemas/UnprocessableEntityResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return object
     * @throws Exception
     */
    public function updateStatus(Request $request, $tradeId)
    {
        $request->validate([
            'status' => 'required|in:de-listed',
        ]);
        $status = $request->status;

        try {
            Trade::where([
                'vendor_id' => data_get($request->user(), 'vendor.id'),
                'id' => $tradeId,
            ])->firstOrFail();
            DB::beginTransaction();
            $this->updateTradeStatus($request->user()->email, $tradeId, $status);
            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error('error.something_went_wrong');
        }

        return $this->success('');
    }

    /**
     * Relist Trade.
     *
     * @OA\Put(
     *      path="/api/trades/{id}/relist",
     *      operationId="relistTrade",
     *      tags={"Trades"},
     *      summary="Relist Trade",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns success",
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade ID",
     *          in="path",
     *          required=true,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Uprocessable Entity",
     *          @OA\JsonContent(ref="#/components/schemas/UnprocessableEntityResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @return object
     * @throws Exception
     */
    public function relistTrade(Request $request, $tradeId)
    {
        try {
            Trade::where([
                'vendor_id' => data_get($request->user(), 'vendor.id'),
                'id' => $tradeId,
            ])->firstOrFail();

            $tradeDetails = Trade::with(
                'offers',
                'wants'
            )->findOrFail($tradeId)->makeHidden([
                'new_offers',
                'expiry_date',
                'status_translation',
                'is_expired',
                'remaining_time',
                'remaining_hours',
            ])->toArray();

            $offers = $tradeDetails['offers'];
            unset($tradeDetails['offers']);

            $wants = $tradeDetails['wants'];
            unset($tradeDetails['wants']);
            unset($tradeDetails['id']);

            $trade = $tradeDetails;
            $trade['created_at'] = Carbon::now()->format('Y-m-d H:i:s');
            $trade['deleted_at'] = null;
            $trade['status'] = config('constants.trades.statuses.live');
            $newTradeId = Trade::insertGetId($trade);

            foreach ($offers as $offer) {
                $offer['trade_id'] = $newTradeId;
                $offer['deleted_at'] = null;
                $offer['created_at'] = Carbon::now()->format('Y-m-d H:i:s');
                TradeOffer::create($offer);

                //reduce inventory stock
                $this->updateInventoryStock($offer['inventory_id'], false);
            }

            foreach ($wants as $want) {
                $want['trade_id'] = $newTradeId;
                $want['created_at'] = Carbon::now()->format('Y-m-d H:i:s');
                TradeWant::create($want);
            }

            $payload = $this->getTradeEmailPayload($newTradeId);
            Mail::queue(new TradeRelisted($request->user()->email, $payload));
        } catch (Exception $exception) {
            report($exception);

            return $this->error('error.something_went_wrong');
        }

        return $this->success('', ['trade_id' => $newTradeId]);
    }

    /**
     * Decline trade offer.
     *
     * @OA\Put(
     *      path="/api/trades/{id}/decline-offer",
     *      operationId="declineOffer",
     *      tags={"Trades"},
     *      summary="Decline trade offer",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns success",
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade ID",
     *          in="path",
     *          required=true,
     *          @OA\Schema(
     *              type="string"
     *          )
     *      ),
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"offer_id", "is_blocked"},
     *                @OA\Property(
     *                      property="offer_id",
     *                      type="String",
     *                      description="Offer Id",
     *                      example="1"
     *                ),
     *                @OA\Property(
     *                      property="block_user",
     *                      type="String",
     *                      description="Block user for future counter offers",
     *                      example="0|1"
     *                )
     *          )
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal Server Error",
     *          @OA\JsonContent(ref="#/components/schemas/InternalServerErrorResponse")
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Uprocessable Entity",
     *          @OA\JsonContent(ref="#/components/schemas/UnprocessableEntityResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return object
     * @throws Exception
     */
    public function declineOffer(Request $request, $tradeId)
    {
        $request->validate([
            'offer_id' => 'required',
            'block_user' => 'required',
        ]);

        try {
            Trade::findOrFail($tradeId);
            $offer = TradeSubmittedOffer::findOrFail($request->offer_id);

            foreach ($offer->theirs_items as $item) {
                //restore inventory stock
                $this->updateInventoryStock($item->inventory_id, true);
            }

            TradeSubmittedOffer::where('id', $request->offer_id)
                ->update([
                    'status' => config('constants.trades.offer_statuses.decline'),
                    'is_blocked' => $request->block_user,
                ]);

            $offerDetails = $offer->toArray();
            $theirUserDetails = Vendor::with('user')->findOrFail($offerDetails['theirs_items'][0]['vendor_id'])->toArray();
            $payload = $this->getTradeOfferEmailPayload($request->offer_id);
            Mail::queue(new OfferDeclined($theirUserDetails['user']['email'], $payload));

            return $this->success('');
        } catch (Exception $e) {
            report($e);

            return $this->error('error.something_went_wrong');
        }
    }

    /**
     * Get vendor trading summary
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/vendor-summary",
     *      operationId="getVendorSummary",
     *      summary="Get vendor trading summary",
     *      description="Returns vendor trading summary",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(ref="#/components/schemas/VendorTradeSummaryResponse")
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return array
     * @throws Exception
     */
    public function getVendorSummary(Request $request)
    {
        $summary = [
            'ranking' => '100', //TODO
            'trades' => Trade::byVendor(data_get($request->user(), 'vendor.id'))->count(),
        ];

        return $this->success('', $summary);
    }

    /**
     * Pay Trade Cash manually after payment failed/payout method missing
     * -------------------------------.
     ** @OA\Post(
     *      path="/api/trades/pay-cash",
     *      operationId="payTradeCash",
     *      summary="Pay Trade Cash",
     *      description="Pay Trade Cash manally after payment failed/payout method missing",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"listing_item_order_id"},
     *                @OA\Property(
     *                      property="listing_item_order_id",
     *                      type="String",
     *                      description="listing item order id",
     *                      example="1"
     *                )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success"
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return array
     * @throws Exception
     */
    public function payTradeCash(Request $request)
    {
        $request->validate([
            'listing_item_order_id' => 'required|integer',
        ]);

        $listingItem = ListingItemOrder::with('order')->findOrFail($request->listing_item_order_id);
        if (! ($listingItem->order->orderable_type === Trade::class)) {
            abort(404);
        }

        return $this->payTradeCashAmount($listingItem->order->orderable_id, $request->listing_item_order_id);
    }

    /**
     * Dismiss Trade from trade hub
     * -------------------------------.
     ** @OA\Post(
     *      path="/api/trades/dismiss",
     *      operationId="dismiss",
     *      summary="Dismiss trade from trade hub",
     *      description="Success/Unauthenticated",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"trade_id"},
     *                @OA\Property(
     *                      property="trade_id",
     *                      type="Number",
     *                      description="Trade Id to be dismissed",
     *                      example="1"
     *                ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Success"
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(ref="#/components/schemas/UnauthorizedResponse")
     *      )
     * )
     *
     * @param Request $request
     * @return array
     * @throws Exception
     */
    public function dismiss(Request $request)
    {
        $request->validate([
            'trade_id' => 'integer|required',
        ]);

        try {
            Trade::findOrFail($request->trade_id);
            TradeDismiss::create([
                'vendor_id' => data_get($request->user(), 'vendor.id'),
                'trade_id' => $request->trade_id,
            ]);

            return $this->success('', ['status' => 'trades.success'], 200);
        } catch (Exception $e) {
            report($e);

            return $this->error('error.some_error_occurred');
        }
    }

    /**
     * @OA\Post(
     *     path="/api/trades/{id}/refund",
     *     tags={"Trades"},
     *     security={{"bearerAuth": {} }},
     *     summary="Refund Trade",
     *     description="Refund a Trade",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         @OA\Schema(type="int")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Success Response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success",type="boolean",example="true"),
     *             @OA\Property(property="message",type="string",example="trades.refund_success"),
     *         )
     *     ),
     *     @OA\Response(
     *         response="500",
     *         description="Error Response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success",type="boolean",example="false"),
     *             @OA\Property(property="message",type="string",example="trades.refund_failed"),
     *         )
     *     )
     * )
     * @param $id
     * @return JsonResponse
     */
    public function refundTrade($id)
    {
        $trade = Trade::findOrFail($id);
        $refundResponse = $trade->refund();
        if ($refundResponse['success']) {
            return $this->success('trades.refund_success');
        } else {
            Log::channel('slack_dev_exceptions_logs')->error(implode("\n", $refundResponse['errors']));

            return $this->error('trades.refund_failed');
        }
    }
}
