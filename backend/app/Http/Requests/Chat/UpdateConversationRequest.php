<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/conversations/{conversation}` — yalnızca grup adı.
 *
 * `type`, `created_by`, `conversable_*` ve `last_message_at` KASITLI olarak
 * yoktur; `validated()` yalnızca burada tanımlı anahtarları döndürdüğü için
 * istemci bunları gönderse bile sessizce yok sayılır. Bir sohbetin türünü
 * sonradan değiştirmek (dm -> group) üyelik ve yetki modelini altından
 * çekerdi; sahiplik ise yalnızca `leave()` üzerinden, kurallı biçimde devrolur.
 */
class UpdateConversationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
