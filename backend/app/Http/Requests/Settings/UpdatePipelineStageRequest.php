<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\PipelineStageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/settings/pipeline-stages/{stage}` — yetkilendirme controller'da
 * (`settings.manage`).
 *
 * =============================================================================
 * `is_won` / `is_lost` BU UÇTAN DEĞİŞTİRİLEMEZ
 * =============================================================================
 * Bu iki bayrak yalnızca bir sütun etiketi değil, DEAL'LERİN DURUM
 * MAKİNESİDİR: DealMoveService bir kartı taşırken hedef aşamanın bayrağına
 * bakarak `status`, `closed_at` ve kayıp/kazanç nedenini yazar. Var olan bir
 * aşamanın bayrağını sonradan çevirmek, o sütundaki kartların GEÇMİŞTE
 * yazılmış `status` değerleriyle sütunun yeni anlamını çelişkiye düşürürdü:
 * "Müzakere" sütunundaki 40 açık fırsat, bir tık sonra "Kazanıldı" sütununda
 * duran ama `status='open'` olan 40 kayda dönüşürdü ve hiçbir rapor bunu
 * düzeltemezdi.
 *
 * Kazanıldı/Kaybedildi bir SİSTEM aşamasıdır; yeni bir sonuç sütunu
 * gerekiyorsa `POST` ile oluşturulur.
 *
 * =============================================================================
 * `is_active: false` — TEK BAŞINA YETMEZ
 * =============================================================================
 * Aşamada açık fırsat varsa `move_to_stage_id` ZORUNLUDUR. Kural burada değil
 * PipelineStageService::deactivate() içindedir: cevabı aşamanın O ANKİ kart
 * sayısına bağlıdır ve bir FormRequest'in bilmediği (ve kilit altında
 * okunması gereken) bir bilgidir — Faz 8'deki StatusTicketRequest ve Faz
 * 9'daki QuoteService::assertAmountsEditable() ile aynı gerekçe.
 */
class UpdatePipelineStageRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('pipeline_stages', 'slug')->ignore($this->route('stage')),
            ],
            'probability' => ['sometimes', 'integer', 'between:0,100'],
            'color' => ['sometimes', 'nullable', Rule::in(PipelineStageService::COLORS)],
            'is_active' => ['sometimes', 'boolean'],
            // Yalnızca `is_active: false` ile birlikte anlamlıdır; servis
            // hedefin aktif / farklı / sonuç aşaması olmadığını ayrıca
            // doğrular (yalnız `exists` yeterli değil).
            'move_to_stage_id' => ['sometimes', 'nullable', 'integer', 'exists:pipeline_stages,id'],

            'is_won' => ['missing'],
            'is_lost' => ['missing'],
            'position' => ['missing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $systemFlagMessage = __('validation.custom.settings.pipeline_system_flag_locked');

        return [
            'slug.regex' => __('validation.custom.settings.pipeline_slug_format'),
            'color.in' => __('validation.custom.settings.pipeline_color_invalid', [
                'values' => implode('|', PipelineStageService::COLORS),
            ]),
            'is_won.missing' => $systemFlagMessage,
            'is_lost.missing' => $systemFlagMessage,
            'position.missing' => __('validation.custom.settings.pipeline_position_locked'),
        ];
    }
}
