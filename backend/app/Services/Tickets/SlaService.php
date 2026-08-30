<?php

namespace App\Services\Tickets;

use App\Models\Setting;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * =============================================================================
 * SLA sayacı — docs/SLA-DESIGN.md §5'in tek uygulaması
 * =============================================================================
 *
 * SİSTEM INVARIANT'I (her an doğru olmalı, §5):
 *
 *     sla_due_at = created_at + hedef_saat(priority) + sla_paused_seconds
 *
 * (aktif duraklama henüz `sla_paused_seconds`'a EKLENMEMİŞTİR — duraklamadan
 * çıkışta eklenir ve `sla_due_at` aynı anda o kadar ileri kaydırılır, böylece
 * invariant tekrar sağlanır.)
 *
 * -----------------------------------------------------------------------------
 * İHLAL NEDEN KALICI BİR BAYRAK DEĞİL (§5.3)
 * -----------------------------------------------------------------------------
 * `sla_breached` adında bir kolon YOKTUR ve olmamalıdır. İhlal, üç alandan
 * (`resolved_at`, `sla_paused_at`, `sla_due_at`) her an TÜRETİLEBİLEN bir
 * değerdir. Bayrak tutulsaydı; duraklama, öncelik değişimi ve yeniden açma
 * senaryolarının HER BİRİNDE senkron tutulması gereken ikinci bir doğruluk
 * kaynağı doğar ve tarayıcı (`tickets:scan-sla`) iki tur arasında gecikirse
 * bayrak yanlış negatif üretirdi. Türetilmiş değer ise gecikemez.
 *
 * -----------------------------------------------------------------------------
 * HAM SQL YOK
 * -----------------------------------------------------------------------------
 * Bu projede `app/` altında SIFIR ham SQL garantisi vardır (Faz 7 sadeleştirme
 * turu, bkz. DealRepository::amountTotals() dokümanı). İhlal ve "risk altında"
 * predicate'leri kolon-kolon karşılaştırma (`whereColumn`) ve parantezli
 * `where` grupları ile yazılmıştır; `selectRaw`/`whereRaw` KULLANILMAZ.
 *
 * "Risk altında" (uyarı eşiği) koşulu ilk bakışta ham SQL gerektirir gibi
 * durur, çünkü `sla_due_at - now() < 0.2 * (sla_due_at - created_at -
 * sla_paused_seconds)` iki kolon üzerinde aritmetik ister. Çözüm §5
 * invariant'ından gelir: sağ taraf ZATEN `hedef_saat(priority) * 3600`'e
 * eşittir; öncelik başına sabit bir eşik demektir. Bu yüzden sorgu, dört
 * önceliğin her biri için `sla_due_at <= now() + 0.2 * hedef` biçiminde bir
 * OR grubuna açılır — saf Eloquent, kolon aritmetiği yok.
 *
 * -----------------------------------------------------------------------------
 * MUTASYON METOTLARI KAYDETMEZ
 * -----------------------------------------------------------------------------
 * `pause()`, `resume()`, `reopen()`, `recalculateForPriority()`,
 * `recordFirstResponse()` yalnızca modelin ÖZNİTELİKLERİNİ değiştirir, `save()`
 * ÇAĞIRMAZ. Kaydetme sorumluluğu çağırana (TicketStatusMachine /
 * TicketService) aittir; böylece durum geçişi + SLA yan etkileri TEK bir
 * `save()` ile, TEK bir transaction ve `lockForUpdate` altında yazılır.
 *
 * -----------------------------------------------------------------------------
 * ÇALIŞMA SAATLERİ KAPSAM DIŞI (§7)
 * -----------------------------------------------------------------------------
 * SLA TAKVİM saatiyle işler. Cuma 17:00'de açılan bir `urgent` ticket'ın
 * Pazartesi sabahı ihlalde görünmesi BİLİNEN ve kabul edilmiş bir sınırdır.
 * İş-saati hesabı eklenmek istenirse tek dokunuş noktası bu sınıftaki süre
 * aritmetiğidir.
 */
