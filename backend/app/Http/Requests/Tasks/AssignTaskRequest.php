<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/tasks/{task}/assign` — Yetkilendirme TaskController::assign()
 * içinde Policy ile yapılır.
 */
class AssignTaskRequest extends FormRequest
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
            // Yalnızca aktif ve silinmemiş bir kullanıcıya atanabilir: düz
            // `exists:users,id` devre dışı/silinmiş hesabı da kabul ediyordu.
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->withoutTrashed()],
        ];
    }
}
