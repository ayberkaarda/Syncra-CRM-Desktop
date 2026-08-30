<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\IndexContactRequest;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\TimelineItemResource;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Quote;
use App\Models\Ticket;
use App\Services\Contacts\ContactService;
use App\Services\Shared\TimelineBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * ContactService devri. İş mantığı burada değil, ContactService içinde yer alır.
 */
class ContactController extends Controller
{
    public function __construct(
        protected ContactService $contacts,
        protected TimelineBuilder $timelineBuilder,
    ) {}

    public function index(IndexContactRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Contact::class);

        $paginator = $this->contacts->list($request->filters());

        return response()->json([
            'data' => ContactResource::collection($paginator->items()),
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

    public function store(StoreContactRequest $request): JsonResponse
    {
        Gate::authorize('create', Contact::class);

        $contact = $this->contacts->create($request->validated());

        return (new ContactResource($contact))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Contact $contact): JsonResponse
    {
        Gate::authorize('view', $contact);

        $contact = $this->contacts->find($contact->id);

        $this->loadRelatedRecords($contact);

        return (new ContactResource($contact))->response();
    }

    public function update(UpdateContactRequest $request, Contact $contact): JsonResponse
    {
        Gate::authorize('update', $contact);

        $contact = $this->contacts->update($contact, $request->validated());

        return (new ContactResource($contact))->response();
    }

    public function destroy(Contact $contact): JsonResponse
    {
        Gate::authorize('delete', $contact);

        $this->contacts->delete($contact);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function timeline(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize('view', $contact);

        $paginator = $this->timelineBuilder->build($contact, [
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
     * Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli (docs/PHASE-INTL.md
     * §3). `kişi ↔ firma` burada TEKRARLANMADI: `contact.company` alanı zaten
     * var (bkz. ContactResource) ve `ContactDetailPage`'in ana bilgi kartında
     * gösteriliyor; ters yön (`firma → kişiler`) de zaten
     * `CompanyDetailPage`'te ayrı bir uçtan tam liste olarak çiziliyor (bkz.
     * CompanyController::loadRelatedRecords()). Bu turdan önce eklenmiş olan
     * `kişi → fırsatlar` ve `kişi → destek talepleri` (Contact modelinde
     * gerçek `deals()`/`tickets()` HasMany ilişkileri var); bu turda
     * `kişi → teklifler` eklendi.
     *
     * `kişi → teklifler`: Contact modelinde `quotes()` ilişkisi YOK (bu
     * şeridin dosya listesi `app/Models/**`'i kapsamıyor, eklenemedi) —
     * CompanyController'daki aynı boşlukla birebir aynı gerekçeyle
     * `Quote::where('contact_id', ...)` ile TEK ek sorgu kullanıldı.
     * Tutarlar ÇEVRİLMEZ: teklif kendi `currency`'siyle basılan bir
     * belgedir (kalıcı kapsam kararı).
     *
     * Yetki: grup yalnızca ilgili modülün `viewAny` Policy'si `true` dönerse
     * yüklenir; aksi halde anahtar yanıtta hiç yer almaz (bkz.
     * `ContactResource`).
     */
    private function loadRelatedRecords(Contact $contact): void
    {
        if (Gate::allows('viewAny', Deal::class)) {
            $contact->setRelation('relatedDeals', [
                'total' => $contact->deals()->count(),
                'items' => $contact->deals()
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

        if (Gate::allows('viewAny', Ticket::class)) {
            $contact->setRelation('relatedTickets', [
                'total' => $contact->tickets()->count(),
                'items' => $contact->tickets()
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

        if (Gate::allows('viewAny', Quote::class)) {
            $quotesQuery = Quote::query()->where('contact_id', $contact->id);

            $contact->setRelation('relatedQuotes', [
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
