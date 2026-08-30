<?php

namespace App\Models;

use App\Support\ActivityLogging\LogsCrmActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Pipeline aşaması (PipelineStage) — Kanban sütunları. Kasıtlı olarak SoftDeletes yok, silme yerine is_active=false kullanılır.
class PipelineStage extends Model
{
    use HasFactory, LogsCrmActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        // Çeviri anahtarı — DOLUYSA `name` bizim taksonomimizdendir ve arayüz `enums.json`daki
        // `pipelineStage.<name_key>` anahtarını basar; NULL'sa `name` MÜŞTERİ VERİSİDİR (admin
        // yeniden adlandırmış ya da yeni aşama oluşturmuş) ve OLDUĞU GİBİ basılır. Yalnızca
        // PipelineStageSeeder (seed) ve PipelineStageService::update() (temizleme) yazar —
        // istemciden doğrudan YAZILAMAZ (bkz. UpdatePipelineStageRequest / StorePipelineStageRequest,
        // ikisi de bu alanı kabul etmez).
        'name_key',
        'position',
        'probability',
        'color',
        'is_won',
        'is_lost',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'probability' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * Sadece aktif (pasifleştirilmemiş) aşamaları getirir.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
