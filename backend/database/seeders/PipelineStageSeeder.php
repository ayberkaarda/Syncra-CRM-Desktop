<?php

namespace Database\Seeders;

use App\Models\PipelineStage;
use Illuminate\Database\Seeder;

/**
 * Türkçe satış hunisinin çekirdek aşamaları.
 *
 * Deals tablosu bu kayıtlara restrictOnDelete ile bağlı olduğu için bu seeder
 * ÜRETİMDE de çalışır ve firstOrCreate sayesinde idempotenttir.
 *
 * `color` alanı hex değil, tasarım sistemi token adı tutar (primary/success/...).
 */
class PipelineStageSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    public const STAGES = [
        ['position' => 1, 'name' => 'Yeni Fırsat', 'slug' => 'yeni-firsat', 'probability' => 10, 'color' => 'neutral', 'is_won' => false, 'is_lost' => false],
        ['position' => 2, 'name' => 'İletişim Kuruldu', 'slug' => 'iletisim-kuruldu', 'probability' => 25, 'color' => 'info', 'is_won' => false, 'is_lost' => false],
        ['position' => 3, 'name' => 'Teklif Hazırlanıyor', 'slug' => 'teklif-hazirlaniyor', 'probability' => 50, 'color' => 'primary', 'is_won' => false, 'is_lost' => false],
        ['position' => 4, 'name' => 'Teklif Gönderildi', 'slug' => 'teklif-gonderildi', 'probability' => 70, 'color' => 'primary', 'is_won' => false, 'is_lost' => false],
        ['position' => 5, 'name' => 'Müzakere', 'slug' => 'muzakere', 'probability' => 85, 'color' => 'warning', 'is_won' => false, 'is_lost' => false],
        ['position' => 6, 'name' => 'Kazanıldı', 'slug' => 'kazanildi', 'probability' => 100, 'color' => 'success', 'is_won' => true, 'is_lost' => false],
        ['position' => 7, 'name' => 'Kaybedildi', 'slug' => 'kaybedildi', 'probability' => 0, 'color' => 'danger', 'is_won' => false, 'is_lost' => true],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::STAGES as $stage) {
            $model = PipelineStage::firstOrCreate(
                ['slug' => $stage['slug']],
                [
                    'name' => $stage['name'],
                    // Çeviri anahtarı — bu SATIR bizim taksonomimizdir, `locales/*/enums.json`daki
                    // `pipelineStage.<slug>` anahtarına karşılık gelir (bkz. migration
                    // 2026_08_25_960001). Admin bu aşamanın adını sonradan değiştirirse
                    // PipelineStageService::update() bu kolonu NULL'lar; isim o andan itibaren
                    // müşteri verisi sayılır ve bir daha çeviriyle ezilmez.
                    'name_key' => $stage['slug'],
                    'position' => $stage['position'],
                    'probability' => $stage['probability'],
                    'color' => $stage['color'],
                    'is_won' => $stage['is_won'],
                    'is_lost' => $stage['is_lost'],
                    'is_active' => true,
                ]
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Pipeline aşamaları hazır: %d yeni, %d toplam.',
            $created,
            PipelineStage::count()
        ));
    }
}
