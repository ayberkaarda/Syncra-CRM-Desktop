<?php

namespace App\Services\Settings;

use App\Models\EmailTemplate;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Collection;

/**
 * E-posta şablonları — SAKLAMA ve ÖNİZLEME.
 *
 * =============================================================================
 * BU FAZDA E-POSTA GÖNDERİLMİYOR
 * =============================================================================
 * Sistem kapalı devredir ve `MAIL_MAILER=log`. Şablonlar hazırlanır, saklanır
 * ve önizlenir; gönderim uçları YOKTUR. Bu bilinçli: gönderim eklemek SMTP
 * yapılandırması, kuyruk, teslim/geri dönüş takibi ve "kime, ne zaman, kaç
 * kez" kayıtları demektir — hiçbiri bu fazın kapsamında değil ve yarım
 * uygulanmış bir gönderim, kullanıcıya gitmiş sanılan ama hiç çıkmamış
 * e-postalar üretirdi.
 *
 * =============================================================================
 * `variables` — TEK DOĞRULUK KAYNAĞI METNİN KENDİSİ
 * =============================================================================
 * Değişken listesi gövdede gönderilmezse `subject` + `body_html` içindeki
 * `{{ degisken }}` yer tutucularından türetilir. Elle tutulan bir liste,
 * metin değiştikçe kaçınılmaz olarak metinle ayrışır: önizleme ekranı artık
 * kullanılmayan bir değişkeni sorar ve gerçekten kullanılan bir değişkeni
 * sormaz — çıktıda ham `{{ ... }}` kalır. Türetme bunu imkânsız kılar.
 *
 * Liste AÇIKÇA gönderilirse olduğu gibi saklanır: henüz metne yazılmamış ama
 * planlanan bir değişkeni tanımlamak meşru bir istek.
 *
 * =============================================================================
 * `body_html` SANİTİZASYONU (Faz 13 / H6, §4-F5)
 * =============================================================================
 * Gövde DB'ye YAZILMADAN ÖNCE `HtmlSanitizer` beyaz listesinden geçer. HTTP
 * uçları için bu ikinci katmandır (FormRequest zaten `prepareForValidation`
 * içinde temizler) ama TEK katman olamazdı: seeder, konsol komutu veya ileride
 * bir import HTTP doğrulamasını hiç görmez ve kirli HTML doğrudan buraya
 * gelirdi. Sanitizer idempotent olduğu için çift geçiş içeriği aşındırmaz.
 *
 * `variables` türetmesi de SANİTİZE EDİLMİŞ metin üzerinden yapılır: aksi
 * halde yalnız `<script>` içinde geçen bir `{{degisken}}`, gövdede artık
 * bulunmayan bir değişkeni listeye yazardı.
 */
class EmailTemplateService
{
    use DeniesSettingsChange;

    /**
     * `{{ degisken }}` / `{{degisken.alt}}` yakalar. Nokta ve alt çizgi
     * kabul edilir (`quote.total`, `contact_name`); boşluklar esnektir.
     */
    protected const VARIABLE_PATTERN = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_.]*)\s*\}\}/';

    /**
     * @return Collection<int, EmailTemplate>
     */
    public function list(bool $includeInactive = true): Collection
    {
        $query = EmailTemplate::query()->orderBy('name');

        if (! $includeInactive) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): EmailTemplate
    {
        $subject = (string) $data['subject'];
        $bodyHtml = HtmlSanitizer::sanitizeEmailBody((string) $data['body_html']);

        return EmailTemplate::query()->create([
            'key' => (string) $data['key'],
            'name' => (string) $data['name'],
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'variables' => array_key_exists('variables', $data) && is_array($data['variables'])
                ? array_values(array_map('strval', $data['variables']))
                : self::extractVariables($subject, $bodyHtml),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EmailTemplate $template, array $data): EmailTemplate
    {
        if (array_key_exists('key', $data) && (string) $data['key'] !== (string) $template->key) {
            $this->deny(
                'Şablon anahtarı (key) oluşturulduktan sonra değiştirilemez.',
                'EMAIL_TEMPLATE_KEY_IMMUTABLE',
                ['key' => ['Şablon anahtarı oluşturulduktan sonra değiştirilemez.']],
                ['current_key' => (string) $template->key],
            );
        }

        // Sanitizasyon `$data` üzerinde YERİNDE yapılır ki hem kaydedilen
        // değer hem aşağıdaki `extractVariables` türetmesi aynı temiz metni
        // görsün (bkz. sınıf dokümanı).
        if (array_key_exists('body_html', $data)) {
            $data['body_html'] = HtmlSanitizer::sanitizeEmailBody((string) $data['body_html']);
        }

        $attributes = array_intersect_key($data, array_flip(['name', 'subject', 'body_html', 'is_active']));

        if (array_key_exists('variables', $data)) {
            $attributes['variables'] = is_array($data['variables'])
                ? array_values(array_map('strval', $data['variables']))
                : null;
        } elseif (array_key_exists('subject', $data) || array_key_exists('body_html', $data)) {
            // Metin değişti ama liste gönderilmedi: liste metinden yeniden
            // türetilir, yoksa iki taraf ayrışırdı (sınıf dokümanı).
            $attributes['variables'] = self::extractVariables(
                (string) ($data['subject'] ?? $template->subject),
                (string) ($data['body_html'] ?? $template->body_html),
            );
        }

        if ($attributes !== []) {
            $template->fill($attributes)->save();
        }

        return $template->refresh();
    }

    /**
     * Gerçek silme — `email_templates` tablosuna bağlı hiçbir kayıt yoktur
     * (şablonlar henüz bir gönderime bağlanmıyor), dolayısıyla CustomField'daki
     * "silme yerine pasifleştirme" gerekçesi burada geçerli değil: silinen bir
     * şablon hiçbir veriyi yanında götürmez. Geçici olarak devre dışı bırakmak
     * isteyen `is_active: false` kullanır.
     */
    public function delete(EmailTemplate $template): void
    {
        $template->delete();
    }

    /**
     * Metinde geçen `{{ degisken }}` adları — sırayı koruyarak, tekrarsız.
     *
     * @return array<int, string>
     */
    public static function extractVariables(string $subject, string $bodyHtml): array
    {
        $matches = [];

        preg_match_all(self::VARIABLE_PATTERN, $subject.' '.$bodyHtml, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
