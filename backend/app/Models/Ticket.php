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

// Destek talebi (Ticket) modeli — SLA takibi ile müşteri destek kaydı.
class Ticket extends Model
{
    use HasFactory, LogsCrmActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_number',
        'subject',
        'description',
        'priority',
        'status',
        'category',
        'contact_id',
        'company_id',
        'assigned_to',
        'created_by',
        'sla_due_at',
        // Faz 8 / B — SLA duraklama + bildirim damgaları (docs/SLA-DESIGN.md §3).
        // $fillable'a eklenmeleri KASITLIDIR: LogsCrmActivity `logFillable()`
        // kullanır, yani bu alanlar ancak burada listelenirse `activity_log`'a
        // düşer. Bir SLA duraklaması ya da ihlal damgası tam olarak denetim
        // izinde görülmesi gereken türden bir değişikliktir. İstemci bunları
        // YİNE DE gönderemez: StoreTicketRequest'te hiç tanımlı değiller,
        // UpdateTicketRequest ise `missing` kuralıyla 422 üretir.
        'sla_paused_at',
        'sla_paused_seconds',
        'sla_warning_notified_at',
        'sla_breach_notified_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sla_due_at' => 'datetime',
            'sla_paused_at' => 'datetime',
            'sla_paused_seconds' => 'integer',
            'sla_warning_notified_at' => 'datetime',
            'sla_breach_notified_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
