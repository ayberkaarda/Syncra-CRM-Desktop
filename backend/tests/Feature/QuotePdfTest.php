<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Quotes\QuotePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Smalot\PdfParser\Parser as PdfTextParser;
use Tests\TestCase;

/**
 * Faz 9/C — teklif PDF çıktısı. Faz 14 / İz D+E (docs/PHASE-INTL.md §2.7):
 * statik etiketler artık `__('pdf.*')` ile basılır (bkz. blade dosyasının
 * başındaki "PDF HANGİ DİLDE BASILIR" kararı) ve `sent` tekliflerde donmuş
 * kur satırı eklenir.
 *
 * En kritik test bu dosyadaki `test_turkish_characters_and_lira_symbol_survive_pdf_roundtrip`
 * testidir: dompdf'in varsayılan font eşlemesi çekirdek PDF fontlarına
 * (Helvetica/Times) düşerse Türkçe glyph'ler boş kutu/yanlış karakter olarak
 * çıkar ve bunu HİÇBİR birim test yakalamaz — yalnızca üretilen PDF'ten metni
 * GERİ OKUYUP karşılaştırmak yakalar. Bu test o yüzden kalıcıdır, aşama
 * kapısının tek seferlik kanıtı değildir.
 *
 * Bu dosyanın MEVCUT testleri (Faz 9'dan) PDF'in TÜRKÇE basıldığını
 * varsayıyordu (sabit Türkçe etiketler). `config('app.locale')` test
 * ortamında `en`'dir (bkz. .env `APP_LOCALE=en`) ve bu servis doğrudan
 * çağrıldığında (HTTP isteği/`SetLocale` middleware'i olmadan) `App::
 * getLocale()` o varsayılana düşer — bu yüzden mevcut testler `setUp()`'ta
 * açıkça `tr`'ye sabitlenir (gerçek akışta bu, indiren kullanıcının
 * `users.locale='tr'` olması durumunun bire bir karşılığıdır). DE/FR
 * doğrulaması ve kur satırı için ayrı testler locale'i kendileri değiştirir.
 */
class QuotePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('tr');

        // Logo header alanı QuotePdfService içinde SÜREÇ BAŞINA (process-wide
        // static) memoize edilir — bkz. QuotePdfService::logoDataUri()
        // docblock'u. Testler arasında bu önbellek sızarsa (ör. bu dosyadan
        // önce çalışan başka bir test zaten render() çağırıp gerçek logoyu
        // önbelleğe almışsa) "varlık yokken çökmüyor" testi yanlışlıkla
        // önbellekten gelen eski sonucu görür. Her testten önce zorla
        // sıfırlanır ki her test kendi dosya-sistemi durumunu görsün.
        $this->resetLogoDataUriCache();
    }

    /**
     * `QuotePdfService::$logoDataUriResolved` / `$logoDataUriCache` private
     * static alanlarını Reflection ile sıfırlar. Servis KASITLI olarak
     * process başına bir kez okuyup önbelleğe alıyor (bkz. servis
     * docblock'u); bu, testin dosyayı geçici olarak taşıyıp geri koyarken
     * her seferinde gerçek dosya sistemi durumunu görmesini SADECE
     * reflection ile mümkün kılar — üretim kodunda test-özel bir "reset"
     * API'si açmak istenmedi.
     */
    private function resetLogoDataUriCache(): void
    {
        $ref = new \ReflectionClass(QuotePdfService::class);

        $resolved = $ref->getProperty('logoDataUriResolved');
        $resolved->setAccessible(true);
        $resolved->setValue(null, false);

        $cache = $ref->getProperty('logoDataUriCache');
        $cache->setAccessible(true);
        $cache->setValue(null, null);
    }

    private function makeQuoteWithItems(int $itemCount = 3, array $quoteOverrides = []): Quote
    {
        $creator = User::factory()->create(['name' => 'Ayşe Yılmaz']);
        $company = Company::factory()->create([
            'name' => 'Işık Teknoloji A.Ş.',
            'address' => 'Maslak, İstanbul',
        ]);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Şükrü',
            'last_name' => 'Öztürk',
        ]);

        $quote = Quote::factory()->create(array_merge([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'created_by' => $creator->id,
        ], $quoteOverrides));

        $subtotal = 0;
        $tax = 0;

        for ($i = 0; $i < $itemCount; $i++) {
            $item = QuoteItem::factory()->create([
                'quote_id' => $quote->id,
                'position' => $i,
            ]);
            $subtotal += (float) $item->line_total;
            $tax += (float) $item->line_total * ((float) $item->tax_rate / 100);
        }

        $quote->update([
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_amount' => round($tax, 2),
            'total' => round($subtotal + $tax, 2),
        ]);

        return $quote->fresh();
    }

    private function extractText(string $pdfBytes): string
    {
        $parser = new PdfTextParser;
        $document = $parser->parseContent($pdfBytes);

        return $document->getText();
    }

    // -----------------------------------------------------------------
    // Aşama kapısı — kalıcı regresyon testi
    // -----------------------------------------------------------------

    public function test_turkish_characters_and_lira_symbol_survive_pdf_roundtrip(): void
    {
        $sample = "ĞÜŞİÖÇ ğüşiöç — Işık ılık, İstanbul'da şşş. Fiyat: 1.234,56 ₺";

        $quote = $this->makeQuoteWithItems(2, [
            'title' => $sample,
            'notes' => $sample,
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);

        $this->assertStringStartsWith('%PDF-', $pdf);

        $text = $this->extractText($pdf);
        $normalize = fn (string $s) => preg_replace('/\s+/u', ' ', trim($s));

        foreach (['Ğ', 'Ü', 'Ş', 'İ', 'Ö', 'Ç', 'ğ', 'ü', 'ş', 'ö', 'ç', 'ı', '₺'] as $char) {
            $this->assertStringContainsString($char, $text, "Beklenen karakter PDF metninde bulunamadı: {$char}");
        }

        $this->assertStringContainsString($normalize($sample), $normalize($text));
    }

    // -----------------------------------------------------------------
    // Temel üretim
    // -----------------------------------------------------------------

    public function test_generates_non_empty_valid_pdf_for_real_quote(): void
    {
        $quote = $this->makeQuoteWithItems(4);

        $pdf = app(QuotePdfService::class)->render($quote);

        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_output_contains_item_table_totals_and_company_info(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'company.name'],
            ['value' => 'Syncra Test Şirketi', 'type' => 'string', 'group' => 'company']
        );
        Setting::query()->updateOrCreate(
            ['key' => 'company.tax_number'],
            ['value' => '9998887776', 'type' => 'string', 'group' => 'company']
        );

        $quote = $this->makeQuoteWithItems(3);
        $quote->items->first()->update(['name' => 'Benzersiz Kalem Adı XYZ']);

        $pdf = app(QuotePdfService::class)->render($quote->fresh(['items']));
        $text = $this->extractText($pdf);

        $this->assertStringContainsString('Syncra Test Şirketi', $text);
        $this->assertStringContainsString('9998887776', $text);
        $this->assertStringContainsString($quote->quote_number, $text);
        $this->assertStringContainsString('Benzersiz Kalem Adı XYZ', $text);
        $this->assertStringContainsString('GENEL TOPLAM', $text);

        $money = fn ($v) => number_format((float) $v, 2, ',', '.');
        $this->assertStringContainsString($money($quote->fresh()->total), $text);
    }

    // -----------------------------------------------------------------
    // Logo — üst bilgide marka varlığı (bkz. QuotePdfService::logoDataUri())
    // -----------------------------------------------------------------

    public function test_header_logo_is_embedded_as_an_image_xobject(): void
    {
        $quote = $this->makeQuoteWithItems(1);

        $pdf = app(QuotePdfService::class)->render($quote);

        $this->assertStringStartsWith('%PDF-', $pdf);

        // "Şablonda <img> var" demekle yetinmiyoruz: dompdf'in gerçekten bir
        // görsel XObject yazdığını PDF nesne sözlüğünde arıyoruz.
        $this->assertMatchesRegularExpression(
            '/\/Subtype\s*\/Image/',
            $pdf,
            'Üretilen PDF baytlarında bir /Subtype /Image XObject bulunamadı.'
        );
    }

    public function test_old_logo_placeholder_text_no_longer_appears(): void
    {
        $quote = $this->makeQuoteWithItems(1);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);

        $this->assertStringNotContainsString('LOGO', $text);
    }

    public function test_missing_logo_asset_does_not_break_pdf_generation(): void
    {
        $path = resource_path('images/logo-mark.png');
        $backupPath = $path.'.test-backup';

        $this->assertFileExists($path, 'Logo varlığı beklenen konumda bulunamadı: '.$path);
        $this->assertFileDoesNotExist($backupPath, 'Önceki bir test çalıştırması yedeği temizlemeden kalmış olabilir.');

        rename($path, $backupPath);
        $this->resetLogoDataUriCache();

        try {
            $quote = $this->makeQuoteWithItems(1);

            $pdf = app(QuotePdfService::class)->render($quote);

            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertDoesNotMatchRegularExpression('/\/Subtype\s*\/Image/', $pdf);

            $text = $this->extractText($pdf);
            $this->assertStringNotContainsString('LOGO', $text);
        } finally {
            rename($backupPath, $path);
            $this->resetLogoDataUriCache();
        }
    }

    // -----------------------------------------------------------------
    // Sayfalama
    // -----------------------------------------------------------------

    public function test_large_quote_with_sixty_items_produces_multi_page_pdf_without_error(): void
    {
        $quote = $this->makeQuoteWithItems(60);

        $pdf = app(QuotePdfService::class)->render($quote);

        $this->assertStringStartsWith('%PDF-', $pdf);

        // Birden fazla sayfa üretildiğini dolaylı olarak doğrula: dompdf her
        // sayfa için ayrı bir /Type /Page nesnesi yazar.
        $pageObjectCount = preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
        $this->assertGreaterThan(1, $pageObjectCount, 'PDF tek sayfaya sıkışmış görünüyor.');

        $text = $this->extractText($pdf);
        $this->assertStringContainsString('GENEL TOPLAM', $text);
    }

    // -----------------------------------------------------------------
    // Güvenlik
    // -----------------------------------------------------------------

    public function test_html_in_notes_is_escaped_not_rendered(): void
    {
        $quote = $this->makeQuoteWithItems(1, [
            'notes' => '<script>alert(1)</script><b>kalın değil</b>',
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);

        // Etiketler literal metin olarak görünmeli (escape edildiği için),
        // gerçek bir HTML elemanı olarak yorumlanıp KAYBOLMAMALI.
        $this->assertStringContainsString('alert(1)', $text);
        $this->assertStringContainsString('kalın değil', $text);
    }

    public function test_remote_image_in_notes_does_not_break_generation_or_fetch_remote(): void
    {
        $this->assertFalse(
            config('dompdf.options.enable_remote'),
            'isRemoteEnabled varsayılan olarak false olmalı (config/dompdf.php).'
        );

        $quote = $this->makeQuoteWithItems(1, [
            'notes' => 'Bakınız: <img src="http://169.254.169.254/latest/meta-data/" width="10" height="10"> son.',
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);

        $this->assertStringStartsWith('%PDF-', $pdf);

        $text = $this->extractText($pdf);
        // <img> etiketi escape edildiği için literal metin olarak görünür,
        // bir görsel olarak yüklenmeye ÇALIŞILMAZ (uzak istek atılmaz).
        $this->assertStringContainsString('img src=', $text);
    }

    // -----------------------------------------------------------------
    // Dosya adı
    // -----------------------------------------------------------------

    public function test_filename_uses_quote_number(): void
    {
        $quote = $this->makeQuoteWithItems(1, ['quote_number' => 'QTE-000042']);

        $this->assertSame('teklif-QTE-000042.pdf', app(QuotePdfService::class)->filename($quote));
    }

    // -----------------------------------------------------------------
    // Kur satırı — Faz 14 / İz E (docs/PHASE-INTL.md §2.3/§2.6)
    // -----------------------------------------------------------------

    public function test_a_sent_foreign_currency_quote_prints_the_frozen_exchange_rate_and_date(): void
    {
        $quote = $this->makeQuoteWithItems(1, [
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => '34.123400',
            'exchange_rate_date' => '2026-08-24',
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);

        $this->assertStringContainsString('1 USD = 34,1234 TRY (24.08.2026)', $text);
    }

    public function test_a_draft_quote_never_prints_an_exchange_rate_line(): void
    {
        $quote = $this->makeQuoteWithItems(1, [
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => null,
            'exchange_rate_date' => null,
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);

        $this->assertStringNotContainsString('TRY (', $text);
    }

    public function test_a_sent_try_quote_never_prints_an_exchange_rate_line(): void
    {
        // TRY kendi kendisine kur=1 taşısa bile (freezesExchangeRate mantığı)
        // satır anlamsızdır ve BASILMAZ.
        $quote = $this->makeQuoteWithItems(1, [
            'status' => 'sent',
            'currency' => 'TRY',
            'exchange_rate' => '1.000000',
            'exchange_rate_date' => '2026-08-24',
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);

        $this->assertStringNotContainsString('TRY (', $text);
    }

    // -----------------------------------------------------------------
    // DE/FR — aksan + para simgesi round-trip (docs/PHASE-INTL.md §2.7)
    // -----------------------------------------------------------------

    public function test_german_locale_prints_translated_labels_and_accented_characters_survive_roundtrip(): void
    {
        App::setLocale('de');

        $sample = 'Änderung, Größe, Straße — Übergabe für Kunden Müller & Söhne, ärgerlich äöüß';

        $quote = $this->makeQuoteWithItems(1, [
            'status' => 'sent',
            'currency' => 'EUR',
            'exchange_rate' => '36.500000',
            'exchange_rate_date' => '2026-08-24',
            'title' => $sample,
            'notes' => $sample,
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);
        $normalize = fn (string $s) => preg_replace('/\s+/u', ' ', trim($s));

        foreach (['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü', '€'] as $char) {
            $this->assertStringContainsString($char, $text, "Beklenen karakter DE PDF metninde bulunamadı: {$char}");
        }

        $this->assertStringContainsString($normalize($sample), $normalize($text));
        $this->assertStringContainsString('Angebot', $text);
        $this->assertStringContainsString('Kundeninformationen', $text);
        $this->assertStringContainsString('GESAMTSUMME', $text);
        $this->assertStringContainsString('MwSt.', $text);
        $this->assertStringContainsString('1 EUR = 36,5000 TRY (24.08.2026)', $text);
    }

    public function test_french_locale_prints_translated_labels_and_accented_characters_survive_roundtrip(): void
    {
        App::setLocale('fr');

        $sample = 'Créé pour le client à Genève : intérêt général, œuvre commune, numéro élevé';

        $quote = $this->makeQuoteWithItems(1, [
            'status' => 'sent',
            'currency' => 'EUR',
            'exchange_rate' => '36.500000',
            'exchange_rate_date' => '2026-08-24',
            'title' => $sample,
            'notes' => $sample,
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);
        $normalize = fn (string $s) => preg_replace('/\s+/u', ' ', trim($s));

        foreach (['é', 'è', 'ê', 'à', 'ç', 'œ', '€'] as $char) {
            $this->assertStringContainsString($char, $text, "Beklenen karakter FR PDF metninde bulunamadı: {$char}");
        }

        $this->assertStringContainsString($normalize($sample), $normalize($text));
        $this->assertStringContainsString('Devis', $text);
        $this->assertStringContainsString('Informations client', $text);
        $this->assertStringContainsString('TOTAL GÉNÉRAL', $text);
        $this->assertStringContainsString('TVA', $text);
        $this->assertStringContainsString('1 EUR = 36,5000 TRY (24.08.2026)', $text);
    }

    // -----------------------------------------------------------------
    // Locale-aware tutar biçimlendirme — Faz 14 kabul kriteri (docs/PHASE-INTL.md
    // §1.8/§2.7): ayraç/gruplama VE para simgesinin KONUMU dile bağlıdır. Beklenen
    // değerler frontend/src/lib/money.ts'in ölçülmüş `narrowSymbol` çıktısıyla
    // birebir eşleşir (görev tanımındaki tablo).
    // -----------------------------------------------------------------

    public function test_amounts_use_locale_correct_separators_and_symbol_position(): void
    {
        // Aynı 1.234,56 TRY tutarı dört locale'de dört farklı ayraç/gruplama
        // VE simge konumuyla basılmalı — tr/en simge önde boşluksuz, de/fr
        // simge arkada (NBSP ile); fr'de binlik ayraç dar boşluktur (U+202F).
        $expectations = [
            'tr' => "\u{20BA}1.234,56",
            'en' => "\u{20BA}1,234.56",
            'de' => "1.234,56\u{00A0}\u{20BA}",
            'fr' => "1\u{202F}234,56\u{00A0}\u{20BA}",
        ];

        foreach ($expectations as $locale => $expected) {
            App::setLocale($locale);

            $quote = $this->makeQuoteWithItems(1, ['currency' => 'TRY']);
            $quote->update([
                'subtotal' => '1234.56',
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'total' => '1234.56',
            ]);

            $pdf = app(QuotePdfService::class)->render($quote->fresh(['items']));
            $text = $this->extractText($pdf);

            $this->assertStringContainsString(
                $expected,
                $text,
                "Locale '{$locale}' için beklenen tutar biçimi ('GENEL TOPLAM'/'Total' satırı) bulunamadı."
            );
        }
    }

    public function test_english_locale_uses_period_decimal_separator_in_exchange_rate_line(): void
    {
        // Faz 14 öncesi hatanın tam tersi: `en` teklifinde kur satırı virgülle
        // (`34,1234`) değil, noktayla (`34.1234`) basılmalı. Tarih biçimi
        // (`24.08.2026`) BİLEREK dilden bağımsız kalır (§2.4/§2.6, bkz. blade
        // içindeki yorum) — bu yüzden yalnız ondalık ayracı değişir.
        App::setLocale('en');

        $quote = $this->makeQuoteWithItems(1, [
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => '34.123400',
            'exchange_rate_date' => '2026-08-24',
        ]);

        $pdf = app(QuotePdfService::class)->render($quote);
        $text = $this->extractText($pdf);

        $this->assertStringContainsString('1 USD = 34.1234 TRY (24.08.2026)', $text);
    }

    public function test_english_locale_prints_thousands_comma_in_grand_total(): void
    {
        App::setLocale('en');

        $quote = $this->makeQuoteWithItems(1, ['currency' => 'USD']);
        $quote->update([
            'subtotal' => '12345.67',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '12345.67',
        ]);

        $pdf = app(QuotePdfService::class)->render($quote->fresh(['items']));
        $text = $this->extractText($pdf);

        $this->assertStringContainsString('$12,345.67', $text);
        $this->assertStringNotContainsString('12.345,67', $text);
    }
}
