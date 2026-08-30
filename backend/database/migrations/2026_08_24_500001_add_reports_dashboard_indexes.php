<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 11 — Raporlar & Dashboard sorgularının dayandığı bileşik index'ler.
 *
 * Sadece index ekler; mevcut migration dosyaları DEĞİŞTİRİLMEDİ (kural:
 * yeni migration'lar yalnızca ileri yönlü).
 *
 * `deals`:
 *   - (status, closed_at)         → KPI/sales-performance: won/lost toplamı,
 *                                    `WHERE status IN (...) AND closed_at
 *                                    BETWEEN ...` desenini karşılar.
 *   - (status, created_at)        → KPI: "bu dönemde açılan ve hâlâ açık"
 *                                    deal'lar, `WHERE status='open' AND
 *                                    created_at BETWEEN ...`.
 *   - (owner_id, status, closed_at) → user-performance: sahibe göre
 *                                    won/lost toplamı.
 *   - (pipeline_stage_id, created_at) → dashboard funnel: aşama başına,
 *                                    dönemde açılmış deal sayısı/tutarı.
 *     (Mevcut (pipeline_stage_id, position) index'i Kanban sıralaması
 *     içindir, tarih aralığı filtresine yardımcı olmaz.)
 *
 * `leads`:
 *   - (created_at)                → source-analysis/conversion: tüm
 *                                    sorguların ortak WHERE'i, hiçbir mevcut
 *                                    index'te yok.
 *   - (source, created_at)        → source-analysis: kaynak başına dönem
 *                                    filtreli sayım.
 *   - (status, converted_at)      → conversion: dönüşüm süresi ortalaması
 *                                    (`status='converted' AND converted_at
 *                                    IS NOT NULL`).
 *
 * `activities`:
 *   - (user_id, occurred_at)      → user-performance/KPI: kullanıcı başına
 *                                    dönem filtreli aktivite sayımı. Tek
 *                                    başına `occurred_at` zaten indeksli
 *                                    (Faz 8); bu, sahip filtresiyle
 *                                    birleşimi kapsar.
 *
 * `tasks`:
 *   - (status, due_at)             → dashboard task-summary: overdue/
 *                                    due-today, `status NOT IN (...) AND
 *                                    due_at ...` bileşimi.
 *
 * NOT: demo veri küçük (50 deal / 40 lead / 120 aktivite / handful task) —
 * MySQL/MariaDB'nin maliyet bazlı planlayıcısı bu ölçekte index'i
 * KULLANMAYI SEÇMEYEBİLİR (tam tarama daha ucuz görünür). Bu beklenen bir
 * davranıştır, index'in eksikliği değil; EXPLAIN sonuçları PR raporunda
 * belgeleniyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->index(['status', 'closed_at'], 'deals_status_closed_at_index');
            $table->index(['status', 'created_at'], 'deals_status_created_at_index');
            $table->index(['owner_id', 'status', 'closed_at'], 'deals_owner_status_closed_at_index');
            $table->index(['pipeline_stage_id', 'created_at'], 'deals_stage_created_at_index');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->index(['created_at'], 'leads_created_at_index');
            $table->index(['source', 'created_at'], 'leads_source_created_at_index');
            $table->index(['status', 'converted_at'], 'leads_status_converted_at_index');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->index(['user_id', 'occurred_at'], 'activities_user_occurred_at_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['status', 'due_at'], 'tasks_status_due_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex('deals_status_closed_at_index');
            $table->dropIndex('deals_status_created_at_index');
            $table->dropIndex('deals_owner_status_closed_at_index');
            $table->dropIndex('deals_stage_created_at_index');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_created_at_index');
            $table->dropIndex('leads_source_created_at_index');
            $table->dropIndex('leads_status_converted_at_index');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_user_occurred_at_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_status_due_at_index');
        });
    }
};
