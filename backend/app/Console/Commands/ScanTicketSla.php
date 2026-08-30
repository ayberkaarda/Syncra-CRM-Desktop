<?php

namespace App\Console\Commands;

use App\Events\TicketSlaBreached;
use App\Events\TicketSlaWarning;
use App\Models\Ticket;
use App\Services\Tickets\SlaService;
use Illuminate\Console\Command;

/**
 * =============================================================================
 * `tickets:scan-sla` — docs/SLA-DESIGN.md §5.5 tarayıcısı
 * =============================================================================
 *
 * `routes/console.php`'de HER 5 DAKİKADA bir zamanlanır. İki eşik tarar:
 *
 *   UYARI  : kalan süre hedefin %20'sinin altına indi ve henüz ihlal YOK
 *            -> TicketSlaWarning, `sla_warning_notified_at` damgalanır.
 *   İHLAL  : `sla_due_at` geçildi -> TicketSlaBreached,
 *            `sla_breach_notified_at` damgalanır.
 *
 * KAPSAM (§5.5): `resolved_at IS NULL AND sla_paused_at IS NULL`. Duraklamadaki
 * (`pending`) bir ticket'a HİÇ bildirim üretilmez — sayacı donmuştur; müşteri
 * yanıtı beklenirken temsilciye "SLA yanıyor" demek, duraklama mantığının
 * tamamını anlamsızlaştırırdı.
 *
 * -----------------------------------------------------------------------------
 * IDEMPOTENCY: NEDEN CACHE DEĞİL, KOLON
 * -----------------------------------------------------------------------------
 * Komut 5 dakikada bir koşar ve ihlal koşulu ticket çözülene kadar SONSUZA
 * KADAR doğru kalır — damgasız bir tarayıcı aynı bildirimi günlerce tekrar
 * üretirdi. A şeridinin `tasks:dispatch-reminders` komutu bunu TTL'li bir cache
 * anahtarıyla çözer ve dokümanında bunun bir ödün olduğunu (cache temizlenirse
 * tekrar gönderim) açıkça yazar; burada kolon kullanılabiliyor çünkü Faz 8'in
 * SLA migration'ı ZATEN yazılıyordu (docs/SLA-DESIGN.md §3 bu iki damgayı
 * bilerek şemaya koydu). Kolon kalıcıdır: `cache:clear`, redis yeniden
 * başlatma ve dağıtım hiçbir şeyi tekrarlatmaz.
 *
 * Damgalar §5.2 gereği ÖNCELİK DEĞİŞİMİNDE null'a çekilir: hedef yeniden
 * hesaplandığında bildirimler de yeni hedefe göre bir kez daha kurulur.
 *
 * -----------------------------------------------------------------------------
 * `--dry-run`
 * -----------------------------------------------------------------------------
 * Hiçbir event dispatch ETMEZ ve hiçbir damga YAZMAZ — yalnızca ne olacağını
 * yazdırır. Kalıcı iz bırakmadığı için bir sonraki gerçek çalıştırma aynı
 * adayları yeniden değerlendirir (`tasks:dispatch-reminders --dry-run` ile
 * aynı sözleşme).
 *
 * -----------------------------------------------------------------------------
 * SORGU MALİYETİ
 * -----------------------------------------------------------------------------
 * İki sorgu (uyarı + ihlal), ikisi de mevcut `sla_due_at` index'ini kullanır;
 * yeni index gerekmedi (§3). Damga yazımı ticket başına tek UPDATE'tir ve
 * yalnızca DAHA ÖNCE damgalanmamış ticket'lar için çalışır — yani her ticket
 * için ömrü boyunca en fazla iki UPDATE.
 */
class ScanTicketSla extends Command
{
    /**
     * @var string
     */
    protected $signature = 'tickets:scan-sla
        {--dry-run : Hiçbir event dispatch etmez ve damga yazmaz, yalnızca ne olacağını yazdırır}';

    /**
     * @var string
     */
    protected $description = 'SLA uyarı (%20 eşiği) ve ihlal olaylarını tarar; ticket başına bir kez event dispatch eder.';

    public function __construct(protected SlaService $sla)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $warned = $this->scanWarnings($isDryRun);
        $breached = $this->scanBreaches($isDryRun);

        $prefix = $isDryRun ? '[dry-run] ' : '';

        $this->components->info(
            "{$prefix}{$warned} SLA uyarısı, {$breached} SLA ihlali işlendi."
        );

        return self::SUCCESS;
    }

    /**
     * §5.5 uyarı eşiği. Predicate SlaService::scopeAtRisk() içindedir ve
     * `GET /api/tickets/stats` -> `at_risk_count` ile AYNIDIR — iki yerde iki
     * farklı eşik yazılsaydı ekrandaki sayı ile gelen bildirim birbirini
     * tutmazdı.
     */
    protected function scanWarnings(bool $isDryRun): int
    {
        $tickets = $this->sla
            ->scopeAtRisk(Ticket::query())
            ->whereNull('sla_warning_notified_at')
            ->get();

        foreach ($tickets as $ticket) {
            $remaining = $this->sla->remainingSeconds($ticket) ?? 0;

            if ($isDryRun) {
                $this->line("[dry-run] UYARI: {$ticket->ticket_number} — kalan {$remaining} sn (hedef {$this->sla->targetHours($ticket->priority)} sa).");

                continue;
            }

            event(new TicketSlaWarning(TicketSlaWarning::payload($ticket, $remaining)));

            // Damga yalnızca GERÇEK dispatch'ten SONRA yazılır.
            $ticket->sla_warning_notified_at = now();
            $ticket->save();
        }

        return $tickets->count();
    }

    /**
     * §5.5 ihlal eşiği. `sla_breach_notified_at` damgası olmayan, sayacı akan
     * ve `sla_due_at`'i geçmiş tüm açık ticket'lar.
     */
    protected function scanBreaches(bool $isDryRun): int
    {
        $tickets = $this->sla
            ->scopeScannableBreached(Ticket::query())
            ->whereNull('sla_breach_notified_at')
            ->get();

        foreach ($tickets as $ticket) {
            // `remainingSeconds()` ihlalde negatiftir; olayda "ne kadar
            // aşıldı" pozitif bir sayı olarak taşınır.
            $overdue = abs($this->sla->remainingSeconds($ticket) ?? 0);

            if ($isDryRun) {
                $this->line("[dry-run] İHLAL: {$ticket->ticket_number} — {$overdue} sn aşıldı.");

                continue;
            }

            event(new TicketSlaBreached(TicketSlaBreached::payload($ticket, $overdue)));

            $ticket->sla_breach_notified_at = now();
            $ticket->save();
        }

        return $tickets->count();
    }
}
