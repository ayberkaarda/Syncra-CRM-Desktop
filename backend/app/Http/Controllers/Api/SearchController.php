<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\SearchResultResource;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;

/**
 * İnce controller: doğrulama (`SearchRequest`) + `GlobalSearchService`
 * devri. Modül bazlı yetkilendirme kararı BİLEREK burada değil, serviste —
 * bkz. `GlobalSearchService` sınıf dokümanı ("İZİNSİZ MODÜLÜN ANAHTARI...").
 */
class SearchController extends Controller
{
    public function __construct(protected GlobalSearchService $search) {}

    public function index(SearchRequest $request): JsonResponse
    {
        $grouped = $this->search->search($request->user(), $request->term());

        $data = array_map(
            fn (array $items) => SearchResultResource::collection($items),
            $grouped
        );

        return response()->json(['data' => $data]);
    }
}
