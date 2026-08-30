<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Sayfa ziyaret logu. Heartbeat yeni satır EKLEMEZ, son kaydın duration_seconds ve
// last_heartbeat_at alanlarını GÜNCELLER — aksi halde tablo haftalar içinde milyonlarca
// satıra şişer (ROADMAP R5, 90 gün retention ile budanacak).
class PageVisitLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'route',
        'path',
        'title',
        'entered_at',
        'last_heartbeat_at',
        'duration_seconds',
        'ip_address',
        'session_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
