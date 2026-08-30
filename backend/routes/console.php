<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/*
 * ROADMAP R5: page_visit_logs / session_logs / activity_log günlük olarak
 * budanır. 03:17 gibi düz olmayan bir dakika seçildi ki başka zamanlanmış
 * işlerle (genelde :00/:30'da kümelenir) aynı ana denk gelmesin.
 * --force: zamanlanmış çalışma non-interactive'dir, onay isteyemez.
 */
Schedule::command('logs:prune --force')->dailyAt('03:17');

/*
 * Faz 8 / A: görev hatırlatıcıları (`reminder_at`). Her dakika çalışır ki
 * hatırlatıcı gecikmesi dakika mertebesinde kalsın — bkz.
 * App\Console\Commands\DispatchTaskReminders dokümanı (tekrar gönderimi
 * önleme tasarımı ve sınırları orada açıklanıyor).
 */
Schedule::command('tasks:dispatch-reminders')->everyMinute();

/*
 * Faz 8 / B: SLA tarayıcısı (docs/SLA-DESIGN.md §5.5). 5 dakikada bir koşar —
 * en kısa SLA hedefi 4 saat (`urgent`) olduğu için dakikalık tarama gereksiz,
 * saatlik tarama ise %20'lik uyarı penceresini (urgent'te 48 dakika) kaba
 * bırakırdı. Uyarı ve ihlal olayları ticket başına bir kez üretilir
 * (`sla_warning_notified_at` / `sla_breach_notified_at` damgaları), bu yüzden
 * sık koşmanın tekrar gönderim maliyeti yoktur.
 */
Schedule::command('tickets:scan-sla')->everyFiveMinutes();

/*
 * Faz 14 / İz E: TCMB günlük döviz kuru çekme (docs/PHASE-INTL.md §2.1,
 * §2.7). TCMB kurları genelde ~15:30'da yayınlar; 16:00 buna güvenli bir
 * pay bırakır. Hafta sonu/resmi tatilde TCMB yeni bülten YAYINLAMAZ — bu
 * HATA DEĞİLDİR: `exchange:fetch-tcmb` böyle günlerde son bilinen kuru
 * koruyarak `info` loglar ve başarı (exit 0) döner (bkz.
 * App\Console\Commands\FetchTcmbRates dokümanı). `unique(currency,
 * rate_date)` çekmeyi idempotent yapar; aynı gün birden fazla tetiklense
 * bile (bu zamanlayıcı + elle çalıştırma) veri bozulmaz/duplike olmaz.
 */
Schedule::command('exchange:fetch-tcmb')->dailyAt('16:00');
