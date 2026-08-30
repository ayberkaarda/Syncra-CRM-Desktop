<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\IndexCompanyRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\TimelineItemResource;
use App\Models\Company;
use App\Models\Deal;
use App\Models\Quote;
use App\Models\Ticket;
use App\Services\Companies\CompanyService;
use App\Services\Shared\TimelineBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * CompanyService devri. İş mantığı burada değil, CompanyService içinde yer alır.
 */
class CompanyController extends Controller
{
    public function __construct(
        protected CompanyService $companies,
        protected TimelineBuilder $timelineBuilder,
    ) {}

    public function index(IndexCompanyRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Company::class);

        $paginator = $this->companies->list($request->filters());

        return response()->json([
            'data' => CompanyResource::collection($paginator->items()),
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

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        Gate::authorize('create', Company::class);

        $company = $this->companies->create($request->validated());

        return (new CompanyResource($company))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        Gate::authorize('view', $company);

        $company = $this->companies->find($company->id);

        $this->loadRelatedRecords($company);

        return (new CompanyResource($company))->response();
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        Gate::authorize('update', $company);

        $company = $this->companies->update($company, $request->validated());

        return (new CompanyResource($company))->response();
    }

    public function destroy(Company $company): JsonResponse
    {
        Gate::authorize('delete', $company);

        $this->companies->delete($company);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function timeline(Request $request, Company $company): JsonResponse
    {
        Gate::authorize('view', $company);

        $paginator = $this->timelineBuilder->build($company, [
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 25),
        ]);

        return response()->json([
            'data' => TimelineItemResource::collection($paginator->items()),
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
     * =========================================================================
     * Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli (docs/PHASE-INTL.md
     * §3, docs/PHASE-AUDIT.md §5.1 C3 satırı)
     * =========================================================================
     *
     * `firma ↔ kişiler` BURADA TEKRAR AÇILMADI: `CompanyDetailPage` zaten
     * `useCompanyContacts` ile `GET /api/contacts?filter[company_id]=` üstünden
     * tam bir bağlı-kişiler tablosu çiziyor (mevcut `ContactController::index`
     * + `ContactPolicy::viewAny` ile zaten yetki filtreli). Aynı veriyi ikinci
     * kez, daha zayıf bir "ilk N" özetiyle burada tekrarlamak yalnızca
     * tutarsızlık riski katardı — bu yön ZATEN kapalı.
     *
     * `firma → fırsatlar` / `firma → destek talepleri`: Company modelinde
     * gerçek `deals()`/`tickets()` HasMany ilişkileri var, doğrudan onlar
     * kullanılıyor.
     *
     * `firma → teklifler`: Company modelinde `quotes()` ilişkisi YOK (bu
     * şeridin dosya listesi `app/Models/**`'i kapsamıyor, eklenemedi) — bunun
     * yerine `Quote::where('company_id', ...)` ile TEK ek sorgu. Teklifin
     * KENDİ tarafından (`Quote → company`) ters yön BASILMAZ: `QuoteResource`/
     * `QuoteController` bu şeridin dosya sahipliğinde değil ve
     * `Bağlanacak sayfalar` listesinde `QuoteDetailPage` yok.
     *
     * Yetki: her grup yalnızca ilgili modülün `viewAny` Policy'si (=`*.view`
     * izni) `true` dönerse yüklenir; aksi halde `relationLoaded()` false
     * kalır ve `CompanyResource` o anahtarı yanıta HİÇ KOYMAZ (bkz.
     * `ContactPolicy`/`TicketPolicy`/`QuotePolicy` — hepsi düz `*.view`,
     * kayıt bazlı ek kısıt yok, bu yüzden viewAny yeterli).
     *
     * N+1: kayıt başına DEĞİL, grup başına sabit 2 sorgu (count + limitli
     * get) — bu tek bir `show()` çağrısı olduğu için zaten sabit, ama Faz 13
     * H-serisi disipliniyle aynı desen izlendi.
     *
     * `setRelation()` gerçek bir Eloquent ilişkisi olmayan `quotes` için de
     * KASITLI kullanıldı: `CompanyResource`'un tüm alanları taklit ettiği
     * `relationLoaded()`/`whenLoaded()` deseniyle birebir aynı okunsun diye
     * (gerçek ilişkilerle sahte "ilişki" arasında Resource katmanında görünür
     * bir fark YOK).
     */
    private function loadRelatedRecords(Company $company): void
    {
        if (Gate::allows('viewAny', Deal::class)) {
            $company->setRelation('relatedDeals', [
                'total' => $company->deals()->count(),
                'items' => $company->deals()
                    ->select(['id', 'title', 'amount', 'currency', 'status'])
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Deal $deal) => [
                        'id' => $deal->id,
                        'title' => $deal->title,
                        'amount' => (float) $deal->amount,
                        'currency' => $deal->currency,
                        'status' => $deal->status,
                    ])->all(),
            ]);
        }

        if (Gate::allows('viewAny', Quote::class)) {
            $quotesQuery = Quote::query()->where('company_id', $company->id);

            $company->setRelation('relatedQuotes', [
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

        if (Gate::allows('viewAny', Ticket::class)) {
            $company->setRelation('relatedTickets', [
                'total' => $company->tickets()->count(),
                'items' => $company->tickets()
                    ->select(['id', 'ticket_number', 'subject', 'status', 'priority'])
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Ticket $ticket) => [
                        'id' => $ticket->id,
                        'ticket_number' => $ticket->ticket_number,
                        'subject' => $ticket->subject,
                        'status' => $ticket->status,
                        'priority' => $ticket->priority,
                    ])->all(),
            ]);
        }
    }
}
