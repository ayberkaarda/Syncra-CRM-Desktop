<?php

namespace App\Http\Resources;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EmailTemplate $resource
 */
class EmailTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmailTemplate $template */
        $template = $this->resource;

        return [
            'id' => $template->id,
            'key' => $template->key,
            'name' => $template->name,
            'subject' => $template->subject,
            'body_html' => $template->body_html,
            // Her zaman dizi döner (kolon nullable olsa da): istemcinin
            // `variables ?? []` yazmak zorunda kalmaması için.
            'variables' => $template->variables ?? [],
            'is_active' => (bool) $template->is_active,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
