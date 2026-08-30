<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/settings/pipeline-stages/reorder` — gövde:
 * `{ "ordered_ids": [3, 1, 2] }`.
 *
 * =============================================================================
 * BU `pipeline_stages.position` — `deals.position` DEĞİL
 * =============================================================================
 * İki farklı "position" kolonu vardır ve karıştırılmaları veri kaybı demektir:
 *
 *   pipeline_stages.position  unsignedInteger  SÜTUNLARIN soldan sağa sırası
 *   deals.position            string(64)       KARTLARIN sütun içindeki sırası
 *                                              (fractional index, bkz.
 *                                              App\Support\FractionalIndex)
 *
 * Burada yeniden yazılan yalnızca BİRİNCİSİDİR. Sütunları sıralamak hiçbir
 * kartı kıpırdatmaz; hiçbir `deals` satırına dokunulmaz.
 *
 * =============================================================================
 * NEDEN TÜM AŞAMALAR GÖNDERİLMEK ZORUNDA
 * =============================================================================
 * Kısmi bir liste ("3 numarayı en başa al") sıra için YETERSİZ BİLGİDİR:
 * gönderilmeyen aşamaların yeni sırada nereye düşeceği sunucunun tahminine
 * kalırdı ve iki kullanıcı arka arkaya kısmi sıralama yaparsa sonuç,
 * isteklerin geliş sırasına göre değişirdi. Ayarlar ekranı zaten listenin
 * tamamını sürükleyerek düzenler; tam liste göndermek ona hiçbir maliyet
 * getirmez. Eksik/fazla liste PipelineStageService::reorder() içinde 422
 * `STAGE_REORDER_INCOMPLETE` ile reddedilir (orada, çünkü karşılaştırma
 * veritabanındaki TOPLAM aşama sayısına bakar).
 */
class ReorderPipelineStagesRequest extends FormRequest
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
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'distinct', 'exists:pipeline_stages,id'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function orderedIds(): array
    {
        return array_map('intval', (array) $this->validated()['ordered_ids']);
    }
}
