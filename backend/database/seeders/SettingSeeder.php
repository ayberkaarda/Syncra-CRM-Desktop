<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Uygulamanın varsayılan ayarları. Üretimde de çalışır; updateOrCreate yerine
 * firstOrCreate kullanılır ki kullanıcının panelden yaptığı değişiklikler
 * yeniden seed edildiğinde EZİLMESİN.
 */
class SettingSeeder extends Seeder
{
    /**
     * key => [value, type, group, is_public, description]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool, 4: string}>
     */
    public const SETTINGS = [
        // Şirket profili
        'company.name' => ['Syncra Teknoloji A.Ş.', 'string', 'company', true, 'Şirket ticari unvanı'],
        'company.email' => ['info@syncra.local', 'string', 'company', true, 'Şirket iletişim e-postası'],
        'company.phone' => ['+90 212 000 00 00', 'string', 'company', true, 'Şirket telefon numarası'],
        'company.address' => ['Maslak Mah. Büyükdere Cad. No:1 Sarıyer / İstanbul', 'string', 'company', true, 'Şirket adresi'],
        'company.tax_number' => ['1234567890', 'string', 'company', false, 'Vergi kimlik numarası'],

        // Genel
        'general.currency' => ['TRY', 'string', 'general', true, 'Varsayılan para birimi'],
        'general.timezone' => ['Europe/Istanbul', 'string', 'general', true, 'Varsayılan saat dilimi'],
        'general.date_format' => ['d.m.Y', 'string', 'general', true, 'Varsayılan tarih formatı'],
        'general.language' => ['tr', 'string', 'general', true, 'Varsayılan arayüz dili'],

        // Teklif
        'quote.validity_days' => ['30', 'integer', 'quote', false, 'Teklif geçerlilik süresi (gün)'],
        'quote.default_tax_rate' => ['20', 'integer', 'quote', false, 'Varsayılan KDV oranı (%)'],
        'quote.terms' => [
            'Ödeme, fatura tarihinden itibaren 30 gün içinde yapılır. Fiyatlara KDV dahil değildir. Teklif, belirtilen geçerlilik süresi boyunca bağlayıcıdır.',
            'string',
            'quote',
            false,
            'Tekliflerde kullanılan varsayılan şartlar metni',
        ],

        // Destek talebi (SLA)
        'ticket.sla_hours_low' => ['72', 'integer', 'ticket', false, 'Düşük öncelikli talep SLA süresi (saat)'],
        'ticket.sla_hours_normal' => ['48', 'integer', 'ticket', false, 'Normal öncelikli talep SLA süresi (saat)'],
        'ticket.sla_hours_high' => ['24', 'integer', 'ticket', false, 'Yüksek öncelikli talep SLA süresi (saat)'],
        'ticket.sla_hours_urgent' => ['4', 'integer', 'ticket', false, 'Acil öncelikli talep SLA süresi (saat)'],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::SETTINGS as $key => [$value, $type, $group, $isPublic, $description]) {
            $setting = Setting::firstOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'type' => $type,
                    'group' => $group,
                    'is_public' => $isPublic,
                    'description' => $description,
                ]
            );

            if ($setting->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Ayarlar hazır: %d yeni, %d toplam.',
            $created,
            Setting::count()
        ));
    }
}
