<?php

namespace App\Http\Requests\Chat;

use App\Services\Chat\MessageService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/conversations/{conversation}/messages?before=&per_page=`
 *
 * `page` KABUL EDİLMEZ (kurallarda yok, `validated()` görmezden gelir):
 * sohbet listesi OFFSET ile sayfalanamaz — gerekçe MessageService::list()
 * dokümanında. `before` bir MESAJ ID'sidir, sayfa numarası değildir.
 */
class IndexMessageRequest extends FormRequest
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
            'before' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.MessageService::MAX_PER_PAGE],
        ];
    }

    public function before(): ?int
    {
        $value = $this->validated()['before'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function perPage(): ?int
    {
        $value = $this->validated()['per_page'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
