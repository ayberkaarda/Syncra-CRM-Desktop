<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavedView\IndexSavedViewRequest;
use App\Http\Requests\SavedView\StoreSavedViewRequest;
use App\Http\Requests\SavedView\UpdateSavedViewRequest;
use App\Http\Resources\SavedViewResource;
use App\Models\SavedView;
use App\Services\SavedViews\SavedViewQueryValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `/api/saved-views*` — Faz 14 / İz F, C2 (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.4).
 *
 * ============================================================================
 * CONFUSED DEPUTY YOK — BU CONTROLLER ASLA VERİ (deal/lead/... satırı) DÖNDÜRMEZ
 * ============================================================================
 * `docs/PHASE-AUDIT.md` §5.4: "paylaşılan bir görünüm AÇAN kullanıcının yetkisiyle
 * çalışmalı, OLUŞTURANIN yetkisiyle DEĞİL". Bu Controller'ın hiçbir ucu bir deal/lead/
 * contact/... sorgusu ÇALIŞTIRMAZ — yalnızca `saved_views` tablosundaki METADATA'yı
 * (isim, modül, saklanmış filtre) CRUD'lar. Bir görünümü "açmak" ayrı bir uç DEĞİLDİR:
 * frontend `index()`'ten aldığı `query_json`'ı kendi URL'ine yazar, GERÇEK veri her zaman
 * ilgili modülün KENDİ liste ucundan (`GET /api/deals` vb.) — AÇAN kullanıcının kendi
 * Sanctum oturumu ve kendi Policy'siyle — çekilir. Bu mimari, "oluşturanın yetkisiyle
 * çalışma" riskini TASARIM GEREĞİ ortadan kaldırır: bu dosyada oluşturanın kimliğinin
 * açanın sorgusuna karıştığı hiçbir kod yolu yoktur.
 *
 * ============================================================================
 * query_json — YAZMA ve OKUMA'da YENİDEN DOĞRULAMA
 * ============================================================================
 * `store()`/`update()` ham `query_json`'ı OLDUĞU GİBİ KAYDETMEZ:
 * `SavedViewQueryValidator::validateStrict()` modül başına beyaz listeye karşı doğrular,
 * bilinmeyen/izinsiz bir alan varsa 422 fırlatır (sessizce atmaz). `index()`'in döndürdüğü
 * `SavedViewResource` da AYRICA `sanitizeForRead()`'den geçer — bkz. o sınıfların
 * dokümanı.
 *
 * ============================================================================
 * SAHİPLİK/PAYLAŞIM
 * ============================================================================
 * `index()`: kendi görünümlerin + `is_shared=true` olan HERKESİN görünümü (görev tanımı:
 * "is_shared olanı herkes GÖRÜR ve AÇAR"). `update()`/`destroy()`: `SavedViewPolicy` yalnız
 * sahibine izin verir — `is_shared` bunu değiştirmez.
 */
class SavedViewController extends Controller
{
    public function index(IndexSavedViewRequest $request): JsonResponse
    {
        $module = $request->validated('module');

        Gate::authorize('viewAny', [SavedView::class, $module]);

        $user = $request->user();

        $views = SavedView::query()
            ->with('user')
            ->where('module', $module)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)->orWhere('is_shared', true);
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => SavedViewResource::collection($views),
        ]);
    }

    public function store(StoreSavedViewRequest $request): JsonResponse
    {
        $data = $request->validated();

        Gate::authorize('create', [SavedView::class, $data['module']]);

        $normalizedQuery = SavedViewQueryValidator::validateStrict($data['module'], $data['query_json']);

        $savedView = SavedView::create([
            'user_id' => $request->user()->id,
            'module' => $data['module'],
            'name' => $data['name'],
            'query_json' => $normalizedQuery,
            'is_shared' => $data['is_shared'] ?? false,
        ]);

        return (new SavedViewResource($savedView->load('user')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateSavedViewRequest $request, SavedView $savedView): JsonResponse
    {
        Gate::authorize('update', $savedView);

        $data = $request->validated();

        if (array_key_exists('query_json', $data)) {
            $data['query_json'] = SavedViewQueryValidator::validateStrict($savedView->module, $data['query_json']);
        }

        $savedView->update($data);

        return (new SavedViewResource($savedView->load('user')))->response();
    }

    public function destroy(SavedView $savedView): JsonResponse
    {
        Gate::authorize('delete', $savedView);

        $savedView->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
