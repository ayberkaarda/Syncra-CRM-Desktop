<?php

namespace App\Http\Requests;

use App\Services\Attachments\AttachmentTypeGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `POST /api/attachments` — Yetkilendirme AttachmentController::store()
 * içinde `chat.use` izniyle (AttachmentPolicy::create()) yapılır.
 *
 * Boyut sınırı ve uzantı allowlist'i `config/chat.php`'den okunur — burada
 * ikinci bir sabit TANIMLANMAZ (drift riski).
 */
class StoreAttachmentRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                // KB cinsinden — config/chat.php'de MB ile karıştırılmaması
                // için ayrıca belgelenmiştir.
                'max:'.(int) config('chat.attachments.max_size_kb'),
            ],
        ];
    }

    /**
     * `mimes`/`extensions` gibi hazır kurallar yalnızca uzantıya veya
     * Symfony'nin statik uzantı->MIME eşlemesine bakar; burada istenen daha
     * sıkı bir kontrol: dosyanın uzantısı VE finfo ile tespit edilen gerçek
     * İÇERİK türü BİRLİKTE `chat.attachments.mime_map` allowlist'iyle
     * eşleşmeli. İstemcinin gönderdiği Content-Type başlığına hiçbir zaman
     * bakılmaz (bkz. App\Services\Attachments\AttachmentTypeGuard).
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->hasFile('file')) {
                return;
            }

            $file = $this->file('file');

            // 'file' kuralı zaten bozuk/eksik yüklemeyi ayrı bir hatayla
            // yakalar — burada ikinci kez aynı hatayı üretmeyelim.
            if ($file === null || ! $file->isValid()) {
                return;
            }

            if (! AttachmentTypeGuard::isAllowed($file)) {
                $validator->errors()->add(
                    'file',
                    'Bu dosya türüne izin verilmiyor ya da dosya içeriği uzantısıyla uyuşmuyor.',
                );
            }
        });
    }
}