class SlaService
{
    /**
     * Uyarı eşiği: kalan süre hedefin bu oranının ALTINA indiğinde
     * "risk altında" sayılır (docs/SLA-DESIGN.md §5.5).
     */
    public const WARNING_THRESHOLD_RATIO = 0.2;

    /**
     * @var array<int, string>
     */
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * `settings` tablosu okunamazsa (ör. ayar satırı silinmiş) kullanılan
     * son çare değerler — SettingSeeder'daki `ticket.sla_hours_*` ile aynı.
     * Ayarın yokluğunda sayacı KAPATMAK yerine makul bir hedefle devam etmek
     * tercih edildi: SLA'sız bir ticket sessizce hiç ihlal etmez ve sorun
     * fark edilmez.
     *
     * @var array<string, int>
     */
    public const FALLBACK_HOURS = [
        'low' => 72,
        'normal' => 48,
        'high' => 24,
        'urgent' => 4,
    ];

    /**
     * Önceliğe karşılık gelen hedef saat — `settings` tablosundan OKUNUR.
     *
     * §5.1: değer OLUŞTURMA anında okunup `sla_due_at`'e SABİTLENİR. Ayar
     * sonradan değişirse mevcut ticket'ların `sla_due_at`'i DEĞİŞMEZ (kabul
     * kriteri 2); yalnızca yeni ticket'lar ve öncelik değişimiyle yeniden
     * hesaplanan ticket'lar yeni değeri alır.
     */
    /**
     * ÖNBELLEK YOK — BİLİNÇLİ.
     *
     * Ayarı hafızada tutmak cazip görünür ("liste yanıtında satır başına bir
     * `settings` sorgusu olmasın"), ama iki nedenle YAPILMADI:
     *
     *   1. GEREKMİYOR. Satır başına çağrılan tek yol TicketResource'tu; o da
     *      artık hedefi satırın KENDİ alanlarından türetiyor (bkz.
     *      totalSeconds()), yani liste yanıtı bu metodu hiç çağırmıyor.
     *      Geriye kalan çağrı noktaları istek başına sabit sayıdadır:
     *      oluşturma, öncelik değişimi ve `scopeAtRisk()`'in dört eşiği.
     *   2. YANLIŞ SONUÇ ÜRETİRDİ. Laravel, `Route` nesnesi üzerinde controller
     *      örneğini HAFIZAYA ALIR (`Route::getController()`), route'lar da
     *      uygulama açılışında bir kez kaydedilir. Bu yüzden aynı uygulama
     *      örneği içinde arka arkaya gelen istekler AYNI controller — ve
     *      dolayısıyla aynı TicketService/SlaService — örneğini kullanır.
     *      Örnek düzeyinde bir hafıza bile, "ayar değişti, YENİ ticket yeni
     *      hedefi alır" davranışını (kabul kriteri 2) bozar; bu, önce yazılıp
     *      testte yakalanmış gerçek bir hatadır.
     */
    public function targetHours(?string $priority): int
    {
        $priority = in_array($priority, self::PRIORITIES, true) ? $priority : 'normal';

        $value = Setting::get("ticket.sla_hours_{$priority}");

        if ($value === null || (int) $value <= 0) {
            return self::FALLBACK_HOURS[$priority];
        }

        return (int) $value;
    }

    public function targetSeconds(?string $priority): int
    {
        return $this->targetHours($priority) * 3600;
    }

    /**
     * Oluşturmada hedef an — §5.1: `created_at + hedef_saat(priority)`.
     * Yeni ticket'ta `sla_paused_seconds` sıfırdır, bu yüzden invariant'ın
     * üçüncü terimi burada yoktur.
     */
    public function initialDueAt(CarbonInterface $createdAt, ?string $priority): CarbonInterface
    {
        return $createdAt->copy()->addHours($this->targetHours($priority));
    }

