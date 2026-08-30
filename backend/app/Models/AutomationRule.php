<?php

namespace App\Models;

use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faz 14 / İz F — C4 küçük no-code otomasyon kuralı
 * (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.1/§5.4).
 *
 * `trigger_type`/`action_type` + `*_config` sözleşmesi tamamen
 * `App\Services\Automation\AutomationCatalog`'dadır — bu model kasıtlı
 * olarak "aptaldır" (dumb): hiçbir iş kuralı/yorum burada yaşamaz, yalnız
 * depolama + cast + ilişki.
 */
class AutomationRule extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'trigger_type',
        'trigger_config',
        'action_type',
        'action_config',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trigger_config' => 'array',
            'action_config' => 'array',
        ];
    }

    protected static function newFactory(): AutomationRuleFactory
    {
        return AutomationRuleFactory::new();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Yalnız aktif kurallar için — çalışma anı eşleştirmesi bunu kullanır.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<AutomationRule>  $query
     * @return \Illuminate\Database\Eloquent\Builder<AutomationRule>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<AutomationRule>  $query
     * @return \Illuminate\Database\Eloquent\Builder<AutomationRule>
     */
    public function scopeForTrigger($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }
}
