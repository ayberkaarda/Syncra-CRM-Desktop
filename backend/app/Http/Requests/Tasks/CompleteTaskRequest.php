<?php

namespace App\Http\Requests\Tasks;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `PATCH /api/tasks/{task}/complete` — Yetkilendirme TaskController::
 * complete() içinde Policy ile yapılır.
 */
class CompleteTaskRequest extends FormRequest
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
            'completed' => ['required', 'boolean'],
        ];
    }

    /**
     * İptal edilmiş (`cancelled`) bir görev tamamlanamaz — bu "geçersiz
     * durum geçişi" bir VERİ doğrulama sorunudur (422), bir yetki reddi
     * (403) değil. Route model binding {task} bu noktada zaten çözülmüş
     * olduğu için ($this->route('task')), kural burada — Policy'de değil —
     * uygulanıyor (bkz. TaskService::complete() dokümanı).
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! (bool) $this->input('completed')) {
                return;
            }

            /** @var Task|null $task */
            $task = $this->route('task');

            if ($task !== null && $task->status === 'cancelled') {
                $validator->errors()->add('completed', 'İptal edilmiş bir görev tamamlanamaz.');
            }
        });
    }
}