    /**
     * §5.2 — ÖNCELİK DEĞİŞİMİNDE YENİDEN HESAP.
     *
     *     sla_due_at = created_at + hedef_saat(yeni_priority) + sla_paused_seconds
     *
     * `normal -> urgent` yükseltmesinde 48 saatlik hedef 4 saate iner ve
     * ticket ANINDA ihlale düşebilir — BU İSTENEN DAVRANIŞTIR (§5.2):
     * aciliyet gerçeği sonradan anlaşıldıysa taahhüt baştan beri 4 saatti.
     * Hedefi korumak "acile çekip rahat rahat 48 saat kullanma" kaçağı açardı.
     *
     * Aktif duraklama (`sla_paused_at`) SÜRERKEN öncelik değişirse formül
     * aynen uygulanır; o duraklama çıkışta yine `sla_due_at`'e eklenir —
     * dolayısıyla iki kez sayılmaz.
     *
     * Her iki bildirim damgası da null'a çekilir: yeni hedefe göre uyarı ve
     * ihlal bildirimleri yeniden kurulmalıdır (kabul kriteri 6).
     */
    public function recalculateForPriority(Ticket $ticket): void
    {
        if ($ticket->created_at === null) {
            return;
        }

        $ticket->sla_due_at = $ticket->created_at->copy()
            ->addHours($this->targetHours($ticket->priority))
            ->addSeconds((int) $ticket->sla_paused_seconds);

        $ticket->sla_warning_notified_at = null;
        $ticket->sla_breach_notified_at = null;
    }

    /**
     * §4 — Durum `pending`'e geçti: sayaç DURUR.
     *
     * `sla_due_at` burada DEĞİŞMEZ; donmuş kalan süre `sla_due_at -
     * sla_paused_at` olarak okunur (bkz. remainingSeconds()).
     */
    public function pause(Ticket $ticket): void
    {
        if ($ticket->sla_paused_at !== null) {
            return; // zaten duraklamada — idempotent
        }

        $ticket->sla_paused_at = now();
    }

    /**
     * §4 — `pending`'den çıkış: sayaç DEVAM EDER.
     *
     *     d = now() - sla_paused_at
     *     sla_paused_seconds += d ; sla_due_at += d ; sla_paused_at = null
     *
     * `sla_due_at`'in de kaydırılması ŞARTTIR: yalnız `sla_paused_seconds`
     * artırılsaydı duraklamada geçen süre hedeften çalınmış olurdu ve
     * temsilci, müşterinin gecikmesi yüzünden ihlale düşerdi — SLA'nın varlık
     * sebebi ortadan kalkardı (§3).
     */
    public function resume(Ticket $ticket): void
    {
        if ($ticket->sla_paused_at === null) {
            return; // duraklamada değil — idempotent
        }

        $elapsed = max(0, now()->getTimestamp() - $ticket->sla_paused_at->getTimestamp());

        $ticket->sla_paused_seconds = (int) $ticket->sla_paused_seconds + $elapsed;

        if ($ticket->sla_due_at !== null) {
            $ticket->sla_due_at = $ticket->sla_due_at->copy()->addSeconds($elapsed);
        }

        $ticket->sla_paused_at = null;
    }

    /**
     * §4 — `resolved -> open` (yeniden açma).
     *
     *     g = now() - resolved_at
     *     sla_paused_seconds += g ; sla_due_at += g ; resolved_at = null
     *
     * Çözümde geçen süre DURAKLAMA gibi işlenir: yeniden açılan bir ticket
     * sırf rafta beklediği için anında ihlale düşmemelidir (kabul kriteri 8) —
     * kalan süresi, çözüm anındaki kalanla aynı olur.
     */
    public function reopen(Ticket $ticket): void
    {
        if ($ticket->resolved_at === null) {
            return;
        }

        $gap = max(0, now()->getTimestamp() - $ticket->resolved_at->getTimestamp());

        $ticket->sla_paused_seconds = (int) $ticket->sla_paused_seconds + $gap;

        if ($ticket->sla_due_at !== null) {
            $ticket->sla_due_at = $ticket->sla_due_at->copy()->addSeconds($gap);
        }

        $ticket->resolved_at = null;
    }

