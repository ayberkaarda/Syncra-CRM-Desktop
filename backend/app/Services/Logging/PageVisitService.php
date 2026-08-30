<?php

namespace App\Services\Logging;

use App\Models\PageVisitLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sayfa gezinme (heartbeat) iş mantığı.
 *
 * ROADMAP R5: heartbeat YENİ SATIR EKLEMEZ, mevcut ziyaret satırını GÜNCELLER.
 * 30 sn'lik heartbeat x 50 kullanıcı x 8 saat = günde ~48.000 potansiyel satır
 * demek — güncelleme yaklaşımıyla sayfa ziyareti başına 1 satır kalır.
 */
class PageVisitService
{
    /**
     * Tek bir heartbeat çağrısında eklenebilecek azami boşluk (saniye).
     *
     * Normal heartbeat aralığı ~30 sn'dir; 5 dk'yı aşan bir boşluk demek
     * sekme dondurulmuş, laptop uyumuş ya da istemci uzun süre hiç
     * heartbeat göndermemiş demektir. Böyle bir boşluğu süreye eklemek,
     * kullanıcı fiilen sayfada olmadığı halde "aktif kaldı" göstermek olur.
     * Bu durumda boşluk sessizce atlanır; toplam duration_seconds birikimli
     * (accumulate) olarak korunur, entered_at'ten yeniden hesaplanmaz.
     */
    private const STALE_GAP_THRESHOLD_SECONDS = 300;

    /**
     * Tek bir ziyaretin üst sınırı (saniye) — 8 saat.
     *
     * Bir sekme günlerce açık kalabilir; duration_seconds'ın makul bir
     * tavanı olmazsa tek bir satır anlamsız derecede büyük bir süre
     * biriktirebilir. Tavana ulaşıldığında değer orada sabitlenir, sonraki
     * heartbeat'ler last_heartbeat_at'i güncellemeye devam eder ama
     * duration_seconds artmaz.
     */
    private const MAX_DURATION_SECONDS = 28800;

    /**
     * Yeni bir sayfa ziyareti başlatır. Aynı kullanıcı + session için önceden
     * açık kalmış bir ziyaret varsa (bir önceki sayfa), o satır kapatılır:
     * son bilinen heartbeat anına kadarki süre eklenip satır olduğu gibi
     * bırakılır — ayrı bir "ziyareti kapat" isteği gerekmez, çünkü tarayıcı
     * kapanırsa böyle bir istek zaten hiç gelmeyecektir.
     *
     * @param  array{route: string, path: string, title?: string|null}  $data
     */
    public function start(User $user, array $data, ?string $ipAddress, ?string $sessionId): PageVisitLog
    {
        return DB::transaction(function () use ($user, $data, $ipAddress, $sessionId) {
            $now = Carbon::now();

            // $sessionId null olabilir (istek Sanctum'un stateful "frontend"
            // tanımına girmiyor / StartSession hiç çalışmadı — bkz.
            // PageVisitController::store()). Bu durumda BİLEREK "önceki
            // ziyareti kapat" adımını tamamen atlıyoruz; user_id'ye tek
            // başına geri düşmüyoruz. Gerekçe: session_id, aynı tarayıcı
            // sekmesindeki ardışık gezinmeleri birbirine bağlayan TEK
            // güvenilir bağdır. Yalnızca user_id ile eşleştirmek, aynı
            // kullanıcının eşzamanlı açık iki sekmesi/isteği (ör. biri
            // session'lı biri session'sız) varken birbirine hiç ilgisi
            // olmayan bir satırı yanlışlıkla "kapatıp" ona başkasının süresini
            // eklemek anlamına gelir. Session bilgisi yoksa yeni satır kendi
            // başına (session_id = null) açılır; hiçbir önceki satır
            // etkilenmez.
            if ($sessionId !== null) {
                $previous = PageVisitLog::query()
                    ->where('user_id', $user->id)
                    ->where('session_id', $sessionId)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($previous !== null) {
                    $this->accumulate($previous, $now);
                    $previous->save();
                }
            }

            return PageVisitLog::create([
                'user_id' => $user->id,
                'route' => $data['route'],
                'path' => $data['path'],
                'title' => $data['title'] ?? null,
                'entered_at' => $now,
                'last_heartbeat_at' => $now,
                'duration_seconds' => 0,
                'ip_address' => $ipAddress,
                'session_id' => $sessionId,
            ]);
        });
    }

    /**
     * Var olan ziyaret satırını günceller — YENİ SATIR EKLEMEZ.
     *
     * İstemciden herhangi bir süre değeri kabul edilmez; duration_seconds
     * tamamen burada, sunucu saatine göre hesaplanır.
     */
    public function heartbeat(PageVisitLog $pageVisit): PageVisitLog
    {
        DB::transaction(function () use ($pageVisit) {
            $locked = PageVisitLog::query()
                ->whereKey($pageVisit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->accumulate($locked, Carbon::now());
            $locked->save();

            $pageVisit->duration_seconds = $locked->duration_seconds;
            $pageVisit->last_heartbeat_at = $locked->last_heartbeat_at;
        });

        return $pageVisit;
    }

    /**
     * last_heartbeat_at (yoksa entered_at) ile $now arasındaki farkı
     * duration_seconds'a ekler — ama yalnızca bu fark "makul" ise (bayat
     * heartbeat eşiğinin altındaysa). Ardından tavanı uygular ve
     * last_heartbeat_at'i her durumda $now'a taşır.
     */
    private function accumulate(PageVisitLog $pageVisit, Carbon $now): void
    {
        $reference = $pageVisit->last_heartbeat_at ?? $pageVisit->entered_at;
        $gap = $reference->diffInSeconds($now, absolute: false);

        // gap > 0: normal ilerleme. gap <= 0: saat kayması/eşzamanlı istek,
        // eklenecek bir şey yok. gap > eşik: bayat heartbeat, boşluk atlanır.
        if ($gap > 0 && $gap <= self::STALE_GAP_THRESHOLD_SECONDS) {
            $pageVisit->duration_seconds = min(
                $pageVisit->duration_seconds + $gap,
                self::MAX_DURATION_SECONDS,
            );
        } else {
            // Tavanı burada da uygula: pre-existing bir değer zaten tavanı
            // aşmış olamaz ama savunmacı olarak koru.
            $pageVisit->duration_seconds = min($pageVisit->duration_seconds, self::MAX_DURATION_SECONDS);
        }

        $pageVisit->last_heartbeat_at = $now;
    }
}
