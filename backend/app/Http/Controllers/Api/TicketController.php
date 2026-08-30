<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\IndexTicketRequest;
use App\Http\Requests\Tickets\StatusTicketRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * TicketService devri. İş mantığı burada DEĞİL — SLA hesabı SlaService,
 * durum geçişleri TicketStatusMachine, sorgular TicketRepository içindedir.
 *
 * TICKET NOTLARI İÇİN AYRI UÇ YOKTUR: notlar `activities` tablosunda
 * `type='note'` olarak tutulur ve A şeridinin `POST /api/activities` ucundan
 * (`activityable_type: "ticket"`) eklenir — gerekçe TicketResource
 * dokümanındadır.
 */
class TicketController extends Controller
{
    public function __construct(protected TicketService $tickets) {}

    public function index(IndexTicketRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        $paginator = $this->tickets->list($request->filters());

        return response()->json([
            'data' => TicketResource::collection($paginator->items()),
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
     * `GET /api/tickets/stats` — destek panosunun özet kutuları.
     *
     * ROUTE SIRASI KASITLIDIR: bu sabit segment routes/api.php'de
     * `{ticket}` route-model-binding parametresinden ÖNCE tanımlanmalı,
     * yoksa Laravel `stats`'ı bir ticket id'si sanıp 404 üretir — Faz 6
     * (`leads/check-duplicates`), Faz 7 (`deals/board`) ve Faz 8/A
     * (`tasks/calendar`) ile AYNI tuzak; TicketApiTest bunu doğrulayan bir
     * test taşır.
     *
     * Toplamlar FİLTRELERDEN VE SAYFALAMADAN BAĞIMSIZDIR (Faz 7'deki
     * `meta.totals` ilkesi): bir özet kutusu, kullanıcının o an hangi
     * filtreyi açtığına göre değişmemelidir.
     */
    public function stats(): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        return response()->json(['data' => $this->tickets->stats()]);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        Gate::authorize('create', Ticket::class);

        $ticket = $this->tickets->create($request->validated(), (int) $request->user()->id);

        return (new TicketResource($ticket))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);

        return (new TicketResource($this->tickets->find((int) $ticket->id)))->response();
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('update', $ticket);

        $ticket = $this->tickets->update($ticket, $request->validated());

        return (new TicketResource($ticket))->response();
    }

    /**
     * Çözülmüş (`resolved`) veya kapanmış (`closed`) ticket'lar silinemez —
     * TicketPolicy::delete() bunu reddedip Gate::authorize üzerinden 403
     * üretir (gerekçe o policy'nin dokümanında).
     */
    public function destroy(Ticket $ticket): JsonResponse
    {
        Gate::authorize('delete', $ticket);

        $this->tickets->delete($ticket);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * `PATCH /api/tickets/{ticket}/status` — durumun DEĞİŞTİĞİ TEK yer.
     *
     * `tickets.update` izniyle korunur (ayrı bir izin açılmadı: durum
     * değiştirmek ticket'ı güncellemenin bir biçimidir ve izin sözlüğünde
     * `tickets.status` diye bir satır yok). Geçersiz geçiş 422
     * `INVALID_STATUS_TRANSITION` döner.
     */
    public function status(StatusTicketRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('update', $ticket);

        $ticket = $this->tickets->changeStatus($ticket, (string) $request->validated()['status']);

        return (new TicketResource($ticket))->response();
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('assign', $ticket);

        $assignedTo = $request->validated()['assigned_to'] ?? null;

        $ticket = $this->tickets->assign($ticket, $assignedTo === null ? null : (int) $assignedTo);

        return (new TicketResource($ticket))->response();
    }
}