    /**
     * §2 — `first_response_at` BİR KEZ yazılır ve BİR DAHA ASLA değişmez.
     *
     * Yalnızca metriktir, SLA hedefi DEĞİLDİR (Faz 11 "ilk yanıt süresi"
     * raporu bunu `first_response_at - created_at` ile üretir).
     *
     * Tetikleyicileri (hangisi önce olursa):
     *   (a) ilk `-> in_progress` geçişi — TicketStatusMachine burayı çağırır;
     *   (b) ticket'a ilk `call | email | meeting` tipli Activity eklenmesi —
     *       `note` SAYILMAZ, çünkü kapalı devre sistemde her not iç nottur.
     *
     * (b) tetikleyicisi bu turda BAĞLANMAMIŞTIR: `POST /api/activities`
     * ucunun sahibi A şerididir (App\Services\Activities\ActivityService) ve
     * o dosya bu şeridin sahipliği DIŞINDADIR. Metot bilinçli olarak public ve
     * yan etkisizdir; ActivityService::create() içinden şu tek satırla
     * bağlanır (hedef ticket ve tip kontrolünden sonra):
     *
     *     app(SlaService::class)->recordFirstResponse($ticket); $ticket->save();
     */
    public function recordFirstResponse(Ticket $ticket): void
    {
        if ($ticket->first_response_at !== null) {
            return;
        }

        $ticket->first_response_at = now();
    }

    // -----------------------------------------------------------------
    // TÜRETİLMİŞ DEĞERLER — yanıt üretilirken hesaplanır
    // -----------------------------------------------------------------

    /**
     * §6 — `sla_paused`. `sla_paused_at !== null` ile `status === 'pending'`
     * uygulama katmanında eşdeğerdir; burada KOLON okunur, çünkü sayacın
     * gerçeği kolondadır (durum, geçiş sırasında bir an için ileri gitmiş
     * olabilir).
     */
    public function isPaused(Ticket $ticket): bool
    {
        return $ticket->sla_paused_at !== null;
    }

    /**
     * §6 — `sla_remaining_seconds`. SUNUCUDA hesaplanır; istemci `Date.now()`
     * ile `sla_due_at`'i ASLA karşılaştırmaz (kullanıcının saati yanlışsa
     * ihlal yanlış görünürdü — §6 istemci geri sayım kuralı).
     *
     *   - akarken:      sla_due_at - now()
     *   - duraklamada:  sla_due_at - sla_paused_at   (DONMUŞ değer)
     *   - çözülmüş/kapanmış: null (sayaç bitmiştir)
     *
     * Negatif değer "aşılmış" demektir ve bilinçli olarak kırpılmaz: arayüz
     * "3 saat 12 dakika gecikme" yazabilsin.
     *
     * Aritmetik Carbon'un `diffInSeconds()`'ı yerine ham UNIX damgaları ile
     * yapılır: `diffInSeconds()` Carbon 2/3 arasında hem işaret hem de dönüş
     * tipi (int/float) davranışını değiştirdi; damga farkı her sürümde aynıdır.
     */
    public function remainingSeconds(Ticket $ticket): ?int
    {
        if ($ticket->sla_due_at === null || $this->isFinished($ticket)) {
            return null;
        }

        $reference = $ticket->sla_paused_at ?? now();

        return $ticket->sla_due_at->getTimestamp() - $reference->getTimestamp();
    }

