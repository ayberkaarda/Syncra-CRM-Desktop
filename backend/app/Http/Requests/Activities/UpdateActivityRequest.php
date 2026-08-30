<?php

namespace App\Http\Requests\Activities;

use App\Support\MorphTargets;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PATCH /api/activities/{activity}` — Yetkilendirme
 * ActivityController::update() içinde Policy ile yapılır.
 *
 * `user_id` burada da YOK — bir aktivitenin sahibi güncelleme ile
 * DEĞİŞTİRİLEMEZ (StoreActivityRequest ile aynı gerekçe).
 */
class UpdateActivityRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(['call', 'meeting', 'email', 'note'])],
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'occurred_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:255'],
            'activityable_type' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(MorphTargets::TARGETS)), 'required_with:activityable_id'],
            'activityable_id' => ['sometimes', 'nullable', 'integer', 'required_with:activityable_type'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->has('occurred_at') && ! $validator->errors()->has('occurred_at')) {
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
