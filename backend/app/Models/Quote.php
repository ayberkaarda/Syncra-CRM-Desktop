<?php

namespace App\Models;

use App\Support\ActivityLogging\LogsCrmActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Teklif — bir deal/company/contact'a bağlı, kalemleri (QuoteItem) olan belge.
class Quote extends Model
{
    use HasFactory, LogsCrmActivity, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'quote_number',
        'title',
        'deal_id',
        'company_id',
        'contact_id',
        'status',
        'valid_until',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'currency',
        // `sent` anında DONAN kur (1 birim `currency` = X TRY) ve yayın
        // tarihi — bkz. QuoteStatusMachine::freezeExchangeRate().
        'exchange_rate',
        'exchange_rate_date',
        'notes',
        'terms',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'created_by',
        'discount_type',
        'discount_value',
        'parent_quote_id',
        'revision',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            // Float DEĞİL — decimal cast'i string döndürür (bcmath ile işlenir).
            'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'discount_value' => 'decimal:2',
            'revision' => 'integer',
        ];
    }

    // M1'in Deal modeli tam nitelikli isimle referans alınıyor (henüz oluşturulmamış olabilir).
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    // Revizyon zinciri: parent_quote_id bir öncekini gösterir (köke değil).
    public function parentQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'parent_quote_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Quote::class, 'parent_quote_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }
}
