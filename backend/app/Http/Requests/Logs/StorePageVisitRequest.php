<?php

namespace App\Http\Requests\Logs;

use Illuminate\Foundation\Http\FormRequest;

class StorePageVisitRequest extends FormRequest
{
    /**
     * Herhangi bir kimliği doğrulanmış (auth:sanctum + active + password.changed
     * grubu route middleware'i zaten sağlıyor) kullanıcı kendi sayfa ziyaretini
     * kaydedebilir — ekstra bir yetki kontrolüne gerek yok.
     */
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
            'route' => ['required', 'string', 'max:255'],
            'path' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
