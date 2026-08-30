<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kişisel arayüz tercihleri — Faz 14 (docs/PHASE-INTL.md §1.3 + §2.3).
 *
 * İKİ KOLON TEK GÖÇTE (bilinçli, faz sözleşmesi kararı): `locale` İz D'nin (i18n),
 * `preferred_currency` İz E'nin (çoklu para birimi) ihtiyacıdır — ama ikisi de AYNI
 * kavramın örneğidir: `users` tablosunda yaşayan, izin gerektirmeyen, uygulama-geneli
 * `settings` kaydını KİŞİSEL olarak ezen bir tercih. Aynı mekanizmayı iki ayrı göçe
 * bölmek, aynı satırı iki kez ALTER TABLE etmek ve iki iş kolunu birbirinin göç
 * sırasına bağımlı kılmak demekti.
 *
 * TAMAMEN ADDITIVE: iki kolon da NOT NULL + DEFAULT'lu, yani mevcut satırlar
 * (varsayılan dil `tr`, temel para birimi `TRY`) hiçbir yazma gerektirmeden geçerli
 * bir değere sahip olur. Geri alma yalnızca kolonları düşürür; veri kaybı riski,
 * saklanan tek şey bir tercih olduğu için kabul edilebilir.
 *
 * TİP SEÇİMİ: `locale` char(5) — bugün kısa kodlar (`tr`/`en`/`de`/`fr`) saklanır,
 * genişlik ileride gerçekten bölgesel bir sözlük gerekirse (`pt-BR`) şemanın yetmesi
 * içindir. `preferred_currency` char(3) = ISO 4217. İkisi de `deals.currency` ile aynı
 * disiplinde sabit genişlikli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('locale', 5)->default('tr')->after('department');
            $table->char('preferred_currency', 3)->default('TRY')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'preferred_currency']);
        });
    }
};
