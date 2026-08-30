<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8 / B — SLA duraklama + bildirim damgaları (docs/SLA-DESIGN.md §3).
 *
 * ADDITIVE bir migration'dır: `2026_08_23_100008_create_tickets_table.php`
 * dosyasına DOKUNULMAZ. Gerekçe, demo verinin (30 ticket, 8'i bilinçli
 * ihlalli) yüklü olması ve `migrate:fresh` çalıştırılmayacak olmasıdır —
 * mevcut migration'ı düzenlemek yalnızca sıfırdan kurulan veritabanlarında
 * etkili olur, çalışan kurulumda kolonlar HİÇ oluşmazdı.
 *
 * VARSAYILANLAR MEVCUT SATIRLARI BOZMAZ (docs/SLA-DESIGN.md §9): dört
 * kolonun da varsayılanı "sayaç akıyor, hiç duraklama olmadı, hiç bildirim
 * üretilmedi" anlamına gelir (`null` / `0`). Böylece demo verideki 8 ihlalli
 * ticket §5.3'ün "akarken ihlal" koşulunu (`resolved_at IS NULL AND
 * sla_paused_at IS NULL AND sla_due_at < now()`) aynen sağlamaya devam eder;
 * ek doldurma (backfill) GEREKMEZ.
 *
 * YENİ INDEX YOK (§3): hem `tickets:scan-sla` tarayıcısı hem de
 * `filter[sla_breached]` mevcut `sla_due_at` ve `status` index'leri üzerinden
 * çalışır. `sla_paused_at`/`sla_paused_seconds` hiçbir sorguda TEK BAŞINA
 * seçici değildir (ezici çoğunluk null/0'dır), bu yüzden onlara index koymak
 * yalnızca yazma maliyeti eklerdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Aktif duraklamanın başlangıcı. Null ise sayaç akıyor demektir.
            // Uygulama katmanı invariant'ı: doludur <=> status = 'pending'
            // (TicketStatusMachine bu çifti tek transaction'da birlikte yazar).
            $table->dateTime('sla_paused_at')->nullable()->after('sla_due_at');

            // Birikmiş TOPLAM duraklama: kapanmış `pending` aralıkları +
            // `resolved -> open` yeniden açmalarındaki raf süresi. Yeniden
            // hesabın (öncelik değişimi) tabanıdır:
            //   sla_due_at = created_at + hedef_saat(priority) + sla_paused_seconds
            $table->unsignedInteger('sla_paused_seconds')->default(0)->after('sla_paused_at');

            // Bildirim idempotency damgaları — `tickets:scan-sla` 5 dakikada
            // bir koştuğu için aynı ticket her turda yeniden aday olur; bu iki
            // damga olmadan aynı uyarı/ihlal olayı sonsuza kadar tekrarlanırdı.
            // Redis'te tutmak uçucudur, `notifications` tablosunda aramak her
            // turda ek sorgu + kırılgan eşleştirmedir (bkz. §3 gerekçesi).
            $table->dateTime('sla_warning_notified_at')->nullable()->after('sla_paused_seconds');
            $table->dateTime('sla_breach_notified_at')->nullable()->after('sla_warning_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn([
                'sla_paused_at',
                'sla_paused_seconds',
                'sla_warning_notified_at',
                'sla_breach_notified_at',
            ]);
        });
    }
};
