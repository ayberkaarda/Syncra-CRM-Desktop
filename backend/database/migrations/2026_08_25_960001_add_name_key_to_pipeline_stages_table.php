<?php

use App\Models\PipelineStage;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pipeline_stages.name_key` — Faz İntl uzantısı: "Sales Funnel Türkçe kalıyor" hatasının kök
 * nedeni. `pipeline_stages.name` HEM bizim çekirdek taksonomimizi (7 seed aşama) HEM DE
 * müşterinin kendi verisini (admin'in yeniden adlandırdığı ya da yeni oluşturduğu aşamalar)
 * aynı kolonda tutuyordu; frontend'in ikisini ayırmasının yolu yoktu.
 *
 * `name_key` bu ayrımı taşır:
 *   - DOLU  → satır BİZİM taksonomimizdendir ve admin ismini HİÇ değiştirmemiştir. Değer
 *             `pipelineStage.<slug>` çeviri anahtarının son parçasıdır (`enums.json`).
 *   - NULL  → isim MÜŞTERİ VERİSİDİR (admin yeniden adlandırmış ya da yeni aşama oluşturmuş) —
 *             frontend ham `name`'i basar, çeviriye SOKMAZ.
 *
 * Bundan sonrası (`PipelineStageService::update()`) `name` GERÇEKTEN değiştiğinde bu kolonu
 * NULL'lar; bu migration yalnızca MEVCUT kurulumu (seeder tekrar çalıştırılmadan) düzeltir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->string('name_key')->nullable()->after('slug');
        });

        // Geri-doldurma: yalnızca SLUG eşleşen VE ismi hâlâ orijinal seed değeriyle BİREBİR
        // aynı olan satırlara `name_key` yazılır. Yalnızca slug'a bakmak YETMEZ — admin bu
        // migration'dan önce zaten "Müzakere"yi "Pazarlık" yapmış olabilir; slug (üretildiği
        // isimden bağımsız, sabit) aynı kalır ama isim artık MÜŞTERİ VERİSİDİR ve çeviriyle
        // ezilmemelidir. İsim karşılaştırması bu durumu ayırt eden tek sinyaldir.
        foreach (PipelineStageSeeder::STAGES as $seedStage) {
            PipelineStage::query()
                ->where('slug', $seedStage['slug'])
                ->where('name', $seedStage['name'])
                ->update(['name_key' => $seedStage['slug']]);
        }
    }

    public function down(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->dropColumn('name_key');
        });
    }
};
