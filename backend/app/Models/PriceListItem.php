<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Bir fiyat listesindeki tek bir ürün fiyatı satırı.
//
// softDeletes YOK ve LogsCrmActivity YOK (bilinçli): bu, CustomFieldValue ile
// aynı "değer satırı" kategorisidir — asıl denetlenebilir kayıt üst
// varlıktır (PriceList), ürün başına bir fiyat satırının kendi geçmişi ayrı
// bir denetim değeri taşımaz. Liste silinince kalemleri de cascade ile
// silinir (bkz. migration) — ayrıca soft-delete tutmanın bir anlamı yok.
class PriceListItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'price_list_id',
        'product_id',
        'unit_price',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
