<?php

namespace App\Http\Controllers;

use App\Models\WantCombination;
use App\Models\WantItem;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TradeWantsController extends Controller
{
    /**
     * Get Vendor want items
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/wants",
     *      operationId="index",
     *      summary="Get vendor want items",
     *      description="Returns list of vendor want items with pagination options. For combination types other filters are not required except page no",
     *      tags={"Trades", "Wants"},
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
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => 'string|nullable',
            'size_types' => 'string|nullable',
            'sizes' => 'string|nullable',
            'sort_by' => 'string|nullable|in:price_asc,price_desc,date_asc,date_desc,size_asc,size_desc',
            'type' => 'string|required|in:general_items,combinations',
        ]);

        if (! $request->user()->vendor) {
            return $this->error('error.something_went_wrong');
        }

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
            config('constants.trades.type_combination') => 'App\Models\WantCombination::getVendorWantCombinations',
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
            'wants.success',
            new LengthAwarePaginator(
                array_values(($sortedCollection->forPage($pageNo, $perPageItems))->toArray()),
                $paginatedItems->total(),
                $perPageItems,
                $pageNo
            )
        );
    }

    /**
     * Get Want Combination against combination ID
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/wants/combination/item",
     *      operationId="getCombination",
     *      summary="Get Want Combination against combination ID",
     *      description="Returns Combination against combination ID",
     *      tags={"Trades", "Wants", "Combination"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Parameter(
     *          name="combination_id",
     *          description="Existing combination's id",
     *          required=true,
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
    public function getCombination(Request $request): JsonResponse
    {
        $request->validate(['combination_id' => 'required']);
        $vendor = $request->user()->vendor;
        if (! $vendor) {
            return $this->error('error.something_went_wrong');
        }
        $combination = WantCombination::getVendorWantCombination($vendor, $request->get('combination_id'));

        return $this->success('success', $combination);
    }

    /**
     * Get combination ids for a vendor to show in select box options
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/wants/combination/options",
     *      operationId="combinationOptions",
     *      summary="Get combination ids for a vendor to show in select box options",
     *      description="Returns list of combination ids against loggedIn user's vendor id",
     *      tags={"Trades", "Wants", "Combinations"},
     *      security={{ "bearerAuth": {} }},
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *          @OA\JsonContent(
     *              @OA\Property(property="data", type="object" ),
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
    public function combinationOptions(Request $request): JsonResponse
    {
        $vendorId = data_get($request->user(), 'vendor.id');
        if (! $vendorId) {
            return $this->error('error.something_went_wrong');
        }
        $ids = WantCombination::where('vendor_id', $vendorId)->pluck('id');

        return $this->success('wants.success', $ids);
    }

    /**
     * Store Trade Want Items.
     *
     * @OA\Post(
     *      path="/api/trades/wants",
     *      operationId="store",
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
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required',
            'quantity' => 'required',
            'size_id' => 'required',
            'packaging_condition_id' => 'required',
            'year' => 'nullable',
            'wants_list_type' => 'required|array',
        ]);

        try {
            DB::beginTransaction();
            $user = $request->user()->load('vendor');
            foreach ($request->wants_list_type as $list_type) {
                $wantItem = WantItem::create([
                    'product_id' => (int) $request['product_id'],
                    'vendor_id' => $user->vendor->id,
                    'size_id' => (int) $request['size_id'],
                    'packaging_condition_id' => (int) $request['packaging_condition_id'],
                    'year' => $request['year'],
                    'type' => ($list_type == 'general_wants') ? 'general' : 'combination',
                ]);
                if ($list_type != 'general_wants') {
                    $combinationId = $list_type;
                    if ($combinationId !== 'new') {
                        // add item to existing combination
                        $combinationDetails = WantCombination::find($combinationId);
                        if ($combinationDetails) {
                            $combinationItemsCount = $combinationDetails->wantItems()->count();
                            if ($combinationItemsCount >= 3) {
                                return $this->error('error.combination_already_has_maximum_items');
                            }
                            $combinationDetails->wantItems()->attach($wantItem);
                        } else {
                            return $this->error('error.invalid_combination_selected');
                        }
                    } else {
                        //create new combination
                        WantCombination::create(['vendor_id' => $user->vendor->id])
                            ->wantItems()->attach([$wantItem->id]);
                    }
                }
            }
            DB::commit();

            return $this->success('wants.success');
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error($exception->getMessage());
        }
    }

    /**
     * Store Combination.
     *
     * @OA\Post(
     *      path="/api/trades/wants/combination",
     *      operationId="storeCombination",
     *      tags={"Trades", "Wants", "Combination"},
     *      summary="Create new combination",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns success message",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"selected_ids"},
     *                @OA\Property(
     *                      property="selected_ids",
     *                      type="list",
     *                      description="Trade Wants ids",
     *                      example="[1, 2, 3]"
     *                )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="status", type="string" ),
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
     * @return JsonResponse
     */
    public function storeCombination(Request $request): JsonResponse
    {
        $request->validate(['selected_ids' => 'required|array']);

        try {
            DB::beginTransaction();
            WantCombination::create(['vendor_id' => $request->user()->vendor->id])
                ->wantItems()->attach($request->get('selected_ids'));
            DB::commit();

            return $this->success('wants.success');
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error($exception->getMessage());
        }
    }

    /**
     * Add Item In Combination.
     *
     * @OA\Post(
     *      path="/api/trades/wants/combination/items",
     *      operationId="addItemInCombination",
     *      tags={"Trades", "Wants", "Combinations", "Item"},
     *      summary="Add Want Item In Want Combination",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns new Trade",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={"status"},
     *              @OA\Property(
     *                  property="product_id",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *              @OA\Property(
     *                  property="quantity",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *              @OA\Property(
     *                  property="size_id",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *              @OA\Property(
     *                  property="packaging_condition_id",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *              @OA\Property(
     *                  property="year",
     *                  type="Integer",
     *                  example="2022"
     *              ),
     *              @OA\Property(
     *                  property="want_combination_id",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *          @OA\JsonContent(
     *              @OA\Property(property="status", type="string" ),
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
     * @return JsonResponse
     */
    public function addItemInCombination(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required',
            'quantity' => 'required',
            'size_id' => 'required',
            'packaging_condition_id' => 'required',
            'year' => 'nullable',
            'want_combination_id' => 'required|exists:want_combinations,id',
        ]);

        try {
            DB::beginTransaction();
            $user = $request->user()->load('vendor');

            $wantItem = WantItem::create([
                'product_id' => (int) $request['product_id'],
                'vendor_id' => $user->vendor->id,
                'size_id' => (int) $request['size_id'],
                'packaging_condition_id' => (int) $request['packaging_condition_id'],
                'year' => $request['year'],
                'type' => 'combination',
            ]);

            WantCombination::find($request->get('want_combination_id'))->wantItems()->attach($wantItem);

            DB::commit();

            return $this->success('wants.success');
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error($exception->getMessage());
        }
    }

    /**
     * Update Trade Wants.
     *
     * @OA\Put(
     *      path="/api/trades/wants/{id}",
     *      operationId="update",
     *      tags={"Trades", "Wants"},
     *      summary="Update trade Wants",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns Success Message",
     *      @OA\Parameter(
     *          name="id",
     *          description="Trade Want ID",
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
     *                  property="quantity",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *              @OA\Property(
     *                  property="size_id",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *              @OA\Property(
     *                  property="packaging_condition_id",
     *                  type="Integer",
     *                  example="1"
     *              ),
     *              @OA\Property(
     *                  property="year",
     *                  type="Integer",
     *                  example="2022"
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
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
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'quantity' => 'required',
            'size_id' => 'required',
            'packaging_condition_id' => 'required',
            'year' => 'nullable',
        ]);

        try {
            DB::beginTransaction();
            WantItem::whereId($id)->update([
                'size_id' => (int) $request['size_id'],
                'packaging_condition_id' => (int) $request['packaging_condition_id'],
                'year' => $request['year'],
            ]);

            DB::commit();

            return $this->success('wants.success');
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error($exception->getMessage());
        }
    }

    /**
     * Delete trade want item or combination.
     * Or detach an item from a combination.
     *
     * @OA\Delete(
     *      path="/api/trades/wants/destroy",
     *      operationId="destroy",
     *      tags={"Trades", "Wants"},
     *      summary="Delete trades Want",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns Success/Error",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\MediaType(
     *              mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(
     *                      property="selected_ids",
     *                      type="list",
     *                      example="[1,2,3]",
     *                      description="Selected want list Ids"
     *                  ),
     *                  @OA\Property(
     *                      property="type",
     *                      type="string",
     *                      example="delete",
     *                      description="Any from [delete, delete_combination, combination_item]"
     *                  ),
     *                  @OA\Property(
     *                      property="want_item_id",
     *                      type="integer",
     *                      example="1",
     *                      description="To detach want item from a combination, required only when `type` is `combination_item`"
     *                  ),
     *                  @OA\Property(
     *                      property="combination_id",
     *                      type="integer",
     *                      example="1",
     *                      description="The combination from which to detach the want item, required only when `type` is `combination_item`"
     *                  ),
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
     * @return JsonResponse
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:delete,delete_combination,combination_item',
            'selected_ids' => 'required_unless:type,combination_item|array',
            'want_item_id' => 'required_if:type,combination_item',
            'combination_id' => 'required_with:want_item_id',
        ]);
        $ids = $request->get('selected_ids');
        $type = $request->get('type');

        try {
            DB::beginTransaction();

            if ($type == 'delete') {
                WantItem::destroy($ids);
            } elseif ($type == 'delete_combination') {
                WantCombination::destroy($ids);
            } else {
                $wantItemId = $request->get('want_item_id');
                $combinationId = $request->get('combination_id');
                $combination = WantCombination::with('wantItems')->find($combinationId);
                if ($combination->wantItems->count() == 1) {
                    $combination->delete();
                } else {
                    $combination->wantItems()->detach($wantItemId);
                }
            }

            DB::commit();

            return $this->success('wants.success');
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error($exception->getMessage());
        }
    }
}
