<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\HeartbeatRequest;
use App\Http\Requests\Logs\StorePageVisitRequest;
use App\Models\PageVisitLog;
use App\Services\Logging\PageVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Sayfa gezinme takibi (heartbeat).
 *
 * ROADMAP R5: heartbeat yeni satır eklemez, mevcut ziyareti günceller — tüm
 * satır/süre mantığı PageVisitService'te (Controller -> Service -> Repository).
 */
class PageVisitController extends Controller
{
    public function __construct(private readonly PageVisitService $pageVisits) {}

    /**
     * POST /api/page-visits
     */
    public function store(StorePageVisitRequest $request): JsonResponse
    {
        // Session store her bağlamda request'e bağlı DEĞİLDİR (ör. Sanctum'un
        // stateful "frontend" tanımına girmeyen -saf token tabanlı ya da
        // StartSession middleware'inden hiç geçmemiş- bir istek). Koşulsuz
        // $request->session()->getId() çağrısı böyle bir durumda
        // "Session store not set on request" RuntimeException'ı fırlatıp
        // isteği 500'e düşürür. page_visit_logs.session_id zaten nullable
        // (Faz 3 migration) — sayfa ziyareti loglamak isteği düşürmeye değecek
        // bir iş olmadığından session yoksa null yazıyoruz.
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $visit = $this->pageVisits->start(
            $request->user(),
            $request->validated(),
            $request->ip(),
            $sessionId,
        );

        return response()->json(['data' => ['id' => $visit->id]], 201);
    }

    /**
     * PATCH /api/page-visits/{pageVisit}/heartbeat
     *
     * IDOR koruması HeartbeatRequest::authorize() içinde: kayıt istek
     * sahibine ait değilse FormRequest 403 döndürür, buraya hiç girilmez.
     * Gövde yok (204) — dakikada iki kez çağrılacağı için gereksiz trafik
     * üretmemek adına.
     */
    public function heartbeat(HeartbeatRequest $request, PageVisitLog $pageVisit): Response
    {
        $this->pageVisits->heartbeat($pageVisit);

        return response()->noContent();
    }
}
