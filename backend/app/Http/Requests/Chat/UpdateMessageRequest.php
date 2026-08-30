<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/messages/{message}` — yalnızca gövde.
 *
 * `attachment_id` ve `type` DEĞİŞTİRİLEMEZ (kurallarda yok): bir metin
 * mesajını sonradan dosya mesajına çevirmek, karşı tarafın okuduğu şeyi
 * geriye dönük başka bir şeye dönüştürmektir. `mentions` de yoktur —
 * düzenleme yeni bildirim üretmez; aksi halde kullanıcı aynı mesajı defalarca
 * düzenleyerek birini istediği kadar dürtebilirdi.
 *
 * Boş gövdeye izin verilmez: mesajı boşaltmak, silmenin izini bırakmayan bir
 * biçimidir; silmek için `DELETE` ucu vardır ve o mezar taşı bırakır.
 */
class UpdateMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:'.StoreMessageRequest::MAX_BODY],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['body' => trim((string) $this->validated()['body'])];
    }
}
