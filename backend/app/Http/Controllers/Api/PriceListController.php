<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PriceLists\IndexPriceListRequest;
use App\Http\Requests\PriceLists\SetPriceRequest;
use App\Http\Requests\PriceLists\StorePriceListRequest;
use App\Http\Requests\PriceLists\UpdatePriceListRequest;
use App\Http\Resources\PriceListItemResource;
use App\Http\Resources\PriceListResource;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\Products\PriceListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * PriceListService devri. İş mantığı burada değil, PriceListService
 * içinde yer alır.
 */
class PriceListController extends Controller
{
    public function __construct(protected PriceListService $priceLists) {}

    public function index(IndexPriceListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', PriceList::class);

        $paginator = $this->priceLists->list($request->filters());

        return response()->json([
            'data' => PriceListResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function store(StorePriceListRequest $request): JsonResponse
    {
        Gate::authorize('create', PriceList::class);

        $priceList = $this->priceLists->create($request->validated());

        return (new PriceListResource($priceList))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PriceList $priceList): JsonResponse
    {
        Gate::authorize('view', $priceList);

        return (new PriceListResource($this->priceLists->find($priceList->id)))->response();
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): JsonResponse
    {
        Gate::authorize('update', $priceList);

        $priceList = $this->priceLists->update($priceList, $request->validated());

        return (new PriceListResource($priceList))->response();
    }

    public function destroy(PriceList $priceList): JsonResponse
    {
        Gate::authorize('delete', $priceList);

        $this->priceLists->delete($priceList);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * `GET /api/price-lists/{priceList}/products` — bu listedeki ürün
     * fiyatlarını (kalemleri) sayfalı döner.
     */
    public function products(Request $request, PriceList $priceList): JsonResponse
    {
        Gate::authorize('view', $priceList);

        $perPage = min((int) $request->integer('per_page', 25), 100);
        $paginator = $this->priceLists->products($priceList, $perPage);

        return response()->json([
            'data' => PriceListItemResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * `PUT /api/price-lists/{priceList}/products/{product}` — listedeki bir
     * ürün fiyatını ekler/günceller (upsert).
     *
     * Durum kodu her zaman 200: bu bir `PUT` (idempotent "set") ucudur, ilk
     * çağrıda kalemi oluştursa da anlamı "kaydı yarat" değil "fiyatı bu
     * değere ayarla"dır — Laravel'in `wasRecentlyCreated`'a bakarak otomatik
     * ürettiği 201'i burada bilinçli olarak EZİYORUZ.
     */
    public function setPrice(SetPriceRequest $request, PriceList $priceList, Product $product): JsonResponse
    {
        Gate::authorize('update', $priceList);

        $item = $this->priceLists->setPrice($priceList, $product->id, (float) $request->validated('unit_price'));

        return (new PriceListItemResource($item))->response()->setStatusCode(Response::HTTP_OK);
    }

    /**
     * `DELETE /api/price-lists/{priceList}/products/{product}` — listedeki
     * bir ürün fiyat kaydını kaldırır (ürünün kendisini DEĞİL).
     */
    public function removePrice(PriceList $priceList, Product $product): JsonResponse
    {
        Gate::authorize('update', $priceList);

        $this->priceLists->removePrice($priceList, $product->id);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