    /**
     * §6 — `sla_total_seconds`: ilerleme çubuğunun paydası.
     *
     * -------------------------------------------------------------------
     * TICKET'IN KENDİ TAAHHÜDÜNDEN TÜRETİLİR, AYARDAN OKUNMAZ
     * -------------------------------------------------------------------
     *     sla_total_seconds = sla_due_at - created_at - sla_paused_seconds
     *
     * Bu, §5'in sistem invariant'ının yeniden düzenlenmiş hâlidir ve §5.5'in
     * uyarı eşiğinde "hedef" için ZATEN kullandığı formülün aynısıdır; ayarlar
     * hiç değişmediği sürece "hedef_saat(priority) * 3600" ile BİREBİR aynı
     * sayıyı verir.
     *
     * §6 bu alanı bir dönem ayardan okunan biçimiyle tarif ediyordu; tanım
     * aşağıdaki iki gerekçeyle düzeltildi ve doküman güncellendi (bkz.
     * docs/SLA-DESIGN.md §6, tablo altındaki not):
     *   1. DOĞRULUK. Kabul kriteri 2, ayar değişince mevcut ticket'ların
     *      hedefinin DEĞİŞMEMESİ gerektiğini söyler. Ayardan okunan biçim,
     *      `sla_due_at` sabit kalırken paydayı oynatır ve ilerleme çubuğu
     *      ticket'ın gerçek taahhüdüyle çelişir.
     *   2. N+1. Ayardan okuma, liste yanıtında TICKET BAŞINA bir `settings`
     *      sorgusu demektir (TicketResource her satırda çağırır). Türetilmiş
     *      biçim SIFIR sorgu kullanır — değerler zaten satırın içindedir.
     *
     * `sla_due_at` yoksa (yalnızca API dışında üretilmiş kayıtlarda mümkün)
     * ayara düşülür; bu yol liste sorgularında pratikte hiç çalışmaz.
     */
    public function totalSeconds(Ticket $ticket): int
    {
        if ($ticket->sla_due_at !== null && $ticket->created_at !== null) {
            $derived = $ticket->sla_due_at->getTimestamp()
                - $ticket->created_at->getTimestamp()
                - (int) $ticket->sla_paused_seconds;

            if ($derived > 0) {
                return $derived;
            }
        }

        return $this->targetSeconds($ticket->priority);
    }

    /**
     * `sla_target_hours` — totalSeconds()'un saat cinsinden hâli (aynı
     * türetilmiş kaynaktan, ek sorgu yok).
     */
    public function targetHoursForTicket(Ticket $ticket): float
    {
        return round($this->totalSeconds($ticket) / 3600, 2);
    }

    /**
     * §5.3 — İHLAL (türetilmiş).
     *
     *   - AKTİF ihlal (açık ticket):
     *       akarken:     sla_due_at < now()
     *       duraklamada: sla_due_at < sla_paused_at
     *   - TARİHSEL ihlal (çözülmüş/kapanmış): resolved_at > sla_due_at
     *
     * Duraklama kuralının inceliği: duraklamaya POZİTİF kalanla girildiyse
     * duvar saati `sla_due_at`'i geçse bile ihlal SAYILMAZ (çıkışta
     * `sla_due_at` duraklama kadar kayacaktır); duraklamaya ZATEN İHLALDEYKEN
     * girildiyse ihlal duraklamayla "iyileşmez" (kabul kriteri 5).
     */
    public function isBreached(Ticket $ticket): bool
    {
        if ($ticket->sla_due_at === null) {
            return false;
        }

        if ($ticket->resolved_at !== null) {
            return $ticket->resolved_at->getTimestamp() > $ticket->sla_due_at->getTimestamp();
        }

        $reference = $ticket->sla_paused_at ?? now();

        return $ticket->sla_due_at->getTimestamp() < $reference->getTimestamp();
    }

    // -----------------------------------------------------------------
    // SORGU KAPSAMLARI (Builder) — ham SQL YOK
    // -----------------------------------------------------------------

