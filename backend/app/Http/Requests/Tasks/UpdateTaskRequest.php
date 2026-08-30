<?php

namespace App\Http\Requests\Tasks;

use App\Support\MorphTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PATCH /api/tasks/{task}` — Yetkilendirme TaskController::update() içinde
 * Policy ile yapılır.
 *
 * `completed_at` buradan KESİNLİKLE değiştirilemez: `missing` kuralı bu
 * alan gövdede bulunursa (değeri ne olursa olsun) 422 üretir. Tamamlama
 * yalnızca `PATCH /api/tasks/{task}/complete` üzerinden yönetilir (bkz.
 * TaskService::complete()) — aksi halde `status`/`completed_at` çifti bu
 * uçtan tutarsız bir kombinasyona (ör. status='pending' ama completed_at
 * dolu) çekilebilirdi.
 */
class UpdateTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'reminder_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:due_at'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'taskable_type' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(MorphTargets::TARGETS)), 'required_with:taskable_id'],
            'taskable_id' => ['sometimes', 'nullable', 'integer', 'required_with:taskable_type'],

            // Bunlar bu uçtan HİÇ gönderilemez (değeri boş/null olsa dahi).
            'completed_at' => ['missing'],
            // `assigned_to` (Faz 13 / F8): devretme AYRI bir izin kapısıdır
            // (`tasks.assign`) ve AYRI bir ucu vardır
            // (PATCH /api/tasks/{task}/assign). Burada kabul edildiği sürece
            // yalnız `tasks.update` taşıyan bir kullanıcı görevi istediği
            // kişiye devredebiliyordu — izin kapısı baypas ediliyordu.
            'assigned_to' => ['missing'],
        ];
    }

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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to.missing' => __('validation.custom.tasks.assigned_locked'),
            'completed_at.missing' => __('validation.custom.tasks.completed_at_locked'),
        ];
    }
}
