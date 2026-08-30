<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Faz 13 / H6 (§4-F5) — HtmlSanitizer sözleşmesi.
 *
 * =============================================================================
 * NEDEN ÇIKTI DİZESİ TAM EŞLEŞMEYLE DEĞİL, YENİDEN AYRIŞTIRARAK DOĞRULANIYOR
 * =============================================================================
 * Sanitizer'ın ürettiği KESİN dize motorun iç ayrıntısıdır (öznitelik sırası,
 * boşluk normalizasyonu, yüzde kodlaması). Örneğin `java&#9;script:alert(1)`
 * girdisi zararsızlaştırılmış hâlde `java%20script%3Aalert(1)` olarak çıkar —
 * yani içinde HÂLÂ "java" ve "script" alt dizeleri geçer. Naif bir
 * `assertStringNotContainsString('script', ...)` burada YANLIŞ ALARM verir;
 * daha kötüsü, ters yönde bir naif kontrol gerçek bir kaçışı kaçırabilir.
 *
 * Bu yüzden iddialar ANLAMSAL: çıktı DOM olarak yeniden ayrıştırılır ve
 * "hiçbir düğümde `on*` özniteliği yok", "hiçbir `href`/`src` izinsiz şema
 * taşımıyor", "yasak etiket yok" invaryantları kontrol edilir. Bu, motor
 * değişse bile (KARAR 1'deki bağımlılık notu) geçerli kalan bir sözleşmedir.
 */
class HtmlSanitizerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Bağımlılık koruması
    // ---------------------------------------------------------------

    /**
     * `ezyang/htmlpurifier` `composer.json`'da DOĞRUDAN yazılı değil,
     * phpspreadsheet üzerinden geçişli geliyor (bkz. HtmlSanitizer KARAR 1).
     * Bir `composer update` onu düşürürse bu test GÜRÜLTÜLÜ patlar; aksi
     * hâlde eksiklik ancak üretimde bir 500 olarak fark edilirdi.
     */
    public function test_html_purifier_dependency_is_present(): void
    {
        $this->assertTrue(
            HtmlSanitizer::isAvailable(),
            'Sanitizasyon motoru kayıp: ezyang/htmlpurifier composer.json\'a doğrudan bağımlılık olarak eklenmeli.'
        );
    }

    // ---------------------------------------------------------------
    // XSS vektörleri — hepsi zararsızlaşmalı
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function xssVectors(): array
    {
        return [
            'script etiketi' => ['<script>alert(1)</script>'],
            'script + çevresinde metin' => ['<p>önce</p><script>alert(1)</script><p>sonra</p>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'javascript: şeması' => ['<a href="javascript:alert(1)">tıkla</a>'],
            'javascript: karışık büyük/küçük harf' => ['<a href="JaVaScRiPt:alert(1)">tıkla</a>'],
            'javascript: gömülü sekme varlığı' => ['<a href="java&#9;script:alert(1)">tıkla</a>'],
            'javascript: gömülü satır başı varlığı' => ['<a href="java&#10;script:alert(1)">tıkla</a>'],
            'javascript: öndeki boşluk' => ['<a href="  javascript:alert(1)">tıkla</a>'],
            'javascript: varlık kodlu ilk harf' => ['<a href="&#106;avascript:alert(1)">tıkla</a>'],
            'vbscript: şeması' => ['<a href="vbscript:msgbox(1)">tıkla</a>'],
            'data:text/html' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">tıkla</a>'],
            'img src javascript:' => ['<img src="javascript:alert(1)">'],
            'iframe' => ['<iframe src="https://evil.example/x"></iframe>'],
            'svg onload' => ['<svg onload=alert(1)><circle r="1"/></svg>'],
            'style bloğu' => ['<style>body{background:url(javascript:alert(1))}</style>'],
            'style özniteliği' => ['<p style="background-image:url(https://evil.example/pixel)">izleme</p>'],
            'form + input' => ['<form action="https://evil.example/steal"><input name="pw"></form>'],
            'base etiketi' => ['<base href="https://evil.example/">'],
            'object' => ['<object data="https://evil.example/x.swf"></object>'],
            'embed' => ['<embed src="https://evil.example/x.swf">'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
            'link stylesheet' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
            'body onload' => ['<body onload=alert(1)>metin</body>'],
            'div onmouseover' => ['<div onmouseover="alert(1)">üzerine gel</div>'],
            'koşullu yorum' => ['<div><!--[if IE]><script>alert(1)</script><![endif]--></div>'],
            'kapatılmamış script' => ['<div><script>alert(1)'],
            'noscript içinde script' => ['<noscript><script>alert(1)</script></noscript>'],
        ];
    }

    #[DataProvider('xssVectors')]
    public function test_vector_is_neutralized(string $payload): void
    {
        $this->assertSanitized(HtmlSanitizer::sanitizeEmailBody($payload));
    }

    // ---------------------------------------------------------------
    // Pozitif vakalar — meşru içerik KORUNMALI
    // ---------------------------------------------------------------

    public function test_allowed_formatting_tags_survive(): void
    {
        $output = HtmlSanitizer::sanitizeEmailBody(
            '<h2>Başlık</h2><p><strong>Kalın</strong> ve <em>eğik</em>.</p>'
            .'<ul><li>bir</li><li>iki</li></ul><blockquote>alıntı</blockquote><hr>'
        );

        foreach (['h2', 'p', 'strong', 'em', 'ul', 'li', 'blockquote', 'hr'] as $tag) {
            $this->assertStringContainsString('<'.$tag, $output, "İzinli `$tag` etiketi düşürülmüş.");
        }
        $this->assertStringContainsString('Kalın', $output);
    }

    public function test_allowed_link_and_image_survive_with_attributes(): void
    {
        $output = HtmlSanitizer::sanitizeEmailBody(
            '<a href="https://syncra.example/teklif" title="Teklif">Teklifi gör</a>'
            .'<img src="https://syncra.example/logo.png" alt="Logo" width="120" height="40">'
            .'<a href="mailto:destek@syncra.example">Yazın</a>'
        );

        $this->assertStringContainsString('https://syncra.example/teklif', $output);
        $this->assertStringContainsString('mailto:destek@syncra.example', $output);
        $this->assertStringContainsString('https://syncra.example/logo.png', $output);
        $this->assertStringContainsString('alt="Logo"', $output);
        $this->assertStringContainsString('width="120"', $output);
        $this->assertSanitized($output);
    }

    public function test_table_layout_survives(): void
    {
        $output = HtmlSanitizer::sanitizeEmailBody(
            '<table><thead><tr><th colspan="2">Kalemler</th></tr></thead>'
            .'<tbody><tr><td>Ürün</td><td>1.250,00 ₺</td></tr></tbody></table>'
        );

        $this->assertStringContainsString('<table', $output);
        $this->assertStringContainsString('colspan="2"', $output);
        $this->assertStringContainsString('1.250,00 ₺', $output);
    }

    /**
     * Şablon değişkenleri düz METİNDİR; sanitizasyon onlara dokunmamalı, yoksa
     * EmailTemplateService::extractVariables hiçbir değişken bulamaz.
     */
    public function test_template_placeholders_survive(): void
    {
        $output = HtmlSanitizer::sanitizeEmailBody('<p>Sayın {{ musteri_adi }}, {{quote.total}}</p>');

        $this->assertStringContainsString('{{ musteri_adi }}', $output);
        $this->assertStringContainsString('{{quote.total}}', $output);
    }

    // ---------------------------------------------------------------
    // UTF-8 / Türkçe — DOMDocument tabanlı ayrıştırmanın klasik tuzağı
    // ---------------------------------------------------------------

    /**
     * libxml tabanlı HTML ayrıştırıcılara kodlama İPUCU verilmezse gövde
     * ISO-8859-1 sanılır ve Türkçe karakterler mojibake'e döner ("Şirket" ->
     * "Åirket"). Sanitizer motoruna `Core.Encoding=UTF-8` verildi; burada
     * kilitleniyor — bu test kırmızıya dönerse şablonlar sessizce bozuluyordur.
     */
    public function test_turkish_characters_are_preserved(): void
    {
        $input = '<p>Şirket: Öztürk Işık Çelik A.Ş. — ĞÜİÖÇ / ğüişöçı</p>';
        $output = HtmlSanitizer::sanitizeEmailBody($input);

        foreach (['ş', 'ğ', 'İ', 'ı', 'ö', 'ü', 'ç', 'Ş', 'Ö', 'Ç', 'Ğ'] as $char) {
            $this->assertStringContainsString($char, $output, "Türkçe karakter kayboldu: $char");
        }

        $this->assertSame($input, $output, 'Tamamen izinli Türkçe içerik hiç değişmemeliydi.');
        $this->assertTrue(mb_check_encoding($output, 'UTF-8'), 'Çıktı geçerli UTF-8 değil.');
    }

    public function test_turkish_characters_survive_alongside_a_stripped_payload(): void
    {
        $output = HtmlSanitizer::sanitizeEmailBody(
            '<p>Değerli müşterimiz</p><script>alert(1)</script><p>Işıl Ünlü</p>'
        );

        $this->assertStringContainsString('Değerli müşterimiz', $output);
        $this->assertStringContainsString('Işıl Ünlü', $output);
        $this->assertSanitized($output);
    }

    // ---------------------------------------------------------------
    // Sözleşme özellikleri
    // ---------------------------------------------------------------

    /**
     * Aynı değer hem FormRequest'te hem serviste temizleniyor. İkinci geçiş
     * içeriği değiştirseydi şablon her kaydedişte aşınırdı.
     */
    public function test_sanitization_is_idempotent(): void
    {
        $input = '<p>Şirket <a href="https://x.example">bağlantı</a></p>'
            .'<img src="https://x.example/a.png" alt="a"><script>alert(1)</script>'
            .'<table><tr><td colspan="2">hücre</td></tr></table>';

        $once = HtmlSanitizer::sanitizeEmailBody($input);
        $twice = HtmlSanitizer::sanitizeEmailBody($once);

        $this->assertSame($once, $twice);
    }

    public function test_empty_input_stays_empty(): void
    {
        $this->assertSame('', HtmlSanitizer::sanitizeEmailBody(''));
        $this->assertSame('', HtmlSanitizer::sanitizeEmailBody('   '));
    }

    /**
     * Gövdesi TAMAMEN yükten ibaret bir istek boş dizeye iner — FormRequest
     * bunu `required` ile 422'ye çevirir (bkz. StoreEmailTemplateRequest).
     */
    public function test_payload_only_body_collapses_to_empty_string(): void
    {
        $this->assertSame('', trim(HtmlSanitizer::sanitizeEmailBody('<script>alert(1)</script>')));
    }

    /**
     * Şemasız (göreli) URL tanımı gereği `javascript:` olamaz; yasaklamak
     * yalnız meşru içeriği bozardı (bkz. HtmlSanitizer KARAR 3).
     */
    public function test_relative_urls_are_left_alone(): void
    {
        $output = HtmlSanitizer::sanitizeEmailBody('<a href="/kampanya">bak</a>');

        $this->assertStringContainsString('href="/kampanya"', $output);
    }

    // ---------------------------------------------------------------
    // Ortak invaryant iddiası
    // ---------------------------------------------------------------

    /**
     * Çıktıyı DOM olarak yeniden ayrıştırıp güvenlik invaryantlarını
     * doğrular (neden dize karşılaştırması yapılmadığı için sınıf dokümanı).
     */
    private function assertSanitized(string $html): void
    {
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'Çıktı geçerli UTF-8 değil.');

        if (trim($html) === '') {
            return;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Kodlama ipucu olmadan libxml gövdeyi ISO-8859-1 sanar; testin kendi
        // ayrıştırması da bu tuzağa düşmemeli.
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><html><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        $forbiddenTags = [
            'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
            'button', 'base', 'link', 'meta', 'svg', 'math', 'applet', 'frame',
            'frameset', 'noscript', 'template',
        ];

        foreach ($forbiddenTags as $tag) {
            $this->assertSame(
                0,
                $doc->getElementsByTagName($tag)->length,
                "Yasak `<$tag>` etiketi çıktıda kalmış: $html"
            );
        }

        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($element->nodeName);

            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);

                $this->assertStringStartsNotWith(
                    'on',
                    $name,
                    "Olay özniteliği `$name` çıktıda kalmış: $html"
                );

                $this->assertNotContains(
                    $name,
                    ['style', 'srcdoc', 'formaction', 'xlink:href'],
                    "Tehlikeli öznitelik `$name` çıktıda kalmış: $html"
                );

                if (in_array($name, ['href', 'src'], true)) {
                    $this->assertUrlSchemeIsAllowed((string) $attribute->nodeValue, $html);
                }
            }

            // `html`/`body` sarmalayıcıları ayrıştırıcının eklediği düğümler.
            if (! in_array($tag, ['html', 'body'], true)) {
                $this->assertArrayHasKey(
                    $tag,
                    HtmlSanitizer::ALLOWED,
                    "Beyaz listede olmayan `<$tag>` etiketi çıktıda kalmış: $html"
                );
            }
        }
    }

    /**
     * Şema kontrolü, tarayıcının URL'i nasıl okuduğunu taklit eder: önce TÜM
     * kontrol karakterleri/boşluklar atılır (tarayıcı sekme ve satır sonlarını
     * URL'in HER YERİNDEN siler — `java\tscript:` kaçış vektörünün temeli),
     * sonra ASCII küçük harfe indirilip ilk `:`'e kadar olan kısım şema
     * sayılır. `mb_strtolower` BİLEREK kullanılmadı: Türkçe/locale duyarlı
     * katlama şema karşılaştırmasında sürpriz üretir, şema zaten saf ASCII'dir.
     */
    private function assertUrlSchemeIsAllowed(string $url, string $context): void
    {
        $cleaned = preg_replace('/[\x00-\x20\x7F]/', '', $url) ?? '';
        $cleaned = strtolower($cleaned);

        $colon = strpos($cleaned, ':');

        if ($colon === false) {
            return; // Göreli URL — şema yok, `javascript:` de olamaz.
        }

        $beforeColon = substr($cleaned, 0, $colon);

        // `/`, `?` veya `#` iki nokta üst üsteden ÖNCE geliyorsa bu bir şema
        // değil, yol/sorgu içindeki bir karakterdir (ör. `/a:b`).
        if (preg_match('/[\/?#]/', $beforeColon) === 1) {
            return;
        }

        $this->assertContains(
            $beforeColon,
            HtmlSanitizer::ALLOWED_SCHEMES,
            "İzinsiz URL şeması `$beforeColon:` çıktıda kalmış: $context"
        );
    }
}
