<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/messages/search?q=&conversation_id=`
 *
 * `q` en az 2 karakter: tek harflik bir arama, `LIKE '%a%'` ile mesaj
 * tablosunun tamamını tarar ve kullanıcıya işe yaramaz bir sonuç yığını
 * döner — hem pahalı hem faydasız.
 *
 * `conversation_id` yalnızca DARALTMA amaçlıdır; erişim kontrolü DEĞİLDİR.
 * Kullanıcının üyesi olmadığı bir konuşmanın id'sini göndermesi hiçbir şey
 * açmaz: üyelik kısıtı sorgunun içindedir (MessageService::search) ve sonuç
 * boş döner.
 */
class SearchMessageRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function term(): string
    {
        return (string) $this->validated()['q'];
    }

    public function conversationId(): ?int
    {
        $value = $this->validated()['conversation_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function perPage(): ?int
    {
        $value = $this->validated()['per_page'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
