<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler (docs/PHASE-INTL.md §3).
 *
 * `query_json` BİLEREK sunucu tarafında re-encode edilmeden ham dönmez: okuma yolları
 * (`SavedViewResource`) `App\Services\SavedViews\SavedViewQueryValidator::sanitizeForRead()`
 * ÜZERİNDEN geçer — bu model kendisi bir doğrulama katmanı DEĞİLDİR, yalnızca depolamadır
 * (docs/PHASE-AUDIT.md §5.4: "query_json sunucuda yeniden doğrulanmalı").
 *
 * Bu kayıt kendisi ASLA veri (deal/lead/... satırı) DÖNDÜRMEZ — yalnızca bir filtre
 * anlık görüntüsü taşır; gerçek veri her zaman AÇAN kullanıcının kendi isteğiyle ilgili
 * modülün kendi uç noktasından (DealController::index() vb.) çekilir.
 */
class SavedView extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'module',
        'name',
        'query_json',
        'is_shared',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'query_json' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
