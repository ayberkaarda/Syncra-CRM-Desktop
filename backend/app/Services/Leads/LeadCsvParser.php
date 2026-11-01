<?php

namespace App\Services\Leads;

use App\Http\Requests\Leads\StoreLeadRequest;
use Generator;

/**
 * CSV dosyasını satır satır (fgetcsv ile) okuyup lead alanlarına doğrulanmış
 * biçimde çevirir.
 *
 * ---------------------------------------------------------------------------
 * NEDEN fgetcsv, NEDEN file() / Storage::get() DEĞİL
 * ---------------------------------------------------------------------------
 * `ImportLeadsRequest` en fazla 5 MB'lık dosyaya izin veriyor, bu da ~50.000
 * satır demek olabilir. Dosyayı `file()` veya `Storage::get()` ile tek
 * seferde belleğe almak 5 MB'ı PHP'nin dizi/array overhead'iyle çarpar (bir
 * CSV satırı array'e dönünce ham boyutunun kat kat üzerine çıkar). `fgetcsv`
 * ise açık bir dosya tanıtıcısından TEK satır okur; bellek kullanımı dosya
 * boyutundan bağımsız, sabittir.
 *
 * ---------------------------------------------------------------------------
 * SÜTUN NORMALİZASYONU
 * ---------------------------------------------------------------------------
 * Kullanıcı başlığı `First Name`, `first name` veya `first_name` yazmış
 * olabilir — hepsi aynı sütuna eşlenir (küçük harf + boşluk dizisi tek alt
 * çizgiye + baştaki UTF-8 BOM temizlenir; Excel'in "CSV UTF-8" ile kaydettiği
 * dosyalar başlık hücresinin önüne BOM ekler, temizlenmezse ilk sütun asla
 * eşleşmez).
 *
 * ---------------------------------------------------------------------------
 * SATIR NUMARASI SÖZLEŞMESİ
 * ---------------------------------------------------------------------------
 * Başlık satırı = 1, ilk veri satırı = 2 — kullanıcının Excel'de gördüğü
 * satır numarasıyla birebir eşleşir (Excel de başlığı 1. satır sayar).
 *
 * ---------------------------------------------------------------------------
 * SERT HATA (row invalid) vs. YUMUŞAK UYARI (fallback + devam)
 * ---------------------------------------------------------------------------
 *   SERT: first_name/last_name boş, email biçimi geçersiz -> satır
 *   OLUŞTURULMAZ, `errors` ile döner, `data` null'dur.
 *   YUMUŞAK: source/status izinli listede değil, score 0-100 dışında ->
 *   satır yine de işlenir, alan varsayılana düşürülür ve `warnings` ile
 *   bildirilir. Tek bozuk sütun yüzünden satırı komple reddetmek gereksiz
 *   sert olurdu; kullanıcı zaten raporda uyarıyı görüp kaynağı düzeltebilir.
 */
class LeadCsvParser
{
    /**
     * @var array<int, string>
     */
    public const REQUIRED_COLUMNS = ['first_name', 'last_name'];

    /**
     * @var array<int, string>
     */
    public const KNOWN_COLUMNS = [
        'first_name', 'last_name', 'email', 'phone', 'company_name',
        'position', 'source', 'status', 'score', 'notes',
    ];

    /**
     * Sütun başına azami karakter sayısı — `StoreLeadRequest` kurallarıyla
     * aynı (formdan reddedilen değer CSV'den de girmemeli). `leads`
     * tablosunda bu sütunlar VARCHAR(255); sınır aşılırsa katı moddaki
     * MariaDB tüm içe aktarmayı "Data too long" ile yarıda keserdi.
     *
     * @var array<string, int>
     */
    private const MAX_LENGTHS = [
        'first_name' => 255,
        'last_name' => 255,
        'email' => 255,
        'phone' => 50,
        'company_name' => 255,
        'position' => 255,
    ];

    /**
     * `notes` bir TEXT sütunu: sınır karakter değil BAYT (65.535).
     */
    private const NOTES_MAX_BYTES = 65535;

    /**
     * normalize edilmiş sütun adı => CSV'deki sütun indeksi.
     *
     * @var array<string, int>
     */
    private array $columnIndex = [];

    /**
     * @var array<int, string>
     */
    private array $unknownColumns = [];

    /**
     * @var array<int, string>
     */
    private array $missingRequiredColumns = [];

