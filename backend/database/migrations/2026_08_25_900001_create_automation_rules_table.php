<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 14 / İz F — C4 küçük no-code otomasyon kuralları
 * (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.1/§5.4).
 *
 * SABİT KATALOG, KEYFİ KOD YOK: `trigger_type`/`action_type` uygulama
 * kodundaki sabit bir listeden (bkz. `App\Services\Automation\AutomationCatalog`)
 * gelir — burada bir DB `enum` KULLANILMADI çünkü katalog PHP tarafında
 * tek doğruluk kaynağı olsun istendi (yeni bir tetikleyici/eylem eklemek
 * yalnızca Catalog'u değiştirmeyi gerektirsin, bir migration'ı değil).
 * `trigger_config`/`action_config` ham JSON olarak saklanır ama YAZMA
 * ANINDA sunucuda ŞEMAYA KARŞI doğrulanır (`AutomationConfigValidator`) —
 * asla güvenilmez, olduğu gibi bir sorguya/komuta gömülmez.
 *
 * `created_by` NOT NULL + `cascadeOnDelete()`: PHASE-AUDIT §5.4'ün ikinci
 * katmanı (ÇALIŞMA ANI yeniden-doğrulama) kuralın YAZARININ GÜNCEL
 * iznine bakar — yazar silinirse doğrulanacak bir aktör kalmaz, o yüzden
 * kural da anlamını yitirir ve birlikte silinir (sahipsiz/hayalet kural
 * bırakmamak, ForcesRecordOwnerOnCreate'in "sahipsiz kayıt istenmeyen bir
 * kaçaktır" felsefesiyle aynı).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->string('trigger_type')->index();
            $table->json('trigger_config');
            $table->string('action_type')->index();
            $table->json('action_config');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
