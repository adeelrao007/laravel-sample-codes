<?php

namespace App\Http\Controllers;

use App\Models\TradePreference;
use App\Traits\RespondsWithJson;
use App\Traits\TradePreferencesTrait;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TradePreferenceController extends Controller
{
    use TradePreferencesTrait, RespondsWithJson;

    /**
     * Get Vendor trade preferences
     * -------------------------------.
     ** @OA\Get(
     *      path="/api/trades/preferences",
     *      operationId="get",
     *      summary="Get vendor want items",
     *      description="Returns vendor trade preferences",
     *      tags={"Trades"},
     *      security={{ "bearerAuth": {} }},
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
     * @return array
     * @todo Get vendor want items with filters.
     */
    public function get(Request $request)
    {
        $vendorId = data_get($request->user(), 'vendor.id');
        if (! $vendorId) {
            return $this->error('error.something_went_wrong');
        }
        $vendorPreferences = TradePreference::byVendor($vendorId)->first();

        return $this->success('', $this->preparePreferences($vendorPreferences ?? false));
    }

    /**
     * Create/Update vendor trade preference.
     *
     * @OA\Post(
     *      path="/api/trades/preferences",
     *      operationId="createOrUpdate",
     *      tags={"Trades"},
     *      summary="Create/Update vendor trade preference",
     *      security={{ "bearerAuth": {} }},
     *      description="Returns success",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              type="object",
     *              required={},
     *              @OA\Property(
     *                  property="inventory",
     *                  type="String",
     *                  description="Inventory visibility",
     *                  example="public, private or customize"
     *              ),
     *              @OA\Property(
     *                  property="public_inventories",
     *                  type="String",
     *                  description="comma separated public inventories in case vendor is selecting customize visibility",
     *                  example="1,2,3,4"
     *              ),
     *              @OA\Property(
     *                  property="fair_trade",
     *                  type="String",
     *                  description="Percentage at which trade is considered as fair",
     *                  example="80"
     *              ),
     *              @OA\Property(
     *                  property="sneaker_interest",
     *                  type="String",
     *                  description="Percentage for interest in sneakers",
     *                  example="100"
     *              ),
     *              @OA\Property(
     *                  property="apparel_interest",
     *                  type="String",
     *                  description="Percentage for interest in apparel",
     *                  example="100"
     *              ),
     *              @OA\Property(
     *                  property="accessories_interest",
     *                  type="String",
     *                  description="Percentage for interest in accessories",
     *                  example="100"
     *              ),
     *              @OA\Property(
     *                  property="size_types",
     *                  type="String",
     *                  description="comma separated size types to be preferred",
     *                  example="1,2,3,4"
     *              ),
     *              @OA\Property(
     *                  property="sneaker_sizes",
     *                  type="String",
     *                  description="comma separated sneaker sizes to be preferred",
     *                  example="1,2,3,4"
     *              ),
     *              @OA\Property(
     *                  property="apparel_sizes",
     *                  type="String",
     *                  description="comma separated apparel sizes to be preferred",
     *                  example="1,2,3,4"
     *              ),
     *              @OA\Property(
     *                  property="brands",
     *                  type="String",
     *                  description="comma separated brands to be preferred",
     *                  example="1,2,3,4"
     *              )
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
     *          response=500,
     *          description="Internal Server Error"
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
    public function createOrUpdate(Request $request)
    {
        $request->validate([
            'inventory' => 'nullable|in:public,private,customize',
        ]);

        $vendorId = data_get($request->user(), 'vendor.id');
        $vendorPreferences = TradePreference::byVendor($vendorId)->first();

        DB::beginTransaction();
        try {
            $this->createOrUpdatePreference($request, $vendorId, $vendorPreferences);

            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            report($exception);

            return $this->error(Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR]);
        }

        return $this->success('trades.preferences.updated_successfully', [], 200);
    }
}
