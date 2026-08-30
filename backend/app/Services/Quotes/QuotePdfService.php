<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\Setting;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Illuminate\Contracts\Container\Container;

/**
 * Teklifi kurumsal, yazdırılabilir bir PDF belgesine dönüştürür.
 *
 * Şablon: resources/views/pdf/quote.blade.php — dompdf ile render edilir
 * (barryvdh/laravel-dompdf). Türkçe karakterler ve ₺ simgesi için
 * `config/dompdf.php`'de varsayılan font 'DejaVu Sans'a ayarlanmıştır
 * (bkz. Faz 9/C aşama kapısı). `enable_remote` kapalıdır: teklif notuna
 * konulan bir `<img src="http://...">` sunucunun dışarı istek atmasına
 * neden OLMAZ (bkz. config/dompdf.php `options.enable_remote`).
 *
 * Bu sınıf PDF'i diske YAZMAZ; ham bayt dizisini döner. İndirme ucu
 * (route + controller) A/B şeritlerinin sorumluluğundadır:
 *
 *   GET /api/quotes/{quote}/pdf   QuoteController@pdf   quotes.view
 *
 * Örnek entegrasyon:
 *   $bytes = app(QuotePdfService::class)->render($quote);
 *   return response($bytes, 200, [
 *       'Content-Type' => 'application/pdf',
 *       'Content-Disposition' => 'attachment; filename="'.app(QuotePdfService::class)->filename($quote).'"',
 *   ]);
 */
class QuotePdfService
{
    public function __construct(private readonly Container $container) {}

    /**
     * Teklifin ham PDF çıktısını üretir (dosyaya yazmaz, akış olarak döner).
     *
     * Her çağrıda TAZE bir `dompdf.wrapper` (dolayısıyla taze bir alttaki
     * `Dompdf` nesnesi) container'dan çözülür — tek bir Dompdf örneği
     * `loadHtml()` ile iki kez kullanılmaya UYGUN DEĞİLDİR (frame tree/DOM
     * durumu sızar ve ikinci render `Call to a member function
     * get_content_box() on null` gibi bir çökmeyle sonuçlanır). Bu servis
     * bu yüzden bilerek STATELESS'tır: aynı `QuotePdfService` örneği
     * ardışık olarak birden çok teklif için (ör. toplu dışa aktarım
     * döngüsünde) güvenle çağrılabilir.
     */
    public function render(Quote $quote): string
    {
        // N+1 önle: kalemler + ilişkiler tek seferde yüklenir.
        $quote->loadMissing([
            'items' => fn ($query) => $query->orderBy('position'),
            'items.product',
            'company',
            'contact',
            'deal',
            'creator',
        ]);

        $data = [
            'quote' => $quote,
            'companyInfo' => $this->companyInfo(),
            'currencySymbol' => self::currencySymbol(
                $quote->currency ?: Setting::get('general.currency', 'TRY')
            ),
            // Faz 14 / İz E (docs/PHASE-INTL.md §2.3/§2.6): blade'in kur
            // satırını basıp basmayacağına karar verirken temel para birimini
            // (TRY) sabit yazmak yerine buradan alır.
            'baseCurrency' => config('exchange.base_currency', 'TRY'),
            'generatedAt' => now(),
        ];

        /** @var DomPdfWrapper $pdf */
        $pdf = $this->container->make('dompdf.wrapper');

        $pdf->loadView('pdf.quote', $data)
            ->setPaper('a4', 'portrait')
            // Uzak kaynak yükleme daima kapalı tutulur (config/dompdf.php'de de
            // false), burada ikinci kez zorlanır ki çağıran taraf yanlışlıkla
            // farklı bir seçenekle örnek oluşturursa bile sızma OLMASIN.
            ->setOption('isRemoteEnabled', false);

        // Sayfa numaralarını (dompdf'in embedded PHP script mekanizmasını
        // KULLANMADAN) canvas API'siyle basıyoruz. `enable_php` kasıtlı olarak
        // false bırakıldı (config/dompdf.php) — teklif notuna gömülen
        // <script type="text/php"> bloklarının çalıştırılması güvenlik
        // açığıdır. page_text() render SONRASI, output ÖNCESİ çağrılmalıdır.
        $pdf->render();
        $this->addPageNumbers($pdf);

        return $pdf->output();
    }

    /**
     * İndirme dosya adı — "teklif-QTE-000001.pdf".
     */
    public function filename(Quote $quote): string
    {
        return sprintf('teklif-%s.pdf', $quote->quote_number);
    }

    /**
     * Ayarlar tablosundan şirket profilini okur (Faz 10'da Ayarlar ekranından
     * düzenlenebilir hale gelecek). Logo dosyası henüz yok; şablonda yer
     * tutucu bırakılıp yorumla belirtilmiştir.
     *
     * @return array<string, string>
     */
    private function companyInfo(): array
    {
        return [
            'name' => (string) Setting::get('company.name', 'Şirket Adı'),
            'email' => (string) Setting::get('company.email', ''),
            'phone' => (string) Setting::get('company.phone', ''),
            'address' => (string) Setting::get('company.address', ''),
            'tax_number' => (string) Setting::get('company.tax_number', ''),
        ];
    }

    /**
     * Para birimi kodunu simgeye çevirir. DejaVu Sans ₺ glyph'ini kapsar
     * (Faz 9/C aşama kapısında round-trip testiyle doğrulandı); tanınmayan
     * kodlar için kod aynen gösterilir (sessizce boş kutu ÜRETMEZ).
     */
    private static function currencySymbol(string $code): string
    {
        return match (strtoupper($code)) {
            'TRY' => '₺',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($code),
        };
    }

    /**
     * "Sayfa {PAGE_NUM} / {PAGE_COUNT}" altbilgisini her sayfaya basar.
     * `enable_php` açmadan, dompdf'in düşük seviyeli Canvas::page_text()
     * API'siyle çalışır (bkz. render() içindeki güvenlik notu).
     */
    private function addPageNumbers(DomPdfWrapper $pdf): void
    {
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');

        // `{PAGE_NUM}`/`{PAGE_COUNT}` dompdf'in KENDİ belirteçleridir — Canvas
        // bunları render sırasında gerçek sayfa numaralarıyla değiştirir.
        // `__()`'e literal olarak geçirilir, Laravel'in `:page`/`:total`
        // yer tutucuları yalnızca ÇEVRESİNDEKİ metni (ör. "Sayfa"/"Page")
        // dile göre değiştirmek için kullanılır.
        $text = __('pdf.page_indicator', ['page' => '{PAGE_NUM}', 'total' => '{PAGE_COUNT}']);
        $size = 8;
        $width = $fontMetrics->getTextWidth($text, $font, $size);

        $x = $canvas->get_width() - $width - 40;
        $y = $canvas->get_height() - 30;

        $canvas->page_text($x, $y, $text, $font, $size, [0.4, 0.4, 0.4]);
    }
}
