<?php

namespace App\Http\Requests\Chat;

use App\Models\Message;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/conversations/{conversation}/read` ve
 * `POST /api/conversations/{conversation}/delivered` — ortak gövde.
 *
 * `message_id` OPSİYONELDİR: gönderilmezse konuşmanın EN SON mesajı kabul
 * edilir. En sık kullanım budur ("sohbeti açtım, hepsini gördüm") ve
 * istemciyi önce son mesajın id'sini bulmaya zorlamak, tam da yarış koşulu
 * üreten yerdir — istemcinin bildiği "son mesaj" bir saniye önceki olabilir.
 *
 * Gönderilirse mesajın BU KONUŞMAYA ait olduğu doğrulanır. Aksi halde bir
 * kullanıcı başka bir konuşmadaki id'yi göndererek imlecini olduğundan ileri
 * taşıyabilir (mesaj id'leri global bir dizidir) ve okumadığı mesajları
 * okunmuş gösterebilirdi. Silinmiş mesajlar da geçerli bir imleçtir
 * (`withTrashed`) — mezar taşı listede durduğu için okunabilir bir satırdır.
 */
class CursorConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $messageId = $this->input('message_id');

            if ($messageId === null || $messageId === '') {
                return;
            }

            $conversation = $this->route('conversation');

            $belongs = Message::withTrashed()
                ->whereKey((int) $messageId)
                ->where('conversation_id', $conversation?->getKey())
                ->exists();

            if (! $belongs) {
                $validator->errors()->add('message_id', 'Mesaj bu sohbete ait değil.');
            }
        });
    }

    public function messageId(): ?int
    {
        $value = $this->validated()['message_id'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
