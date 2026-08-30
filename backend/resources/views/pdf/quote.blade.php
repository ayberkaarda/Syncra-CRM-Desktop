{{--
    Teklif PDF şablonu (dompdf / barryvdh-laravel-dompdf).

    ÖNEMLİ — GÜVENLİK: Kullanıcı girdisi olabilecek her alan ({{ }} ile,
    ASLA {!! !!} ile değil) escape edilerek basılır: $quote->notes,
    $quote->terms, kalem name/description. Aksi halde bir teklif notuna
    gömülen HTML/script PDF üretimini bozabilir veya beklenmedik içerik
    üretebilir.

    Dompdf harici CSS/JS yüklemez; tüm stil bu dosyada <style> içinde satır
    içi tanımlıdır. Tasarım kasıtlı olarak sade/siyah-beyaz tutulmuştur —
    PDF, uygulamanın ekran tasarım sisteminden ayrı, yazdırılabilir bir
    mecradır.

    Font: 'DejaVu Sans' (dompdf'e gömülü). Türkçe karakterler (ı İ ş Ş ğ Ğ
    ü Ü ö Ö ç Ç), Almanca/Fransızca aksanlar (ä ö ü ß é è ê ë à â û ç œ …) ve
    ₺ € $ £ simgeleri bu fontla doğrulanmıştır — bkz.
    QuotePdfTest::test_turkish_characters_and_lira_symbol_survive_pdf_roundtrip
    ve DE/FR round-trip testleri. FONT DEĞİŞTİRİLMEZ (docs/PHASE-INTL.md §2.7).

    -----------------------------------------------------------------------
    PDF HANGİ DİLDE BASILIR (Faz 14 / İz D + İz E kararı, docs/PHASE-INTL.md
    §2.7): talebi yapan/indiren kullanıcının O ANDAKİ `App::getLocale()`'i —
    yani `SetLocale` middleware'inin `GET /api/quotes/{quote}/pdf` isteğinde
    `$request->user()->locale`'den zaten kurduğu dil. Statik etiketler
    `lang/{tr,en,de,fr}/pdf.php`'den `__('pdf.*')` ile gelir.
    GEREKÇE: teklif müşteriye giden bir BELGE olsa da, PDF'i ÜRETEN/GÖNDEREN
    kullanıcı içeriğin geri kalanını (başlık, notlar, şartlar) zaten KENDİ
    dilinde serbestçe yazmıştır — bu metinler `settings.quote.terms` gibi
    kullanıcı verisi, hiçbir zaman çevrilmez (§1.5). Statik iskeleti
    (Müşteri Bilgileri, KDV, Ara Toplam...) o kullanıcının arayüz diliyle
    tutarlı tutmak, "arayüz Almanca ama indirdiği belge hep Türkçe" tutarsız
    deneyiminden kaçınır — kullanıcı zaten hangi dilde çalıştığını seçmiştir
    (`users.locale`) ve teklifi genelde KENDİ şirketi için (arşiv, iç onay)
    veya müşterisiyle aynı dilde çalıştığı bir bağlamda indirir. Reddedilen
    alternatif: teklifin `deal`/`contact` diline göre basmak — ne `deals`
    ne `contacts` şemasında bir dil alanı var, böyle bir alan eklemek bu
    fazın kapsamını patlatırdı ve gerçek ihtiyacı karşılayan bir sinyal
    değil (bir contact'ın hangi dilde yazışıldığı onun "dili" değildir).
--}}
@php
    /** @var \App\Models\Quote $quote */
    $billTo = $quote->company ?? $quote->deal?->company;
    $contact = $quote->contact;

    $statusLabels = __('pdf.status');
    $statusLabel = $statusLabels[$quote->status] ?? ucfirst($quote->status);

    // Faz 14 / İz D+E (docs/PHASE-INTL.md §1.8/§2.7): ayraç/gruplama VE para
    // simgesinin KONUMU indiren kullanıcının arayüz diline göre değişir — bkz.
    // App\Support\LocaleNumberFormatter docblock'u (frontend `money.ts` ile
    // ölçülüp eşleştirilmiş tablo). Tarih biçimi (`d.m.Y`) BİLEREK dilden
    // BAĞIMSIZ bırakıldı: docs/PHASE-INTL.md §2.4/§2.6 kur/rapor tarihlerini
    // her yerde sabit "dd.mm.yyyy" olarak tanımlıyor (rapor dipnotu örneği,
    // bayatlık etiketi) — bu PDF'te de aynı disiplin korunur.
    $appLocale = app()->getLocale();
    $money = fn ($value) => \App\Support\LocaleNumberFormatter::number($value, 2, $appLocale);
    $qty = fn ($value) => \App\Support\LocaleNumberFormatter::number($value, 2, $appLocale);
    $rateFormat = fn ($value) => \App\Support\LocaleNumberFormatter::number($value, 4, $appLocale);
    $moneyWithSymbol = fn ($value) => \App\Support\LocaleNumberFormatter::money($value, $currencySymbol, $appLocale);

    // §2.3/§2.6: yalnız `sent` (ve sonrası) tekliflerde `exchange_rate` dolu
    // olur; taslakta null → satır basılmaz. Para birimi temel (TRY) ise kur
    // 1'dir ve satır anlamsızdır → yine basılmaz.
    $showExchangeRate = $quote->exchange_rate !== null
        && strtoupper($quote->currency) !== strtoupper($baseCurrency);
@endphp
<html>
<head>
<meta charset="utf-8">
<style>
    @page {
        margin: 90px 40px 60px 40px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 10px;
        color: #1a1a1a;
        line-height: 1.4;
    }

    h1, h2, h3 {
        margin: 0;
        padding: 0;
        font-weight: bold;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    /* ------------------------------------------------------------------
     * Üst bilgi (şirket + teklif no) — dompdf'in "running header" desteği
     * kısıtlı olduğundan sabit konumlu bir div ile her sayfada tekrarlanır.
     * ---------------------------------------------------------------- */
    #page-header {
        position: fixed;
        top: -70px;
        left: 0;
        right: 0;
        height: 60px;
        border-bottom: 1.5px solid #1a1a1a;
        padding-bottom: 8px;
    }

    #page-header .company-name {
        font-size: 15px;
        font-weight: bold;
    }

    #page-header .company-meta {
        font-size: 8px;
        color: #444;
        margin-top: 2px;
    }

    #page-header .logo-slot {
        /* Logo dosyası yok (Faz 10 Ayarlar ekranında yüklenebilir hale
           gelecek). Şimdilik yer tutucu bırakıldı, gerçek bir logo
           <img> değil — kutu yalnızca marka alanını göstermek içindir. */
        width: 70px;
        height: 40px;
        border: 1px solid #999;
        color: #999;
        font-size: 7px;
        text-align: center;
        vertical-align: middle;
    }

    #page-footer {
        position: fixed;
        bottom: -50px;
        left: 0;
        right: 0;
        height: 30px;
        border-top: 0.75px solid #999;
        padding-top: 6px;
        font-size: 7px;
        color: #777;
        text-align: center;
    }

    /* Sayfa numarası QuotePdfService::addPageNumbers() tarafından
       Canvas::page_text() ile basılır (bkz. servis içi güvenlik notu:
       dompdf'in embedded PHP script mekanizması KULLANILMAZ). */

    .section-title {
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #555;
        border-bottom: 0.75px solid #ccc;
        padding-bottom: 3px;
        margin-bottom: 6px;
    }

    #meta-block {
        width: 100%;
        margin-top: 10px;
        margin-bottom: 16px;
    }

    #meta-block td {
        vertical-align: top;
        width: 50%;
        padding: 0;
    }

    #meta-block .box {
        border: 0.75px solid #ccc;
        padding: 8px 10px;
        margin-right: 8px;
    }

    #meta-block .box.last {
        margin-right: 0;
    }

    #meta-block .row {
        margin-bottom: 2px;
    }

    #meta-block .label {
        color: #666;
        display: inline;
    }

    .quote-title {
        font-size: 13px;
        margin-bottom: 2px;
    }

    .status-badge {
        display: inline;
        border: 0.75px solid #1a1a1a;
        padding: 1px 6px;
        font-size: 8px;
        font-weight: bold;
    }

    /* ------------------------------------------------------------------
     * Kalem tablosu — <thead> her sayfada tekrar eder (dompdf yerleşik
     * davranışı). 60+ kalemlik tekliflerde çok sayfaya bölünür.
     * ---------------------------------------------------------------- */
    #items-table {
        margin-top: 4px;
    }

    #items-table thead th {
        background-color: #eeeeee;
        border: 0.75px solid #999;
        padding: 5px 6px;
        font-size: 8.5px;
        text-align: left;
        font-weight: bold;
    }

    #items-table tbody td {
        border: 0.75px solid #ccc;
        padding: 5px 6px;
        font-size: 9px;
        vertical-align: top;
    }

    #items-table .col-no {
        width: 4%;
        text-align: center;
    }

    #items-table .col-desc {
        width: 38%;
    }

    #items-table .col-desc .item-desc {
        color: #555;
        font-size: 8px;
        margin-top: 2px;
    }

    #items-table .col-qty {
        width: 12%;
        text-align: right;
    }

    #items-table .col-price {
        width: 15%;
        text-align: right;
    }

    #items-table .col-discount {
        width: 9%;
        text-align: right;
    }

    #items-table .col-tax {
        width: 8%;
        text-align: right;
    }

    #items-table .col-total {
        width: 14%;
        text-align: right;
        font-weight: bold;
    }

    /* ------------------------------------------------------------------
     * Toplamlar — sayfa ortasında bölünmesin.
     * ---------------------------------------------------------------- */
    #totals-wrapper {
        page-break-inside: avoid;
        margin-top: 10px;
    }

    #totals-table {
        width: 45%;
        margin-left: 55%;
    }

    #totals-table td {
        padding: 4px 6px;
        font-size: 9.5px;
    }

    #totals-table .totals-label {
        text-align: left;
        color: #444;
    }

    #totals-table .totals-value {
        text-align: right;
    }

    #totals-table .grand-total-row td {
        border-top: 1.5px solid #1a1a1a;
        font-size: 12px;
        font-weight: bold;
        padding-top: 7px;
    }

    #notes-block {
        page-break-inside: avoid;
        margin-top: 18px;
    }

    #notes-block .block {
        margin-bottom: 12px;
    }

    #notes-block .text {
        font-size: 9px;
        color: #333;
        white-space: pre-line;
        border: 0.75px solid #ddd;
        padding: 8px 10px;
    }
