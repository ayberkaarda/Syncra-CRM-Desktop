<?php

namespace App\Http\Requests\Chat;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/conversations/{conversation}/members`.
 *
 * `chat.use` kontrolünün neden Policy'de değil BURADA olduğu
 * StoreConversationRequest dokümanında anlatılıyor (özet: bu bir yetki sorusu
 * değil, gönderilen verinin geçerliliği sorusu — 403 değil 422).
 */
class StoreMemberRequest extends FormRequest
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
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $blocked = User::query()
                ->whereKey(array_map('intval', (array) $this->input('user_ids', [])))
                ->get()
                ->reject(fn (User $user): bool => $user->can('chat.use'))
                ->map(fn (User $user): string => $user->name)
                ->values();

            if ($blocked->isNotEmpty()) {
                $validator->errors()->add(
                    'user_ids',
                    sprintf('Sohbet yetkisi olmayan kullanıcı eklenemez: %s', $blocked->implode(', '))
                );
            }
        });
    }

    /**
     * @return array<int, int>
     */
    public function userIds(): array
    {
        return array_map('intval', (array) $this->validated()['user_ids']);
    }
}
