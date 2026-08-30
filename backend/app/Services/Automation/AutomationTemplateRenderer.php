<?php

namespace App\Services\Automation;

/**
 * Faz 14 / İz F — sabit beyaz listeden placeholder değişimi. SERBEST İFADE
 * DEĞERLENDİRMESİ YOK: yalnızca `{ad}` biçimindeki birebir metin değişimi (`eval`,
 * Blade, Twig, hiçbir şablon motoru KULLANILMAZ).
 *
 * `AllowedPlaceholdersRule` YAZMA anında yalnızca beyaz listedeki adların
 * kullanılmasını zorunlu kılar; burada ayrıca bağlamda KARŞILIĞI OLMAYAN bir
 * placeholder (ör. `ticket.created` bağlamında `{stage_name}`) sessizce boş
 * dizeye çözülür — hata FIRLATILMAZ, çünkü kural tek bir şablonla birden çok
 * tetikleyiciyle eşleşebilir ve bu durum kurallı/beklenen bir sınırdır (bkz.
 * AutomationCatalog::TITLE_PLACEHOLDERS dokümanı).
 */
final class AutomationTemplateRenderer
{
    /**
     * @param  array<string, string>  $values  placeholder adı => değer (bağlamda yoksa anahtar hiç bulunmayabilir)
     */
    public static function render(string $template, array $values): string
    {
        $rendered = preg_replace_callback(
            '/\{([^{}]*)\}/',
            static fn (array $match): string => $values[$match[1]] ?? '',
            $template,
        );

        return $rendered ?? $template;
    }
}
