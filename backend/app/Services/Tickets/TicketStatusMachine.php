<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * =============================================================================
 * Ticket durum makinesi — docs/SLA-DESIGN.md §4'ün tek uygulaması
 * =============================================================================
 *
 * Geçiş tablosu TEK YERDE yaşar: aşağıdaki `TRANSITIONS` sabiti. SLA yan
 * etkileri `SlaService`'e delegedir; bu sınıf "hangi geçiş yasal" ve "geçişte
 * hangi damga yazılır" sorularının dışına çıkmaz.
 *
 * -----------------------------------------------------------------------------
 * DURUM NEDEN YALNIZCA ÖZEL UÇTAN DEĞİŞİR
 * -----------------------------------------------------------------------------
 * `PATCH /api/tickets/{ticket}` gövdesinde `status` gönderilirse
 * UpdateTicketRequest `missing` kuralıyla 422 üretir; durum YALNIZCA
 * `PATCH /api/tickets/{ticket}/status` ucundan değişir. Faz 7'deki
 * `PATCH /api/deals/{deal}/move` ile aynı gerekçe: genel update ucu ham
 * kolon yazımıdır, oradan `status` geçirilseydi bu sınıfın TÜM SLA yan
 * etkileri (duraklama başlat/bitir, `resolved_at`, yeniden açmada kaydırma)
 * SESSİZCE baypas edilir ve sayaç kalıcı olarak yanlış kalırdı.
 *
 * -----------------------------------------------------------------------------
 * TRANSACTION + lockForUpdate
 * -----------------------------------------------------------------------------
 * Geçiş kararı, okunan `status`'e dayanır: aynı ticket'a aynı anda gelen iki
 * durum isteği (ör. iki temsilci "çözüldü" ve "beklemede" derse) kilit
 * olmadan İKİSİ DE geçerli görünür ve ikincisi birincinin SLA yan etkilerini
 * bozar. `lockForUpdate()` satırı transaction boyunca tutar; ikinci istek
 * GÜNCEL durumdan doğrulanır ve bayatsa 422 alır. Doğrulama + SLA
 * hesaplaması + `save()` aynı transaction içindedir.
 *
 * -----------------------------------------------------------------------------
 * NEDEN AYRI BİR EXCEPTION SINIFI DEĞİL
 * -----------------------------------------------------------------------------
 * Geçersiz geçiş, `HttpResponseException` fırlatılarak raporlanır — merkezî
 * hata zarfının (`bootstrap/app.php` -> withExceptions) İLK kuralı zaten
 * "`HttpResponseException` kendi yanıtını taşır, dokunma"dır. Böylece Faz 7'de
 * `DealVersionConflictException` için kurulan desen, o dosyaya HİÇ dokunmadan
 * tekrar kullanılır; bu sınıfın dışında ayrı bir exception dosyasına ihtiyaç
 * yoktur çünkü hata yalnızca burada üretilir ve başka hiçbir yerde
 * yakalanmaz.
 */
class TicketStatusMachine
{
    /**
     * §4 geçiş tablosu. Anahtar = KAYNAK durum, değer = izin verilen HEDEF
     * durumlar. Listelenmeyen her hedef geçersizdir; AYNI duruma geçiş de
     * geçersizdir (hiçbir listede kendi anahtarı yoktur — no-op bir geçiş
     * `resolved_at`/`sla_paused_at` damgalarını tazeleyip sayacı bozardı).
     *
     * `closed` TERMİNALDİR (boş liste): yeniden açılamaz, aynı konu tekrar
     * ederse yeni ticket açılır. Gerekçe (§4): kapanmış dönem raporları
     * (Faz 11) geriye dönük değişmez kalmalıdır.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'open' => ['in_progress', 'pending', 'resolved'],
        'in_progress' => ['open', 'pending', 'resolved'],
        'pending' => ['open', 'in_progress', 'resolved'],
        'resolved' => ['open', 'closed'],
        'closed' => [],
    ];

    /**
     * Tüm geçerli durum adları — Form Request'lerin `Rule::in()` kaynağı.
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public function __construct(protected SlaService $sla) {}

    /**
     * `PATCH /api/tickets/{ticket}/status`.
     *
     * @throws HttpResponseException 422 INVALID_STATUS_TRANSITION
     */
    public function transition(Ticket $ticket, string $to): Ticket
    {
        return DB::transaction(function () use ($ticket, $to) {
            /** @var Ticket|null $locked */
            $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                // Kilit alınırken satır silinmiş — route model binding'in
                // 404'üyle aynı sonuç, ama transaction içinde.
                abort(Response::HTTP_NOT_FOUND);
            }

            $from = (string) $locked->status;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                $this->denyTransition($from, $to);
            }

            $this->applySideEffects($locked, $from, $to);

            $locked->status = $to;
            $locked->save();

            return $locked;
        });
    }

    /**
     * §4 tablosundaki yan etkiler. Sıra ÖNEMLİDİR:
     *
     *   1. `pending`'den ÇIKIŞ önce işlenir (duraklama kapanır, `sla_due_at`
     *      kaydırılır) — `pending -> resolved` geçişinde `resolved_at` ancak
     *      duraklama kapandıktan SONRA yazılmalıdır, aksi halde duraklamada
     *      geçen süre çözüm süresine dahil edilmiş olur.
     *   2. `resolved -> open` yeniden açması `resolved_at`'i null'a çekmeden
     *      ÖNCE aradaki raf süresini okumak zorundadır (SlaService::reopen()
     *      ikisini birlikte yapar).
     */
    protected function applySideEffects(Ticket $ticket, string $from, string $to): void
    {
        if ($from === 'pending') {
            $this->sla->resume($ticket);
        }

        if ($from === 'resolved' && $to === 'open') {
            $this->sla->reopen($ticket);
        }

        if ($to === 'pending') {
            $this->sla->pause($ticket);
        }

        if ($to === 'in_progress') {
            // §2: ilk kez yazılır, bir daha ASLA değişmez (kabul kriteri 11).
            $this->sla->recordFirstResponse($ticket);
        }

        if ($to === 'resolved') {
            $ticket->resolved_at = now();
        }

        if ($to === 'closed') {
            $ticket->closed_at = now();
        }
    }

    /**
     * ROADMAP standardındaki hata zarfı (§4):
     *
     *   { "errors": { "message": "...", "code": "INVALID_STATUS_TRANSITION",
     *                 "fields": { "status": ["..."] } } }
     *
     * FAZ 14 / İz D: sabit Türkçe cümleler `lang/{tr,en,de,fr}/errors.php`'ye taşındı
     * (`errors.ticket_status.*` + QuoteStatusMachine ile paylaşılan
     * `errors.status_transition.invalid`) — dosya sahipliği ayrı olduğu için mesaj tek
     * bir dosyaya değil, iki durum makinesinin de kullandığı ortak anahtara + kendi
     * domain anahtarlarına bölündü.
     *
     * @throws HttpResponseException
     */
    protected function denyTransition(string $from, string $to): never
    {
        $allowed = self::TRANSITIONS[$from] ?? [];

        $detail = $allowed === []
            ? __('errors.ticket_status.terminal', ['from' => $from])
            : __('errors.ticket_status.allowed_transitions', [
                'from' => $from,
                'allowed' => implode(', ', $allowed),
            ]);

        throw new HttpResponseException(response()->json([
            'errors' => [
                'message' => __('errors.status_transition.invalid', ['from' => $from, 'to' => $to]),
                'code' => 'INVALID_STATUS_TRANSITION',
                'fields' => [
                    'status' => [$detail],
                ],
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
