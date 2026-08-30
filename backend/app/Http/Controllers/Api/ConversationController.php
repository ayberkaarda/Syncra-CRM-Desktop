<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\CursorConversationRequest;
use App\Http\Requests\Chat\ForRecordConversationRequest;
use App\Http\Requests\Chat\IndexConversationRequest;
use App\Http\Requests\Chat\MuteConversationRequest;
use App\Http\Requests\Chat\StoreConversationRequest;
use App\Http\Requests\Chat\StoreMemberRequest;
use App\Http\Requests\Chat\UpdateConversationRequest;
use App\Http\Resources\Chat\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use App\Services\Chat\RecordChatRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (ConversationPolicy) + Form Request
 * doğrulaması + ConversationService devri. İş kuralları (get-or-create,
 * sahiplik devri, üyelik) servistedir.
 *
 * -----------------------------------------------------------------------------
 * ROUTE SIRASI (routes/api.php'de)
 * -----------------------------------------------------------------------------
 * `unread-count` ve `for-record` SABİT segmentleri `{conversation}`
 * route-model-binding parametresinden ÖNCE tanımlanmalıdır; yoksa Laravel
 * bunları bir konuşma id'si sanıp 404 üretir. Faz 6'dan (`check-duplicates`)
 * beri her fazda tekrar eden aynı tuzak — ChatConversationTest bunu doğrulayan
 * bir test taşır.
 */
class ConversationController extends Controller
{
    public function __construct(
        protected ConversationService $conversations,
        protected MessageService $messages,
    ) {}

    public function index(IndexConversationRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Conversation::class);

        $viewerId = (int) $request->user()->getKey();
        $paginator = $this->conversations->list($viewerId, $request->filters());

        return response()->json([
            'data' => ConversationResource::manyForViewer($paginator->items(), $viewerId),
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
     * `GET /api/conversations/unread-count` — kenar çubuğu rozetinin ilk
     * boyaması. Sonrasında sayı `.chat.unread` olayıyla canlı ilerler; bu uç
     * yalnızca sayfa açılışında ve yeniden bağlanmada çağrılır.
     */
    public function unreadCount(): JsonResponse
    {
        Gate::authorize('viewAny', Conversation::class);

        return response()->json([
            'data' => $this->conversations->unreadTotals((int) request()->user()->getKey()),
        ]);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        Gate::authorize('create', Conversation::class);

        $conversation = $this->conversations->create($request->validated(), $request->user());

        // `dm` GET-OR-CREATE'tir: var olan konuşma dönerse 201 yanıltıcı
        // olurdu ("yeni kayıt oluşturuldu"). `wasRecentlyCreated` gerçekten
        // yeni satır yazılıp yazılmadığını söyler.
        $status = $conversation->wasRecentlyCreated
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;

        return (new ConversationResource($conversation))
            ->forViewer((int) $request->user()->getKey())
            ->response()
            ->setStatusCode($status);
    }

    /**
     * `POST /api/conversations/for-record` — kayda bağlı sohbeti aç/getir.
     *
     * Yetki kaydın KENDİ iznine bakar (`deals.view` / `tickets.view`),
     * `presence-record.{type}.{id}` kanalıyla aynı kural. Kaydı göremeyen
     * kullanıcı 403 alır; kayıt hiç yoksa istek 422'de (Form Request) durur.
     */
    public function forRecord(ForRecordConversationRequest $request): JsonResponse
    {
        Gate::authorize('create', Conversation::class);

        $type = $request->recordType();

        abort_unless(
            $request->user()->can(RecordChatRegistry::permission($type)),
            Response::HTTP_FORBIDDEN,
        );

        $conversation = $this->conversations->forRecord($type, $request->recordId(), $request->user());

        // `store()` ile aynı sözleşme: yeni satır yazıldıysa 201, var olan
        // konuşma döndüyse 200. Durum kodu AÇIKÇA yazılır — JsonResource
        // varsayılan olarak `wasRecentlyCreated`'a bakıp kendiliğinden 201
        // üretiyor, ama sözleşme örtük bir vendor davranışına bırakılmaz.
        $status = $conversation->wasRecentlyCreated
            ? Response::HTTP_CREATED
            : Response::HTTP_OK;

        return (new ConversationResource($conversation))
            ->forViewer((int) $request->user()->getKey())
            ->response()
            ->setStatusCode($status);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        return (new ConversationResource($this->conversations->load($conversation)))
            ->forViewer((int) request()->user()->getKey())
            ->response();
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('update', $conversation);

        $conversation = $this->conversations->update($conversation, $request->validated());

        return (new ConversationResource($conversation))
            ->forViewer((int) $request->user()->getKey())
            ->response();
    }

    public function destroy(Conversation $conversation): JsonResponse
    {
        Gate::authorize('delete', $conversation);

        $this->conversations->delete($conversation);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function storeMember(StoreMemberRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('addMember', $conversation);

        $conversation = $this->conversations->addMembers($conversation, $request->userIds());

        return (new ConversationResource($conversation))
            ->forViewer((int) $request->user()->getKey())
            ->response();
    }

    public function destroyMember(Conversation $conversation, User $user): JsonResponse
    {
        Gate::authorize('removeMember', $conversation);

        $conversation = $this->conversations->removeMember($conversation, (int) $user->getKey());

        return (new ConversationResource($conversation))
            ->forViewer((int) request()->user()->getKey())
            ->response();
    }

    /**
     * `POST /api/conversations/{conversation}/leave`.
     *
     * 204 döner, konuşmayı DÖNMEZ: ayrılan kullanıcı artık o konuşmayı
     * görmemektedir ve gövdede geri göndermek, az önce kaybettiği erişimi
     * yanıt içinde ona geri vermek olurdu.
     */
    public function leave(Conversation $conversation): JsonResponse
    {
        Gate::authorize('leave', $conversation);

        $this->conversations->leave($conversation, (int) request()->user()->getKey());

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function mute(MuteConversationRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('participate', $conversation);

        $conversation = $this->conversations->setMuted(
            $conversation,
            (int) $request->user()->getKey(),
            $request->isMuted(),
        );

        return (new ConversationResource($conversation))
            ->forViewer((int) $request->user()->getKey())
            ->response();
    }

    /**
     * `POST /api/conversations/{conversation}/read` — okuma imlecini ilerletir,
     * okunmamış sayacını yeniden hesaplar, `.message.read` yayınlar.
     */
    public function read(CursorConversationRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('participate', $conversation);

        return response()->json([
            'data' => $this->messages->markRead(
                $conversation,
                (int) $request->user()->getKey(),
                $request->messageId(),
            ),
        ]);
    }

    /**
     * `POST /api/conversations/{conversation}/delivered` — iletim imlecini
     * ilerletir, `.message.delivered` yayınlar. Okunmamış sayacına DOKUNMAZ.
     */
    public function delivered(CursorConversationRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('participate', $conversation);

        return response()->json([
            'data' => $this->messages->markDelivered(
                $conversation,
                (int) $request->user()->getKey(),
                $request->messageId(),
            ),
        ]);
    }
}
