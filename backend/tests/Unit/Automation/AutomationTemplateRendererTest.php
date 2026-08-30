<?php

namespace Tests\Unit\Automation;

use App\Services\Automation\AutomationTemplateRenderer;
use PHPUnit\Framework\TestCase;

class AutomationTemplateRendererTest extends TestCase
{
    public function test_it_substitutes_known_placeholders(): void
    {
        $result = AutomationTemplateRenderer::render(
            'Takip: {record_title} ({stage_name})',
            ['record_title' => 'Yıllık Sözleşme', 'stage_name' => 'Görüşme', 'status_label' => '', 'priority_label' => ''],
        );

        $this->assertSame('Takip: Yıllık Sözleşme (Görüşme)', $result);
    }

    public function test_a_placeholder_with_no_value_in_context_resolves_to_empty_string(): void
    {
        // ticket.created bağlamında {stage_name} anlamsızdır — hata FIRLATILMAZ, sessizce silinir.
        $result = AutomationTemplateRenderer::render('Yeni talep: {record_title} [{stage_name}]', [
            'record_title' => 'Sunucu çöktü',
        ]);

        $this->assertSame('Yeni talep: Sunucu çöktü []', $result);
    }

    public function test_a_template_without_any_placeholder_is_returned_unchanged(): void
    {
        $this->assertSame('Sabit metin', AutomationTemplateRenderer::render('Sabit metin', ['record_title' => 'x']));
    }
}
