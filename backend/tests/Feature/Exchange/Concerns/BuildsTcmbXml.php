<?php

namespace Tests\Feature\Exchange\Concerns;

/**
 * Gerçek TCMB `today.xml` yapısını taklit eden küçük XML üretici — tüm
 * Exchange test dosyaları arasında paylaşılır ki her test kendi elle
 * yazılmış XML string'ini tekrarlamasın (drift riski).
 */
trait BuildsTcmbXml
{
    /**
     * @param  array<string, array{unit?: int, forexBuying?: string|null}>  $currencies  Kod => alanlar
     */
    protected function buildTcmbXml(
        string $tarihDdMmYyyy,
        string $dateMmDdYyyy,
        array $currencies,
        ?string $doctypeInternalSubset = null,
    ): string {
        $currencyXml = '';

        foreach ($currencies as $code => $data) {
            $unit = $data['unit'] ?? 1;
            $forexBuying = array_key_exists('forexBuying', $data) ? $data['forexBuying'] : '30.000000';

            $currencyXml .= <<<XML

  <Currency CrossOrder="0" Kod="{$code}" CurrencyCode="{$code}">
    <Unit>{$unit}</Unit>
    <Isim>Test Para Birimi</Isim>
    <CurrencyName>Test Currency</CurrencyName>
    <ForexBuying>{$forexBuying}</ForexBuying>
    <ForexSelling>0</ForexSelling>
    <BanknoteBuying>0</BanknoteBuying>
    <BanknoteSelling>0</BanknoteSelling>
    <CrossRateUSD></CrossRateUSD>
    <CrossRateOther></CrossRateOther>
  </Currency>
XML;
        }

        $doctype = $doctypeInternalSubset !== null
            ? "<!DOCTYPE Tarih_Date [\n{$doctypeInternalSubset}\n]>\n"
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
{$doctype}<Tarih_Date Tarih="{$tarihDdMmYyyy}" Date="{$dateMmDdYyyy}" Bulten_No="2026/158">{$currencyXml}
</Tarih_Date>
XML;
    }
}
