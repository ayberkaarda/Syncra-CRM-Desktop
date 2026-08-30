<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir para biriminin bir güne ait TCMB döviz alış kuru (veya manuel
 * düzeltme). 1 birim yabancı para için TRY karşılığı — bkz.
 * App\Services\Exchange\TcmbRateFetcher (ForexBuying/Unit gerekçesi) ve
 * database/migrations/..._create_exchange_rates_table.php (TRY satırı
 * neden tutulmuyor).
 *
 * TRY için hiçbir zaman satır YOKTUR — App\Services\Exchange\
 * ExchangeRateService::isBaseCurrency()/latest() bu kısayolu yönetir.
 */
class ExchangeRate extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'currency',
        'rate',
        'unit',
        'rate_date',
        'source',
        'entered_by',
    ];

    /**
     * `rate` FLOAT DEĞİL — `decimal:6` cast'i Eloquent'te string olarak
     * tutulur (float yuvarlama/temsil hatasına asla girmez); bkz.
     * docs/QUOTE-FINANCIALS.md float disiplini.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'unit' => 'integer',
            'rate_date' => 'date',
        ];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function scopeForCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * En güncel tarihli satır önce — `latest(currency)` sorgularında
     * kullanılır (bkz. ExchangeRateService).
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('rate_date');
    }
}
