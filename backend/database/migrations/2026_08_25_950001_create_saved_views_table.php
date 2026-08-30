<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler (Saved Views)
 * (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.1/§5.4).
 *
 * ŞEMA: `user_id` (sahip), `module` (deals/leads/contacts/companies/quotes/tickets/tasks/
 * products/users — beyaz liste `App\Services\SavedViews\SavedViewModules`), `name`,
 * `query_json` (mevcut Faz 6 liste sözleşmesinin — `?page&per_page&sort&filter[]&q` —
 * SADECE `sort`/`q`/`per_page`/`filter` alanlarını taşıyan bir anlık görüntüsü), `is_shared`.
 *
 * `query_json` HAM DEĞİLDİR: yazılırken de okunurken de
 * `App\Services\SavedViews\SavedViewQueryValidator` ile modül başına BEYAZ LİSTEYE karşı
 * doğrulanır (docs/PHASE-AUDIT.md §5.4 — "ham filtre olarak sorguya gömülmemeli"). Burada bir
 * DB `enum`/`json` şeması KULLANILMADI çünkü beyaz liste PHP tarafında (mevcut
 * `Index*Request` sınıflarından TÜRETİLMİŞ) tek doğruluk kaynağıdır — yeni bir filtre alanı
 * eklemek bir migration'ı değil yalnızca `SavedViewQuerySchema`'yı değiştirmeyi gerektirir.
 *
 * `unique(user_id, module, name)`: aynı kullanıcı aynı modülde aynı adı iki kez kullanamaz —
 * `StoreSavedViewRequest`/`UpdateSavedViewRequest` bunu `Rule::unique()` ile 422'ye çevirir,
 * bu kısıt yalnızca son bir güvenlik ağı (yarış durumu / doğrudan DB yazımı).
 *
 * `is_shared` üstünde AYRI bir index YOK: `SavedViewController::index()` sorgusu her zaman
 * `module` (+`user_id` OR `is_shared`) ile filtrelenir — birleşik `(module, is_shared)`
 * index'i bu sorgu şeklini karşılar, `user_id` zaten FK index'i taşır.
 *
 * `created_by` YOK, `user_id` VAR: AutomationRule'daki `created_by` isimlendirmesinden
 * BİLEREK farklı — orada "kural onu yazanın izniyle çalışır" vurgusu isim seçimine
 * yansımıştı (docs/PHASE-AUDIT.md §5.4), burada ise sahiplik CRUD'un (kim
 * düzenleyebilir/silebilir) öznesi olduğu için standart Eloquent `user_id`/`belongsTo`
 * ismi tercih edildi — PHASE-AUDIT §5.4'ün "AÇAN kullanıcının yetkisiyle çalışır" kuralı
 * zaten SavedView modelinde veri TAŞIMAMASIYLA (yalnızca filtre parametreleri) sağlanıyor,
 * isimlendirmenin bunu ayrıca vurgulaması gerekmiyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module');
            $table->string('name');
            $table->json('query_json');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'module', 'name']);
            $table->index(['module', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
