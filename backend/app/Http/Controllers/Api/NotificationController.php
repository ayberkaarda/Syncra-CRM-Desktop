<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\Notifications\NotificationReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * İnce controller: yetkilendirme (Gate — model policy yok, `notifications.view`
 * doğrudan izin adı, tıpkı `App\Http\Controllers\Api\LogController`'daki
 * `logs.view`/`logs.export` deseni gibi) + sahiplik kontrolü.
 *
 * İş mantığı yok çünkü olacak bir şey yok: `Illuminate\Notifications\
 * DatabaseNotification` zaten `markAsRead()` / `scopeUnread()` gibi
 * hazır yardımcılarla geliyor (vendor kaynağı okunarak doğrulandı) — ayrı
 * bir servis/repository katmanı bu ince CRUD'u sarmalamaktan başka bir şey
 * yapmazdı.
 *
 * SAHİPLİK: kullanıcı yalnızca KENDİ bildirimlerini görür/işaretler/siler.
 * `{notification}` route-model-binding'i uuid VAR OLDUĞU sürece HERKESİN
 * satırını bulur (Eloquent binding sahiplik bilmez) — bu yüzden her
 * yazma/okuma ucu `authorizeOwnership()` ile başkasının satırını 404'e
 * çevirir (403 DEĞİL — varlık sızdırma engellenir, bkz. Faz 10 sözleşmesi).
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationReadService $reads) {}

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('notifications.view'), Response::HTTP_FORBIDDEN);

        $validated = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.read' => ['sometimes', Rule::in(['read', 'unread'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $query = $request->user()->notifications();

        $readFilter = $validated['filter']['read'] ?? null;

        if ($readFilter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($readFilter === 'read') {
            $query->whereNotNull('read_at');
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE));

        return response()->json([
            'data' => NotificationResource::collection($paginator->items()),
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

    public function unreadCount(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('notifications.view'), Response::HTTP_FORBIDDEN);

        return response()->json([
            'data' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        abort_unless(Gate::allows('notifications.view'), Response::HTTP_FORBIDDEN);

        $this->authorizeOwnership($request, $notification);

        $this->reads->markRead($notification);

        return (new NotificationResource($notification))->response();
    }

    public function markAllRead(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('notifications.view'), Response::HTTP_FORBIDDEN);

        /*
         * Protocol §2.3 #2 - this used to be a single bulk UPDATE. It bypassed
         * Eloquent entirely, so `sync_version` was never stamped and the whole
         * batch was invisible to a desktop client. Even stamping it in one
         * statement would be wrong: §2.5/K-C requires ONE DISTINCT version per
         * row, because the pull cursor is a single scalar and a LIMIT boundary
         * inside a tie loses rows permanently. The chunked model loop lives in
         * NotificationReadService, which the sync push applier calls too (K7).
         */
        $this->reads->markAllRead($request->user());

        return response()->json([
            'data' => [
                'unread_count' => $this->reads->unreadCount($request->user()),
            ],
        ]);
    }

    public function destroy(Request $request, DatabaseNotification $notification): JsonResponse
    {
        abort_unless(Gate::allows('notifications.view'), Response::HTTP_FORBIDDEN);

        $this->authorizeOwnership($request, $notification);

        $notification->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Kullanıcı yalnızca KENDİ bildirimlerini görür/işaretler/siler —
     * başkasının uuid'si 404 (403 DEĞİL; varlık sızdırma engellenir).
     */
    private function authorizeOwnership(Request $request, DatabaseNotification $notification): void
    {
        $user = $request->user();

        abort_unless(
            $notification->notifiable_type === $user->getMorphClass()
                && (int) $notification->notifiable_id === (int) $user->getKey(),
            Response::HTTP_NOT_FOUND,
        );
    }
}
