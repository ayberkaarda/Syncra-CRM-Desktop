<?php

namespace App\Http\Requests\Chat;

use App\Models\Attachment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/conversations/{conversation}/messages`
 *
 * -----------------------------------------------------------------------------
 * GÖVDE SÖZLEŞMESİ
 * -----------------------------------------------------------------------------
 *   body           string|null   görüntülenecek metin. `@Ad Soyad` GEÇEBİLİR;
 *                                sunucu bu metni AYRIŞTIRMAZ.
 *   attachment_id  int|null      önceden yüklenmiş ekin kimliği.
 *   mentions       int[]         bahsedilen KULLANICI ID'leri — bildirimin TEK
 *                                kaynağı (gerekçe: MentionResolver dokümanı).
 *
 * `type` İSTEMCİDEN ALINMAZ; ekten türetilir (MessageService::create). Aksi
 * halde kullanıcı `type=system` göndererek arayüzde sistem sesiyle konuşan
 * sahte bir satır üretebilirdi.
 *
 * -----------------------------------------------------------------------------
 * BOŞ MESAJ YOK
 * -----------------------------------------------------------------------------
 * `body` ve `attachment_id` ikisi birden boş olamaz. Boş mesaj hem listede
 * anlamsız bir satır bırakır hem de karşı tarafta okunmamış sayacını artırıp
 * bildirim üretir — yani hiçbir bilgi taşımadan herkesin dikkatini çeker.
 *
 * -----------------------------------------------------------------------------
 * EK SAHİPLİĞİ: BAŞKASININ DOSYASI İLİŞTİRİLEMEZ
 * -----------------------------------------------------------------------------
 * `attachment_id` düz bir tamsayıdır ve `exists:attachments,id` tek başına
 * SADECE satırın var olduğunu söyler. Bu yeterli olsaydı, bir kullanıcı 1'den
 * başlayarak id denemekle başkasının yüklediği (ör. bir teklife ait sözleşme)
 * dosyayı kendi sohbetine iliştirip ekibiyle paylaşabilirdi. Bu yüzden ek,
 * isteği yapan kullanıcının KENDİ yüklediği bir dosya olmak zorundadır.
 *
 * NOT (entegrasyon dalgası): dosya yükleme ucu paralel bir şeridin
 * sahipliğindedir; bu kural `attachments.uploaded_by` alanının yükleme
 * sırasında DOLDURULDUĞUNU varsayar.
 */
class StoreMessageRequest extends FormRequest
{
    /**
     * Tek mesajın gövde sınırı. Sohbet bir belge deposu değildir; uzun metin
     * için ek yüklenir.
     */
    public const MAX_BODY = 5000;

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
            'body' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_BODY],
            'attachment_id' => ['sometimes', 'nullable', 'integer', 'exists:attachments,id'],
            'mentions' => ['sometimes', 'nullable', 'array'],
            'mentions.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $body = trim((string) $this->input('body', ''));
            $attachmentId = $this->input('attachment_id');

            if ($body === '' && ($attachmentId === null || $attachmentId === '')) {
                $validator->errors()->add('body', 'Mesaj boş olamaz.');

                return;
            }

            if ($attachmentId === null || $attachmentId === '') {
                return;
            }

            $ownsAttachment = Attachment::query()
                ->whereKey((int) $attachmentId)
                ->where('uploaded_by', $this->user()->getKey())
                ->exists();

            if (! $ownsAttachment) {
                $validator->errors()->add('attachment_id', 'Seçilen dosya geçerli değil.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();
        $body = $validated['body'] ?? null;
        $body = $body === null ? null : trim($body);

        return [
            'body' => $body === '' ? null : $body,
            'attachment_id' => isset($validated['attachment_id']) && $validated['attachment_id'] !== null
                ? (int) $validated['attachment_id']
                : null,
            'mentions' => array_map('intval', (array) ($validated['mentions'] ?? [])),
        ];
    }
}
