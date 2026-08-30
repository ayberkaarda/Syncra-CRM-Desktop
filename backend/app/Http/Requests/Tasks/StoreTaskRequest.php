<?php

namespace App\Http\Requests\Tasks;

use App\Http\Requests\Concerns\ForcesRecordOwnerOnCreate;
use App\Support\MorphTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /api/tasks` — Yetkilendirme TaskController::store() içinde Policy
 * ile yapılır.
 *
 * `created_by` ve `completed_at` KASITLI olarak burada YOK: ->validated()
 * yalnızca rules()'ta tanımlı anahtarları döner, istemci bunları gönderse
 * bile sessizce yok sayılır. `created_by` her zaman TaskService::create()
 * içinde $request->user()->id ile yazılır.
 */
class StoreTaskRequest extends FormRequest
{
    use ForcesRecordOwnerOnCreate;

    /**
     * `assigned_to` yalnızca `tasks.assign` iznine sahip aktörden kabul edilir;
     * aksi hâlde isteği yapan kullanıcıya sabitlenir (gerekçe:
     * ForcesRecordOwnerOnCreate).
     */
    protected function prepareForValidation(): void
    {
        $this->forceOwnerUnlessAssigner('assigned_to', 'tasks.assign');
    }

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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            // Hatırlatıcı vadeden SONRA olamaz — bir görevi vadesi geçtikten
            // sonra hatırlatmanın anlamı yok.
            'reminder_at' => ['nullable', 'date', 'before_or_equal:due_at'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            // İkisi birlikte gönderilmeli: required_with karşılıklı, biri
            // varsa diğeri zorunlu olur. taskable_type ayrıca beyaz listede
            // olmalı (MorphTargets::TARGETS) — sınıf enjeksiyonu engellenir.
            'taskable_type' => ['nullable', 'string', Rule::in(array_keys(MorphTargets::TARGETS)), 'required_with:taskable_id'],
            'taskable_id' => ['nullable', 'integer', 'required_with:taskable_type'],
        ];
    }

    /**
     * Beyaz listede olsa bile hedef kayıt gerçekten VAR OLMALI — var olmayan
     * bir kayda görev bağlamak sessiz bir öksüz üretir.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('taskable_type');
            $id = $this->input('taskable_id');

            if ($type !== null && $id !== null && ! MorphTargets::exists($type, $id)) {
                $validator->errors()->add('taskable_id', 'Belirtilen hedef kayıt bulunamadı.');
            }
        });
    }
}
