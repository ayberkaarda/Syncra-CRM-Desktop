<?php

namespace App\Http\Requests\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/conversations` — `dm` ya da `group`.
 *
 * -----------------------------------------------------------------------------
 * `chat.use` OLMAYAN KULLANICI ÜYE YAPILAMAZ — VE BU KONTROL POLICY'DE DEĞİL
 * -----------------------------------------------------------------------------
 * İzleyici (`İzleyici`) rolünde `chat.use` KASITLI olarak yoktur. Böyle bir
 * kullanıcıyı bir gruba eklemek, onun asla açamayacağı (kanal yetkilendirmesi
 * `chat.use` isteyerek reddeder) bir konuşmada üye olarak görünmesine yol
 * açardı: diğerleri ona yazar, o hiç görmez.
 *
 * Kontrol POLICY'DE DEĞİL, BURADA yapılır. Fark anlamlıdır: policy "SEN bunu
 * yapamazsın" der (403), oysa buradaki sorun isteği yapanın yetkisi değil,
 * GÖNDERİLEN VERİNİN geçersizliğidir — "bu kişi eklenemez" (422). Arayüz de
 * hatayı ancak alan bazlı 422 zarfıyla üye seçme kutusunun altında
 * gösterebilir; 403 tüm formu kilitlerdi.
 *
 * `type=record` BU UÇTAN AÇILAMAZ: kayda bağlı sohbetin `conversable`
 * doğrulaması ve get-or-create semantiği farklıdır, kendi ucu vardır
 * (`POST /api/conversations/for-record`).
 */
class StoreConversationRequest extends FormRequest
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
            'type' => ['required', Rule::in([Conversation::TYPE_DM, Conversation::TYPE_GROUP])],
            'name' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === Conversation::TYPE_GROUP),
                'nullable',
                'string',
                'max:255',
            ],
            'member_ids' => ['required', 'array'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = (string) $this->input('type');
            $memberIds = array_map('intval', (array) $this->input('member_ids', []));
            $actorId = (int) $this->user()->getKey();

            if ($type === Conversation::TYPE_DM) {
                $others = array_values(array_filter($memberIds, fn (int $id): bool => $id !== $actorId));

                if (count($others) !== 1) {
                    // Birebir sohbet TAM İKİ kişiliktir. Üç kişilik bir "dm",
                    // görünürde dm olan ama grup gibi davranan ve hiçbir grup
                    // kuralına (kurucu, üye çıkarma, arşivleme) tabi olmayan
                    // bir melez üretirdi.
                    $validator->errors()->add(
                        'member_ids',
                        'Birebir sohbet için kendiniz dışında tam olarak bir kişi seçmelisiniz.'
                    );

                    return;
                }
            }

            $this->assertMembersMayChat($validator, $memberIds, $actorId);
        });
    }

    /**
     * @param  array<int, int>  $memberIds
     */
    protected function assertMembersMayChat(Validator $validator, array $memberIds, int $actorId): void
    {
        $targets = array_values(array_filter($memberIds, fn (int $id): bool => $id !== $actorId));

        if ($targets === []) {
            return;
        }

        $blocked = User::query()
            ->whereKey($targets)
            ->get()
            ->reject(fn (User $user): bool => $user->can('chat.use'))
            ->map(fn (User $user): string => $user->name)
            ->values();

        if ($blocked->isNotEmpty()) {
            $validator->errors()->add(
                'member_ids',
                sprintf('Sohbet yetkisi olmayan kullanıcı eklenemez: %s', $blocked->implode(', '))
            );
        }
    }
}
