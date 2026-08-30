<?php

namespace App\Models;

use App\Support\ActivityLogging\LogsCrmActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Anlaşma (Deal) modeli — Kanban kartı, pipeline_stage_id + position ile sıralanır.
class Deal extends Model
{
    use HasFactory, LogsCrmActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'amount',
        'currency',
        // Kapanışta (won/lost) DONAN temel-para-birimi (TRY) karşılığı —
        // üçü birlikte yazılır/temizlenir, bkz. DealMoveService::freezeBaseAmount().
        'base_amount',
        'base_rate',
        'base_rate_date',
        'pipeline_stage_id',
        'position',
        'version',
        'probability',
        'expected_close_date',
        'closed_at',
        'status',
        'lost_reason',
        'won_reason',
        'company_id',
        'contact_id',
        'owner_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            // Donmuş alanlar float DEĞİL: `decimal` cast'i Eloquent'te
            // string döndürür ve bcmath ile kayıpsız işlenir
            // (docs/QUOTE-FINANCIALS.md float disiplini).
            'base_amount' => 'decimal:2',
            'base_rate' => 'decimal:6',
            'base_rate_date' => 'date',
            'version' => 'integer',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    // `position`: fractional index (ör. "aa", "ab", "b") — Kanban'da kartlar arası ekleme toplu renumbering gerektirmesin diye string tutulur.
    // `version`: optimistic locking sayacı — iki kullanıcı aynı kartı eşzamanlı taşır/günceller ise, eski version'lı istek reddedilip çakışma tespit edilir.

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activityable');
    }

    // taggables pivotunda timestamps yok — withTimestamps() ÇAĞIRMA (kolonlar mevcut değil).
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'customizable');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeWon(Builder $query): Builder
    {
        return $query->where('status', 'won');
    }

    public function scopeLost(Builder $query): Builder
    {
        return $query->where('status', 'lost');
    }
}
