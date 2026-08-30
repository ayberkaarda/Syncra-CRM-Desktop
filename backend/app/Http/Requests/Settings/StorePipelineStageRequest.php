<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\PipelineStageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/settings/pipeline-stages` — yetkilendirme controller'da
 * (`settings.manage`).
 *
 * `position` BURADA YOK: yeni aşama daima listenin SONUNA eklenir ve sıra
 * yalnızca `POST /api/settings/pipeline-stages/reorder` ucundan değişir. İki
 * ayrı yerden pozisyon yazılabilseydi, ekleme ile yeniden sıralama arasında
 * çelişen değerler oluşurdu.
 *
 * `is_active` de yok: yeni aşama aktif doğar. Pasifleştirme, açık fırsatların
 * ne olacağına karar vermeyi gerektiren AYRI bir işlemdir (bkz.
 * UpdatePipelineStageRequest ve PipelineStageService::deactivate()).
 */
class StorePipelineStageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Verilmezse isimden üretilir (PipelineStageService::create).
            'slug' => [
                'sometimes', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('pipeline_stages', 'slug'),
            ],
            'probability' => ['sometimes', 'integer', 'between:0,100'],
            'color' => ['sometimes', 'nullable', Rule::in(PipelineStageService::COLORS)],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],

            'position' => ['missing'],
            'is_active' => ['missing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('validation.custom.settings.pipeline_slug_format'),
            'color.in' => __('validation.custom.settings.pipeline_color_invalid', [
                'values' => implode('|', PipelineStageService::COLORS),
            ]),
            'position.missing' => __('validation.custom.settings.pipeline_position_locked'),
            'is_active.missing' => __('validation.custom.settings.pipeline_is_active_locked'),
        ];
    }
}