    /**
     * §5.4 — `filter[sla_breached]=1`: AKTİF ihlal koşulu.
     *
     * `resolved_at IS NULL` olduğu için çözülmüş VE kapanmış ticket'lar
     * kapsam dışıdır (kapanan bir ticket her zaman önce çözülür, bkz. §4
     * geçiş tablosu) — bu filtre "şu an ihlalde olan iş" listesidir, tarihsel
     * ihlal raporu değildir.
     *
     * `where('sla_due_at','<',...)` NULL `sla_due_at`'i SQL semantiği gereği
     * zaten eler (NULL karşılaştırması UNKNOWN'dır) — ayrıca `whereNotNull`
     * yazmak gereksizdir.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeActivelyBreached(Builder $query): Builder
    {
        $now = now();

        return $query->whereNull('resolved_at')->where(function (Builder $query) use ($now) {
            // Sayaç akıyor: duvar saatine göre ihlal.
            $query->where(function (Builder $query) use ($now) {
                $query->whereNull('sla_paused_at')->where('sla_due_at', '<', $now);
            })
                // Duraklamada: DONMUŞ kalan negatifse ihlal (duraklamaya
                // zaten ihlaldeyken girilmiş). Kolon-kolon karşılaştırma,
                // ham SQL değil.
                ->orWhere(function (Builder $query) {
                    $query->whereNotNull('sla_paused_at')
                        ->whereColumn('sla_due_at', '<', 'sla_paused_at');
                });
        });
    }

    /**
     * §5.5 uyarı eşiği ile AYNI predicate: henüz ihlal etmemiş ama kalan
     * süresi hedefin %20'sinin altına inmiş, sayacı AKAN açık ticket'lar.
     *
     * `stats.at_risk_count` ve `tickets:scan-sla`'nın uyarı taraması bu TEK
     * metodu paylaşır — iki yerde iki farklı eşik yazılırsa arayüzdeki sayı
     * ile gelen bildirim birbirini tutmaz.
     *
     * İHLAL ETMİŞ ticket'lar kapsam DIŞIDIR (`sla_due_at > now()`): onlar
     * "risk altında" değil, artık ihlaldedir ve kendi olaylarını
     * (`TicketSlaBreached`) alırlar.
     *
     * Ham SQL'siz nasıl: eşik, öncelik başına SABİT bir süredir (bkz. sınıf
     * dokümanı), bu yüzden dört öncelik için birer `orWhere` grubu yeterlidir.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeAtRisk(Builder $query): Builder
    {
        $now = now();

        return $query->whereNull('resolved_at')
            ->whereNull('sla_paused_at')
            ->where('sla_due_at', '>', $now)
            ->where(function (Builder $query) use ($now) {
                foreach (self::PRIORITIES as $priority) {
                    $threshold = (int) round($this->targetSeconds($priority) * self::WARNING_THRESHOLD_RATIO);

                    $query->orWhere(function (Builder $query) use ($priority, $now, $threshold) {
                        $query->where('priority', $priority)
                            ->where('sla_due_at', '<=', $now->copy()->addSeconds($threshold));
                    });
                }
            });
    }

    /**
     * `tickets:scan-sla` ihlal taraması — §5.5 kapsamı: duraklamadaki
     * ticket'a bildirim ÜRETİLMEZ (donmuş sayaç ihlal üretmemelidir).
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeScannableBreached(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')
            ->whereNull('sla_paused_at')
            ->where('sla_due_at', '<', now());
    }

    /**
     * Sayaç bitti mi? `resolved_at` doluysa SLA ölçümü bitmiştir; `closed`
     * durumu da her zaman bir `resolved`'dan geçer (§4).
     */
    protected function isFinished(Ticket $ticket): bool
    {
        return $ticket->resolved_at !== null || in_array($ticket->status, ['resolved', 'closed'], true);
    }
}
