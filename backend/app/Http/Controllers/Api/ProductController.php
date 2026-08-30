<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\IndexProductRequest;
use App\Http\Requests\Products\PriceProductRequest;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Products\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * ProductService devri. İş mantığı burada değil, ProductService içinde yer
 * alır.
 */
class ProductController extends Controller
{
    public function __construct(protected ProductService $products) {}

    public function index(IndexProductRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $paginator = $this->products->list($request->filters());

        return response()->json([
            'data' => ProductResource::collection($paginator->items()),
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
     * `GET /api/products/categories` — mevcut ürünlerin benzersiz kategori
     * listesi (filtre dropdown'ı için).
     *
     * ROUTE SIRASI KASITLIDIR: bu sabit segment routes/api.php'de
     * `{product}` route-model-binding parametresinden ÖNCE tanımlanmalı,
     * yoksa Laravel `categories`'i bir product id'si sanıp 404 üretir — Faz 6
     * (`leads/check-duplicates`), Faz 7 (`deals/board`) ve Faz 8
     * (`tasks/calendar`, `tickets/stats`) ile AYNI tuzak; ProductApiTest bunu
     * doğrulayan bir test taşır.
     */
    public function categories(): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        return response()->json(['data' => $this->products->categories()->values()]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $product = $this->products->create($request->validated());

        return (new ProductResource($product))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        return (new ProductResource($this->products->find($product->id)))->response();
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        Gate::authorize('update', $product);

        $product = $this->products->update($product, $request->validated());

        return (new ProductResource($product))->response();
    }

    public function destroy(Product $product): JsonResponse
    {
        Gate::authorize('delete', $product);

        $this->products->delete($product);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * `GET /api/products/{product}/price?price_list_id=` — teklif kalemi
     * eklerken kullanılacak fiyat çözümleme. Karar/gerekçe
     * ProductService::resolvePrice() içindedir.
     */
    public function price(PriceProductRequest $request, Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        $priceListId = $request->validated('price_list_id');

        return response()->json([
            'data' => $this->products->resolvePrice($product, $priceListId !== null ? (int) $priceListId : null),
        ]);
    }
}
