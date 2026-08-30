<?php

namespace App\Http\Requests\Chat;

use App\Services\Chat\RecordChatRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/conversations/for-record` — kayda bağlı sohbeti aç/getir.
 *
 * `conversable_type` İSTEMCİDEN GELİR ve ASLA sınıf adına çevrilmez:
 * `"App\\Models\\".$type` yazmak, autoload edilebilen herhangi bir sınıfı
 * hedef gösterebilen bir sınıf enjeksiyonu açığıdır. Kabul edilen tipler
 * RecordChatRegistry::TYPES sabit dizisidir (Faz 4/6/8'deki aynı desen).
 *
 * Kaydın GERÇEKTEN VAR OLDUĞU da burada doğrulanır — yoksa `conversations`
 * tablosunda hiçbir zaman açılmayacak öksüz bir satır kalır ve var olmayan bir
 * id'ye 200 dönmek id uzayını sızdırır. Kaydı GÖRME yetkisi ayrı bir sorudur
 * ve Policy'ye aittir (ConversationPolicy::canSeeRecord).
 */
class ForRecordConversationRequest extends FormRequest
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
            'conversable_type' => ['required', 'string', Rule::in(RecordChatRegistry::TYPES)],
            'conversable_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = (string) $this->input('conversable_type');
            $id = (int) $this->input('conversable_id');

            if (! RecordChatRegistry::exists($type, $id)) {
                $validator->errors()->add('conversable_id', 'Seçilen kayıt bulunamadı.');
            }
        });
    }

    public function recordType(): string
    {
        return (string) $this->validated()['conversable_type'];
    }

    public function recordId(): int
    {
        return (int) $this->validated()['conversable_id'];
    }
}
