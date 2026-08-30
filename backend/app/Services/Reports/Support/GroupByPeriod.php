<?php

namespace App\Services\Reports\Support;

use Illuminate\Validation\ValidationException;

/**
 * `group_by=day|week|month` beyaz listesi ve bu değerin MySQL
 * `DATE_FORMAT()` kalıbına eşlemesi.
 *
 * GÜVENLİK: kullanıcı girdisi (`group_by`) hiçbir zaman doğrudan
 * `selectRaw()`/`groupBy()` içine string olarak gömülmez — yalnızca bu
 * sabit haritanın bir anahtarıysa kullanılır (whitelist), aksi halde 422.
 *
 * Hafta biçimi `%x-%v` (ISO hafta-numaralandırma yılı + ISO hafta no,
 * MySQL mod 3 ile aynı çift) kullanır — `%Y-%v` DEĞİL: yıl sınırındaki
 * haftalarda (ör. 30 Aralık) %Y ile %v uyuşmaz ve "2026-W01" gibi tutarsız
 * bir dönem etiketi üretir. VERİ SÖZLEŞMESİ'ndeki `period` biçimi
 * (`Y-\WW`) burada `"2026-W34"` şeklinde üretilir.
 */
class GroupByPeriod
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    /**
     * @var array<string, string>
     */
    private const FORMATS = [
        self::DAY => '%Y-%m-%d',
        self::WEEK => '%x-W%v',
        self::MONTH => '%Y-%m',
    ];

    public static function validate(?string $groupBy): string
    {
        $groupBy ??= self::DAY;

        if (! array_key_exists($groupBy, self::FORMATS)) {
            throw ValidationException::withMessages([
                'group_by' => ['Geçersiz gruplama. Kabul edilen değerler: day, week, month.'],
            ]);
        }

        return $groupBy;
    }

    public static function dateFormat(string $groupBy): string
    {
        return self::FORMATS[$groupBy] ?? self::FORMATS[self::DAY];
    }
}
