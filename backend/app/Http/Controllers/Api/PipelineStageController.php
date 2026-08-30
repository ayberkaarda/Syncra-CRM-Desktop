<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ReorderPipelineStagesRequest;
use App\Http\Requests\Settings\StorePipelineStageRequest;
use App\Http\Requests\Settings\UpdatePipelineStageRequest;
use App\Http\Resources\PipelineStageResource;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Settings\PipelineStageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Pipeline aşamaları — İKİ farklı uç ailesi, TEK controller.
 *
 * -----------------------------------------------------------------------------
 * `GET /api/pipeline-stages` (Faz 7) — `deals.view`
 * -----------------------------------------------------------------------------
 * Kanban panosunun sütun listesi. Ayrı bir PipelineStagePolicy YOK: yetki
 * kararı `Deal` modeli üzerinden DealPolicy'nin `viewAny` metoduna (deals.view)
 * devredilir, çünkü aşamalar yalnızca deal'lerin bağlamı olarak var (kendi
 * başına bir yetki alanı değil).
 *
 * -----------------------------------------------------------------------------
 * `/api/settings/pipeline-stages*` (Faz 10) — `settings.manage`
 * -----------------------------------------------------------------------------
 * Aşama EDİTÖRÜ. Aynı satırları okur ama bambaşka bir yetki alanıdır: bir
 * satış temsilcisi panoyu görmelidir (`deals.view`), sütunları yeniden
 * tanımlayamamalıdır.
 *
 * `index()` bu yüzden İSTEĞİN ROTASINA bakar. İki ayrı metot yazmak
 * (`index()` + `settingsIndex()`) da mümkündü; sözleşmede uçların ikisi de
 * `PipelineStageController@index`'e bağlandığı için tek metot korundu ve fark
 * `routeIs()` ile tek satırda ifade edildi. Rota adı sunucunun kendi
 * sözleşmesidir (istemci girdisi değil), dolayısıyla bu dallanma güvenlik
 * açısından bir "kullanıcı girdisine göre yetki" örneği DEĞİLDİR.
 *
 * Varsayılan `include_inactive` de rotaya göre değişir: pano yalnızca aktif
 * sütunları çizer, editör pasifleri de göstermek ZORUNDADIR — pasif bir aşama
 * yalnızca oradan geri açılabilir.
 */
class PipelineStageController extends Controller
{
    /**
     * Ayarlar uçlarının rota adı öneki.
     */
    protected const SETTINGS_INDEX_ROUTE = 'settings.pipeline-stages.index';

    public function __construct(protected PipelineStageService $stages) {}

    public function index(Request $request): JsonResponse
    {
        $isSettings = $request->routeIs(self::SETTINGS_INDEX_ROUTE);

        if ($isSettings) {
            $this->authorizeSettings();
        } else {
            Gate::authorize('viewAny', Deal::class);
        }

        // Pano: varsayılan yalnızca aktif, `?include_inactive=1` ile hepsi.
        // Editör: varsayılan HEPSİ, `?include_inactive=0` ile yalnızca aktif.
        $includeInactive = $isSettings
            ? $request->boolean('include_inactive', true)
            : $request->boolean('include_inactive');

        return response()->json([
            'data' => PipelineStageResource::collection($this->stages->list($includeInactive)),
        ]);
    }

    /**
     * `POST /api/settings/pipeline-stages` — yeni aşama, listenin sonuna.
     */
    public function store(StorePipelineStageRequest $request): JsonResponse
    {
        $this->authorizeSettings();

        $stage = $this->stages->create($request->validated());

        return (new PipelineStageResource($stage))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * `PATCH /api/settings/pipeline-stages/{stage}`.
     *
     * Bu uç yalnızca bir "güncelleme" değil, bu dalganın en riskli işlemini de
     * taşır: `is_active: false` geldiğinde aşamadaki AÇIK fırsatların ne
     * olacağına karar verilir. Kural ve gerekçeler PipelineStageService
     * içindedir; controller ince kalır.
     */
    public function update(UpdatePipelineStageRequest $request, PipelineStage $stage): JsonResponse
    {
        $this->authorizeSettings();

        $stage = $this->stages->update($stage, $request->validated(), $request->user());

        return (new PipelineStageResource($stage))->response();
    }

    /**
     * `POST /api/settings/pipeline-stages/reorder` — SÜTUN sırası.
     *
     * `deals.position` (kart sırası) ile ilgisi yoktur; hiçbir `deals` satırı
     * değişmez (bkz. ReorderPipelineStagesRequest).
     */
    public function reorder(ReorderPipelineStagesRequest $request): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json([
            'data' => PipelineStageResource::collection($this->stages->reorder($request->orderedIds())),
        ]);
    }

    /**
     * Ayarlar uçlarının tek yetki kapısı — `settings.manage`.
     */
    protected function authorizeSettings(): void
    {
        abort_unless(Gate::allows('settings.manage'), Response::HTTP_FORBIDDEN);
    }
}