    /**
     * Açık dosya tanıtıcısından başlık satırını okur ve sütun eşlemesini
     * kurar. Aynı parser örneği farklı bir handle ile tekrar çağrılabilir
     * (ör. önce satır sayımı, sonra gerçek işleme için yeniden açılan
     * dosya) — her çağrı state'i baştan kurar.
     *
     * @param  resource  $handle
     */
    public function readHeader($handle): void
    {
        $header = fgetcsv($handle);

        $normalized = array_map($this->normalizeColumn(...), $header === false ? [] : $header);

        $this->columnIndex = [];

        foreach ($normalized as $index => $name) {
            if ($name === '') {
                continue;
            }

            // Aynı isimli sütun tekrar ederse sonuncusu kazanır — nadir bir
            // kullanıcı hatası, sessizce en sağdaki değeri kullanmak makul.
            $this->columnIndex[$name] = $index;
        }

        $this->missingRequiredColumns = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($this->columnIndex)));
        $this->unknownColumns = array_values(array_diff(array_keys($this->columnIndex), self::KNOWN_COLUMNS));
    }

    /**
     * @return array<int, string>
     */
    public function missingRequiredColumns(): array
    {
        return $this->missingRequiredColumns;
    }

    public function hasRequiredColumns(): bool
    {
        return $this->missingRequiredColumns === [];
    }

    /**
     * @return array<int, string>
     */
    public function unknownColumns(): array
    {
        return $this->unknownColumns;
    }

    /**
     * `readHeader()` sonrası kalan satırları tek tek üretir. Boş satırlar
     * (tüm hücreleri boş) sessizce atlanır — ne rapora ne sayaçlara girer.
     *
     * @param  resource  $handle
     * @return Generator<int, array{row: int, data: ?array<string, mixed>, errors: array<int, string>, warnings: array<int, string>, raw: array<string, mixed>}>
     */
    public function rows($handle): Generator
    {
        $rowNumber = 1; // başlık = 1

        while (($fields = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($this->isBlankRow($fields)) {
                continue;
            }

            yield $this->parseRow($fields, $rowNumber);
        }
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    private function isBlankRow(array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) $field) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array{row: int, data: ?array<string, mixed>, errors: array<int, string>, warnings: array<int, string>, raw: array<string, mixed>}
     */
    private function parseRow(array $fields, int $rowNumber): array
    {
        // Son satırdaki eksik virgülleri tolere et: fgetcsv zaten eksik
        // sondaki hücreleri array'e hiç koymaz, `get()` bunu array_key_exists
        // ile kontrol ederek null'a düşürür — index dışı erişim hatası olmaz.
        $get = function (string $column) use ($fields): ?string {
            $index = $this->columnIndex[$column] ?? null;

            if ($index === null || ! array_key_exists($index, $fields)) {
                return null;
            }

            // Geçersiz bayt dizileri buradan dışarı çıkmaz (bkz. aşağıdaki
            // kodlama kontrolü): değer hata mesajlarına da gömülüyor ve tek
            // bozuk bayt tüm rapor yanıtının JSON'a çevrilmesini bozardı.
            $value = trim(mb_scrub((string) $fields[$index], 'UTF-8'));

            return $value === '' ? null : $value;
        };

        $raw = [];
        $badEncoding = [];
        foreach ($this->columnIndex as $name => $index) {
            $cell = $fields[$index] ?? null;

            if (is_string($cell) && ! mb_check_encoding($cell, 'UTF-8')) {
                $badEncoding[] = $name;
                $cell = mb_scrub($cell, 'UTF-8');
            }

            $raw[$name] = $cell;
        }

        $errors = [];
        $warnings = [];

        // Geçersiz UTF-8 satır hatasıdır, sessizce saklanmaz: utf8mb4 katı
        // modda satırı reddeder, gevşek modda bozuk metin listeye girer ve
        // listeleme yanıtı JSON'a çevrilemez hâle gelir. Tipik kaynak,
        // Excel'in "CSV (virgülle ayrılmış)" ile Windows-1254 kaydetmesidir.
        foreach ($badEncoding as $column) {
            $errors[] = "\"{$column}\" sütununda geçersiz UTF-8 karakter var; dosyayı \"CSV UTF-8\" olarak kaydedin.";
        }

        foreach (self::MAX_LENGTHS as $column => $max) {
            $value = $get($column);

            if ($value !== null && mb_strlen($value, 'UTF-8') > $max) {
                $errors[] = "\"{$column}\" en fazla {$max} karakter olabilir.";
            }
        }

        $notes = $get('notes');
        if ($notes !== null && strlen($notes) > self::NOTES_MAX_BYTES) {
            $errors[] = '"notes" çok uzun (en fazla '.self::NOTES_MAX_BYTES.' bayt).';
        }

        $firstName = $get('first_name');
        $lastName = $get('last_name');

        if ($firstName === null) {
            $errors[] = 'first_name boş olamaz.';
        }

        if ($lastName === null) {
            $errors[] = 'last_name boş olamaz.';
        }

        $email = $get('email');
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Geçersiz e-posta biçimi: \"{$email}\".";
        }

        $source = $get('source');
        if ($source !== null && ! in_array($source, StoreLeadRequest::SOURCES, true)) {
            $warnings[] = "Bilinmeyen kaynak (\"{$source}\"), \"other\" olarak ayarlandı.";
            $source = null;
        }
        $source ??= 'other';

        // `converted` burada da yazılamaz (StoreLeadRequest::WRITABLE_STATUSES):
        // dönüşüm yalnızca dönüşüm servisinden geçer.
        $status = $get('status');
        if ($status !== null && ! in_array($status, StoreLeadRequest::WRITABLE_STATUSES, true)) {
            $warnings[] = "Bilinmeyen durum (\"{$status}\"), \"new\" olarak ayarlandı.";
            $status = null;
        }
        $status ??= 'new';

        $scoreRaw = $get('score');
        $score = 0;
        if ($scoreRaw !== null) {
            if (ctype_digit($scoreRaw) && (int) $scoreRaw >= 0 && (int) $scoreRaw <= 100) {
                $score = (int) $scoreRaw;
            } else {
                $warnings[] = "Geçersiz skor (\"{$scoreRaw}\"), 0 olarak ayarlandı.";
            }
        }

        $data = null;

        if ($errors === []) {
            $data = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $get('phone'),
                'company_name' => $get('company_name'),
                'position' => $get('position'),
                'source' => $source,
                'status' => $status,
                'score' => $score,
                'notes' => $get('notes'),
            ];
        }

        return [
            'row' => $rowNumber,
            'data' => $data,
            'errors' => $errors,
            'warnings' => $warnings,
            'raw' => $raw,
        ];
    }

    private function normalizeColumn(?string $name): string
    {
        $name = mb_scrub((string) $name, 'UTF-8');

        // Excel'in "CSV UTF-8" export'u başlık hücresinin önüne BOM koyar.
        $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
        $name = trim($name);
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/\s+/u', '_', $name) ?? $name;

        return $name;
    }
}
