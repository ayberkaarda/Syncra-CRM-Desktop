<?php

namespace App\Http\Requests\Activities;

use App\Support\MorphTargets;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /api/activities` — Yetkilendirme ActivityController::store() içinde
 * Policy ile yapılır.
 *
 * `user_id` KASITLI olarak burada YOK: ->validated() yalnızca rules()'ta
 * tanımlı anahtarları döner, istemci `user_id` gönderse bile sessizce yok
 * sayılır — ActivityService::create() her zaman $request->user()->id yazar.
 */
class StoreActivityRequest extends FormRequest
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
            'type' => ['required', Rule::in(['call', 'meeting', 'email', 'note'])],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'occurred_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'outcome' => ['nullable', 'string', 'max:255'],
            'activityable_type' => ['nullable', 'string', Rule::in(array_keys(MorphTargets::TARGETS)), 'required_with:activityable_id'],
            'activityable_id' => ['nullable', 'integer', 'required_with:activityable_type'],
        ];
    }

    /**
     * İki ek iş kuralı:
     *  1. `occurred_at` gelecekte olamaz — aktivite GERÇEKLEŞMİŞ bir
     *     etkileşimin kaydıdır; gelecek tarihli bir şey planlanan bir
     *     GÖREVDİR (Task), aktivite değil. Bu ayrım burada uygulanmazsa
     *     iki modül arasındaki anlam farkı veri seviyesinde bulanıklaşır.
     *  2. Hedef (activityable) beyaz listede olsa bile gerçekten VAR
     *     OLMALI — bkz. StoreTaskRequest aynı kural.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $validator->errors()->has('occurred_at')) {
                $occurredAt = $this->input('occurred_at');

                if ($occurredAt !== null && Carbon::parse((string) $occurredAt)->gt(now())) {
                    $validator->errors()->add('occurred_at', 'Aktivite tarihi gelecekte olamaz.');
                }
            }

            $type = $this->input('activityable_type');
            $id = $this->input('activityable_id');

            if ($type !== null && $id !== null && ! MorphTargets::exists($type, $id)) {
                $validator->errors()->add('activityable_id', 'Belirtilen hedef kayıt bulunamadı.');
            }
        });
    }
}