</style>
</head>
<body>

<div id="page-header">
    <table>
        <tr>
            <td style="width: 78%;">
                <div class="company-name">{{ $companyInfo['name'] }}</div>
                <div class="company-meta">
                    {{ $companyInfo['address'] }}
                    @if($companyInfo['tax_number']) &middot; VKN: {{ $companyInfo['tax_number'] }} @endif
                    @if($companyInfo['phone']) &middot; {{ $companyInfo['phone'] }} @endif
                    @if($companyInfo['email']) &middot; {{ $companyInfo['email'] }} @endif
                </div>
            </td>
            <td style="width: 22%; text-align: right;">
                {{-- Logo alanı: Faz 10'da Ayarlar ekranından yüklenebilir hale gelecek. --}}
                <div class="logo-slot">LOGO</div>
            </td>
        </tr>
    </table>
</div>

<div id="page-footer">
    {{ $companyInfo['name'] }} &middot; {{ $companyInfo['tax_number'] ? 'VKN: '.$companyInfo['tax_number'] : '' }}
</div>

<table id="meta-block">
    <tr>
        <td>
            <div class="box">
                <div class="quote-title">{{ __('pdf.quote_label') }} {{ $quote->quote_number }}</div>
                <div class="row"><span class="label">{{ __('pdf.title_label') }}:</span> {{ $quote->title }}</div>
                <div class="row"><span class="label">{{ __('pdf.date_label') }}:</span> {{ optional($quote->created_at)->format('d.m.Y') }}</div>
                <div class="row"><span class="label">{{ __('pdf.validity_label') }}:</span> {{ optional($quote->valid_until)->format('d.m.Y') ?? '-' }}</div>
                <div class="row"><span class="label">{{ __('pdf.status_label') }}:</span> <span class="status-badge">{{ $statusLabel }}</span></div>
                @if($showExchangeRate)
                    <div class="row">{{ __('pdf.exchange_rate_line', [
                        'currency' => strtoupper($quote->currency),
                        'rate' => $rateFormat($quote->exchange_rate),
                        'base' => strtoupper($baseCurrency),
                        'date' => $quote->exchange_rate_date->format('d.m.Y'),
                    ]) }}</div>
                @endif
            </div>
        </td>
        <td>
            <div class="box last">
                <div class="quote-title" style="font-size: 11px;">{{ __('pdf.customer_info') }}</div>
                <div class="row"><strong>{{ $billTo->name ?? '-' }}</strong></div>
                @if($contact)
                    <div class="row">{{ $contact->full_name }}@if($contact->position), {{ $contact->position }}@endif</div>
                @endif
                @if($billTo?->address)
                    <div class="row">{{ $billTo->address }}@if($billTo->city), {{ $billTo->city }}@endif</div>
                @endif
                @if($contact?->phone ?? $billTo?->phone)
                    <div class="row"><span class="label">{{ __('pdf.phone_label') }}:</span> {{ $contact->phone ?? $billTo->phone }}</div>
                @endif
                @if($contact?->email ?? $billTo?->email)
                    <div class="row"><span class="label">{{ __('pdf.email_label') }}:</span> {{ $contact->email ?? $billTo->email }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

<div class="section-title">{{ __('pdf.items_section') }}</div>

<table id="items-table">
    <thead>
        <tr>
            <th class="col-no">{{ __('pdf.col_no') }}</th>
            <th class="col-desc">{{ __('pdf.col_description') }}</th>
            <th class="col-qty">{{ __('pdf.col_quantity') }}</th>
            <th class="col-price">{{ __('pdf.col_unit_price') }}</th>
            <th class="col-discount">{{ __('pdf.col_discount') }}</th>
            <th class="col-tax">{{ __('pdf.col_tax') }}</th>
            <th class="col-total">{{ __('pdf.col_amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($quote->items as $index => $item)
            <tr>
                <td class="col-no">{{ $index + 1 }}</td>
                <td class="col-desc">
                    <div>{{ $item->name }}</div>
                    @if($item->description)
                        <div class="item-desc">{{ $item->description }}</div>
                    @endif
                </td>
                <td class="col-qty">{{ $qty($item->quantity) }} {{ __('pdf.unit_piece') }}</td>
                <td class="col-price">{{ $moneyWithSymbol($item->unit_price) }}</td>
                <td class="col-discount">{{ $money($item->discount_percent) }}%</td>
                <td class="col-tax">{{ $money($item->tax_rate) }}%</td>
                <td class="col-total">{{ $moneyWithSymbol($item->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div id="totals-wrapper">
    <table id="totals-table">
        <tr>
            <td class="totals-label">{{ __('pdf.subtotal') }}</td>
            <td class="totals-value">{{ $moneyWithSymbol($quote->subtotal) }}</td>
        </tr>
        <tr>
            <td class="totals-label">{{ __('pdf.discount') }}</td>
            <td class="totals-value">-{{ $moneyWithSymbol($quote->discount_amount) }}</td>
        </tr>
        <tr>
            <td class="totals-label">{{ __('pdf.tax') }}</td>
            <td class="totals-value">{{ $moneyWithSymbol($quote->tax_amount) }}</td>
        </tr>
        <tr class="grand-total-row">
            <td class="totals-label">{{ __('pdf.grand_total') }}</td>
            <td class="totals-value">{{ $moneyWithSymbol($quote->total) }}</td>
        </tr>
    </table>
</div>

<div id="notes-block">
    @if($quote->notes)
        <div class="block">
            <div class="section-title">{{ __('pdf.notes_section') }}</div>
            <div class="text">{{ $quote->notes }}</div>
        </div>
    @endif
    @if($quote->terms)
        <div class="block">
            <div class="section-title">{{ __('pdf.terms_section') }}</div>
            <div class="text">{{ $quote->terms }}</div>
        </div>
    @endif
</div>

</body>
</html>
