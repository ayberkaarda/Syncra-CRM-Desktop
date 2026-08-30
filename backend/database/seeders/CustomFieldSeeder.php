<?php

namespace Database\Seeders;

use App\Models\CustomField;
use Illuminate\Database\Seeder;

/**
 * Örnek özel alan tanımları. `custom_fields` tablosunda (entity_type, key)
 * unique olduğu için firstOrCreate bu iki kolon üzerinden yapılır.
 */
class CustomFieldSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    public const FIELDS = [
        [
            'entity_type' => 'leads',
            'key' => 'butce',
            'name' => 'Bütçe',
            'type' => 'number',
            'options' => null,
            'is_required' => false,
            'position' => 1,
        ],
        [
            'entity_type' => 'leads',
            'key' => 'ilgilendigi_urun',
            'name' => 'İlgilendiği Ürün',
            'type' => 'select',
            'options' => ['CRM Lisansı', 'Destek Paketi', 'Danışmanlık', 'Eğitim', 'Entegrasyon'],
            'is_required' => false,
            'position' => 2,
        ],
        [
            'entity_type' => 'companies',
            'key' => 'vergi_dairesi',
            'name' => 'Vergi Dairesi',
            'type' => 'text',
            'options' => null,
            'is_required' => false,
            'position' => 1,
        ],
        [
            'entity_type' => 'deals',
            'key' => 'rakip_firma',
            'name' => 'Rakip Firma',
            'type' => 'text',
            'options' => null,
            'is_required' => false,
            'position' => 1,
        ],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::FIELDS as $field) {
            $model = CustomField::firstOrCreate(
                ['entity_type' => $field['entity_type'], 'key' => $field['key']],
                [
                    'name' => $field['name'],
                    'type' => $field['type'],
                    'options' => $field['options'],
                    'is_required' => $field['is_required'],
                    'position' => $field['position'],
                    'is_active' => true,
                ]
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Özel alanlar hazır: %d yeni, %d toplam.',
            $created,
            CustomField::count()
        ));
    }
}
