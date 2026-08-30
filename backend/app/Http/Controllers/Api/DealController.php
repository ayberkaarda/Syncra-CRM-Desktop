<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\AssignDealRequest;
use App\Http\Requests\Deals\BoardDealRequest;
use App\Http\Requests\Deals\IndexDealRequest;
use App\Http\Requests\Deals\StoreDealRequest;
use App\Http\Requests\Deals\UpdateDealRequest;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Models\Quote;
use App\Services\Deals\DealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * DealService devri. İş mantığı burada değil, DealService/DealRepository
 * içinde yer alır.
 *
 * `move` action'ı BURADA YOK — `PATCH /api/deals/{deal}/move` A şeridinin
 * DealMoveController'ına bağlıdır (bkz. routes/api.php).
 */
class DealController extends Controller
{
    public function __construct(protected DealService $deals) {}

    public function index(IndexDealRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);

        $result = $this->deals->list($request->filters());
        $paginator = $result['paginator'];

        return response()->json([
            'data' => DealResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                // FİLTRELENMİŞ TÜM kümenin toplamları — sayfalanmış
                // sonuçtan değil (bkz. DealService::list() dokümanı).
                'totals' => $result['totals'],
            ],
        ]);
    }

    /**
     * `GET /api/deals/board` — Kanban panosu. Route sırası KASITLIDIR: bu
     * sabit segment routes/api.php'de `{deal}` route-model-binding
     * parametresinden ÖNCE tanımlanmalı, yoksa Laravel `board`'u bir deal id
     * sanıp 404 üretir (Faz 6'daki `check-duplicates` tuzağıyla aynı desen).
     */
    public function board(BoardDealRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Deal::class);

        $result = $this->deals->board($request->filters(), $request->perStage());

        return response()->json([
            'data' => $result['columns'],
            'meta' => [
                'currency' => $result['currency'],
                'total_open_amount' => $result['total_open_amount'],
            ],
        ]);
    }

    public function store(StoreDealRequest $request): JsonResponse
    {
        Gate::authorize('create', Deal::class);

        $deal = $this->deals->create($request->validated());

        return (new DealResource($deal))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Deal $deal): JsonResponse
    {
        Gate::authorize('view', $deal);

        $deal = $this->deals->find($deal->id);

        $this->loadRelatedRecords($deal);

        return (new DealResource($deal))->response();
    }

    public function update(UpdateDealRequest $request, Deal $deal): JsonResponse
    {
        Gate::authorize('update', $deal);

        $deal = $this->deals->update($deal, $request->validated());

        return (new DealResource($deal))->response();
    }

    /**
     * Kazanılmış/kaybedilmiş deal'ler silinemez — DealPolicy::delete() bunu
     * reddedip Gate::authorize üzerinden 403 üretir.
     */
    public function destroy(Deal $deal): JsonResponse
    {
        Gate::authorize('delete', $deal);

        $this->deals->delete($deal);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function assign(AssignDealRequest $request, Deal $deal): JsonResponse
    {
        Gate::authorize('assign', $deal);

        $deal = $this->deals->assign($deal, (int) $request->validated()['owner_id']);

        return (new DealResource($deal))->response();
    }

    /**
     * Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli (docs/PHASE-INTL.md
     * §3). `fırsat ↔ firma` ve `fırsat ↔ kişi` burada TEKRARLANMADI: `company`/
     * `contact` alanları zaten var (bkz. DealResource) ve ters yön
     * (`firma → fırsatlar`, `kişi → fırsatlar`) sırasıyla
     * CompanyController/ContactController::loadRelatedRecords()'ta ekleniyor.
     * Burada YENİ eklenen tek şey `fırsat → teklifler`: Deal modelinde
     * `quotes()` ilişkisi YOK (Models dosya sahipliği dışında), bunun yerine
     * `Quote::where('deal_id', ...)` ile tek ek sorgu. Ters yön
     * (`Quote → deal`) BASILMAZ — `QuoteDetailPage` `Bağlanacak sayfalar`
     * listesinde yok, `QuoteController`/`QuoteResource` bu şeridin dosya
     * sahipliğinde değil.
     *
     * Yetki: `quotes.view` (`Gate::allows('viewAny', Quote::class)`) yoksa
     * anahtar hiç eklenmez.
     */
    private function loadRelatedRecords(Deal $deal): void
    {
        if (Gate::allows('viewAny', Quote::class)) {
            $quotesQuery = Quote::query()->where('deal_id', $deal->id);

            $deal->setRelation('relatedQuotes', [
                'total' => (clone $quotesQuery)->count(),
                'items' => $quotesQuery
                    ->select(['id', 'quote_number', 'title', 'status', 'total', 'currency'])
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Quote $quote) => [
                        'id' => $quote->id,
                        'quote_number' => $quote->quote_number,
                        'title' => $quote->title,
                        'status' => $quote->status,
                        'total' => (float) $quote->total,
                        'currency' => $quote->currency,
                    ])->all(),
            ]);
        }
    }
}
