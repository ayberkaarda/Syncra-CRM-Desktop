<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Sıra FK bağımlılığına göredir: roller/izinler → süper admin →
     * pipeline aşamaları (deals bunlara bağlı) → ayarlar (ticket SLA saatleri
     * DemoDataSeeder tarafından okunur) → özel alanlar → demo veri.
     */
    public function run(): void
    {
        $this->command?->newLine();
        $this->command?->info('=== Syncra veritabanı seed ediliyor ===');

        $this->call([
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
            PipelineStageSeeder::class,
            SettingSeeder::class,
            CustomFieldSeeder::class,
        ]);

        if (app()->environment('production')) {
            $this->command?->warn('Üretim ortamı algılandı — DemoDataSeeder ATLANDI (demo veri üretilmedi).');

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}
