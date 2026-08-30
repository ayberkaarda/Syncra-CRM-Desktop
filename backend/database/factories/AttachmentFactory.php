<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 *
 * Demo/test satırları — diskte gerçek dosya karşılığı yoktur.
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->uuid().'.pdf';

        return [
            'filename' => $filename,
            'original_name' => fake()->randomElement([
                'Teklif_Formu',
                'Sözleşme_Taslağı',
                'Fatura_Örneği',
                'Ürün_Kataloğu',
                'Toplantı_Notları',
                'Proje_Planı',
            ]).'.'.fake()->randomElement(['pdf', 'docx', 'xlsx']),
            'mime_type' => fake()->randomElement([
                'application/pdf',
                'image/png',
                'image/jpeg',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
            ]),
            'size' => fake()->numberBetween(10240, 8388608),
            'disk' => 'local',
            'path' => 'attachments/demo/'.$filename,
            'attachable_type' => null,
            'attachable_id' => null,
            'uploaded_by' => null,
        ];
    }

    /**
     * Bir mesaja bağlı — AttachmentPolicy::view() bu durumda
     * `conversation_user` üyeliğine bakar (bkz. AttachmentApiTest).
     */
    public function attachedTo(Message $message): static
    {
        return $this->state(fn (array $attributes) => [
            'attachable_type' => Message::class,
            'attachable_id' => $message->id,
        ]);
    }

    /**
     * Raster görsel — `is_image`/`?inline=1` senaryolarında kullanılır.
     */
    public function image(): static
    {
        $filename = fake()->uuid().'.png';

        return $this->state(fn (array $attributes) => [
            'filename' => $filename,
            'original_name' => 'ekran-goruntusu.png',
            'mime_type' => 'image/png',
            'path' => 'attachments/demo/'.$filename,
        ]);
    }
}
