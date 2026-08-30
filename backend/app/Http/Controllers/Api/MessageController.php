<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\IndexMessageRequest;
use App\Http\Requests\Chat\SearchMessageRequest;
use App\Http\Requests\Chat\StoreMessageRequest;
use App\Http\Requests\Chat\UpdateMessageRequest;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\MessageService;
use App\Services\Chat\TickState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (ConversationPolicy / MessagePolicy) + Form
 * Request doğrulaması + MessageService devri.
 *
 * -----------------------------------------------------------------------------
 * ROUTE SIRASI (routes/api.php'de)
 * -----------------------------------------------------------------------------
 * `/messages/search` SABİT segmenti `/messages/{message}` route-model-binding
 * parametresinden ÖNCE tanımlanmalıdır; yoksa Laravel `search` kelimesini bir
 * mesaj id'si sanıp 404 üretir. Faz 6'dan beri tekrar eden aynı tuzak —
 * ChatMessageTest bunu doğrulayan bir test taşır.
 */
class MessageController extends Controller
{
    public function __construct(protected MessageService $messages) {}

    /**
     * `GET /api/conversations/{conversation}/messages?before=&per_page=`
     *
     * İMLEÇLİ SAYFALAMA, en yeniden eskiye. Yanıt sözleşmesi:
     * `{ data: Message[], meta: { has_more, next_before } }` —
     * `meta.pagination` YOKTUR, çünkü toplam sayfa sayısı gibi bir kavram
     * imleçli sayfalamada tanımsızdır (ve `COUNT(*)` her kaydırmada tüm
     * konuşmayı taramak demektir).
     */
    public function index(IndexMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        return response()->json($this->messages->list(
            $conversation,
            (int) $request->user()->getKey(),
            $request->before(),
            $request->perPage(),
        ));
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('sendMessage', $conversation);

        $message = $this->messages->create($conversation, $request->user(), $request->payload());

        $ticks = TickState::forConversation(
            (int) $conversation->getKey(),
            (int) $request->user()->getKey(),
        );

        return response()->json(
            ['data' => (new MessageResource($message))->withTicks($ticks)],
            Response::HTTP_CREATED,
        );
    }

    public function update(UpdateMessageRequest $request, Message $message): JsonResponse
    {
        Gate::authorize('update', $message);

        $message = $this->messages->update($message, $request->payload());

        $ticks = TickState::forConversation(
            (int) $message->conversation_id,
            (int) $request->user()->getKey(),
        );

        return response()->json(['data' => (new MessageResource($message))->withTicks($ticks)]);
    }

    /**
     * `DELETE /api/messages/{message}` — mezar taşı bırakır (soft delete).
     *
     * 204 döner: silinen mesajın gövdesini yanıtta geri göndermek, silmenin
     * amacıyla çelişirdi. Arayüz mesajı zaten id ile bulup mezar taşına
     * çevirir.
     */
    public function destroy(Message $message): JsonResponse
    {
        Gate::authorize('delete', $message);

        $this->messages->delete($message);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * `GET /api/messages/search?q=&conversation_id=`
     *
     * Sonuçlar birden çok konuşmaya yayılabildiği için tik durumları TOPLU
     * hesaplanır (TickState::forConversations — tek `GROUP BY` sorgusu) ve
     * her mesaj kendi konuşmasının durumuyla eşlenir. Konuşma başına ayrı
     * sorgu atmak, 25 sonuçlu bir aramada 25 sorgu demekti.
     */
    public function search(SearchMessageRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Conversation::class);

        $viewerId = (int) $request->user()->getKey();

        $paginator = $this->messages->search(
            $viewerId,
            $request->term(),
            $request->conversationId(),
            $request->perPage(),
        );

        $items = $paginator->items();

        $states = TickState::forConversations(
            array_map(fn (Message $message): int => (int) $message->conversation_id, $items),
            $viewerId,
        );

        $data = [];

        foreach ($items as $message) {
            $data[] = (new MessageResource($message))
                ->withTicks($states[(int) $message->conversation_id] ?? null);
        }

        return response()->json([
            'data' => $data,
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
}
