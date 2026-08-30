<?php

namespace App\Services\Automation;

/**
 * Faz 14 / İz F — bir tetiklenen olayın, eylem çalıştırıcısına aktarılan DÜZ (skaler)
 * bağlamı. `App\Events\DealMoved` dokümanının aynı gerekçesi burada da geçerli: veriyi
 * TETİKLENDİĞİ anda hesaplayıp taşımak, çalıştırıcının modeli farklı bir durumda tekrar
 * sorgulamasını (ve tutarsız okumasını) önler.
 */
final class AutomationContext
{
    /**
     * @param  'deal'|'ticket'  $morphType  `App\Support\MorphTargets` kısa adı — Task oluşturulurken `taskable_type` olarak kullanılır.
     */
    public function __construct(
        public readonly string $morphType,
        public readonly int $recordId,
        public readonly string $recordTitle,
        public readonly ?int $ownerId,
        public readonly string $link,
        public readonly ?string $stageName = null,
        public readonly ?string $statusLabel = null,
        public readonly ?string $priorityLabel = null,
    ) {}

    /**
     * `AutomationCatalog::TITLE_PLACEHOLDERS` ile BİREBİR aynı anahtar kümesi — bağlamda
     * karşılığı olmayan bir placeholder boş dizeye düşer (bkz. AutomationTemplateRenderer).
     *
     * @return array<string, string>
     */
    public function placeholderValues(): array
    {
        return [
            'record_title' => $this->recordTitle,
            'stage_name' => $this->stageName ?? '',
            'status_label' => $this->statusLabel ?? '',
            'priority_label' => $this->priorityLabel ?? '',
        ];
    }
}
