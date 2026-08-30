<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/conversations/{conversation}/mute`.
 *
 * Değer AÇIKÇA gönderilir (`is_muted: true|false`), "tersine çevir" (toggle)
 * semantiği KULLANILMAZ: iki sekmeden art arda gelen iki toggle isteği,
 * kullanıcının hiç istemediği bir sonuçta (yeniden açık) biter ve arayüz
 * hangi durumda olduğunu bilemez. Açık değer idempotenttir.
 */
class MuteConversationRequest extends FormRequest
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
            'is_muted' => ['required', 'boolean'],
        ];
    }

    public function isMuted(): bool
    {
        return (bool) $this->validated()['is_muted'];
    }
}
