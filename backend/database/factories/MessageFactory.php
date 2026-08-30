<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 *
 * conversation_id is NOT NULL at the database level. It is left null in
 * definition() by design — the caller/seeder must always supply it,
 * e.g. Message::factory()->create(['conversation_id' => $conversation->id]).
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => null,
            'user_id' => null,
            'body' => fake()->randomElement([
                'Merhaba, teklifle ilgili son durumu paylaşabilir misiniz?',
                'Müşteri görüşmesi yarın saat 14:00\'te.',
                'Sözleşme taslağını ekledim, kontrol eder misiniz?',
                'Bu fırsatı öncelikli listeye aldım.',
                'Fatura kesildi, muhasebeye ilettim.',
                'Ürün demo talebi geldi, planlayalım.',
                'Destek talebi çözüldü, müşteri onayladı.',
                'Toplantı notlarını CRM\'e ekledim.',
                'Yeni lead atandı, ilgilenir misin?',
                'Ödeme henüz yapılmamış, hatırlatma gönderelim.',
                'Teklif süresi bu hafta doluyor.',
                'Görüşme iyi geçti, bir sonraki adımı planlıyoruz.',
            ]),
            'attachment_id' => null,
            'type' => Message::TYPE_TEXT,
            'edited_at' => null,
        ];
    }

    /**
     * Indicate that the message is a system message.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Message::TYPE_SYSTEM,
            'user_id' => null,
        ]);
    }

    /**
     * Indicate that the message has been edited.
     */
    public function edited(): static
    {
        return $this->state(fn (array $attributes) => [
            'edited_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    /**
     * A file message. `type` is derived from the presence of an attachment
     * exactly the way MessageService::create() derives it, so factory-built
     * rows and API-built rows are indistinguishable.
     */
    public function withAttachment(Attachment $attachment): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Message::TYPE_FILE,
            'attachment_id' => $attachment->getKey(),
        ]);
    }

    public function inConversation(Conversation $conversation): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => $conversation->getKey(),
        ]);
    }

    public function fromUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
        ]);
    }
}
