<?php

namespace App\Repositories;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\Quotes\QuoteExpiry;
use App\Sync\SyncVersionBumper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Teklif sorgu katmanı.
 *
 * QuoteExpiry BURAYA ENJEKTE EDİLİR (TicketRepository ← SlaService ile aynı
 * desen): süresi dolma predicate'i saf bir sorgu-kapsamı üreticisidir,
 * hiçbir repository'ye bağlı değildir, dolayısıyla döngü oluşmaz. Tanımın tek
 * yerde yaşaması `filter[expired]` ile `is_expired` alanının çelişmemesini
 * garanti eder.
 */
class QuoteRepository
{
    /**
     * Sıralama beyaz listesi. Dışındaki her değer SESSİZCE varsayılana
     * (`-created_at`) düşer — istemciye 422 döndürmek, kaydedilmiş bir
     * görünümün kolon adı değişince tamamen kırılmasına yol açardı (Faz 6/7/8
     * ile aynı sözleşme).
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'quote_number', 'title', 'status', 'total', 'valid_until', 'created_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Liste ucunun eager-load seti — N+1'in tek savunması.
     *
     * `items` BİLEREK YOK. Liste yanıtı kalemleri DÖNDÜRMEZ (yalnızca
     * `items_count` ve toplamlar), dolayısıyla kalemleri yüklemek sadece
     * saymak için yüzlerce satırı belleğe almak olurdu — 100 teklif × 5 kalem
     * = 500 gereksiz satır. `withCount('items')` bunu ana sorgunun İÇİNDE tek
     * bir alt-select ile halleder: teklif başına ek sorgu YOK, ek satır YOK.
     *
     * @var array<int, string>
     */
    protected const LIST_RELATIONS = ['deal', 'company', 'contact', 'creator'];

    /**
     * Detay ucunun seti — burada kalemler TAM olarak yüklenir.
     * `items` ilişkisi Quote modelinde `orderBy('position')` taşır.
     *
     * @var array<int, string>
     */
    protected const DETAIL_RELATIONS = ['deal', 'company', 'contact', 'creator', 'items'];

    public function __construct(protected QuoteExpiry $expiry) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Quote::query()
            ->with(self::LIST_RELATIONS)
            ->withCount('items');

        $this->applyFilters($query, $filters);

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);
        $query->orderBy($column, $direction);

        // İkincil sıralama: `status` veya `total` gibi kolonlarda eşit
        // değerler sayfalar arasında rastgele sıralanır ve aynı kayıt iki
        // sayfada birden görünebilir. `id` benzersiz olduğu için sıralamayı
        // toplam düzene tamamlar.
        if ($column !== 'created_at') {
            $query->orderBy('id', 'desc');
        }

        return $query->paginate($perPage);
    }

    public function findOrFail(int $id): Quote
    {
        return Quote::query()
            ->with(self::DETAIL_RELATIONS)
            ->withCount('items')
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Quote
    {
        $quote = new Quote;
        $quote->fill($data);
        $quote->save();

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Quote $quote, array $data): Quote
    {
        $quote->fill($data);
        $quote->save();

        return $quote;
    }

    /**
     * Bir teklifin HENÜZ GÖNDERİLMEMİŞ revizyonu (varsa).
     *
     * QuoteService::revise() bunu tekrar-çağrı koruması için kullanır:
     * "Revize Et" düğmesine iki kez basmak iki yarım revizyon üretmemelidir.
     */
    public function findDraftChild(Quote $quote): ?Quote
    {
        // Modeldeki `revisions()` (hasMany, `parent_quote_id`) ilişkisi
        // üzerinden: yabancı anahtar adı TEK yerde (modelde) yaşasın.
        // `orderBy('id')` deterministik seçim içindir — teoride tek bir draft
        // child olur (QuoteService::revise tekrar-çağrı koruması), ama elle
        // üretilmiş veride ikinci bir kayıt varsa her zaman EN ESKİSİ döner.
        return $quote->revisions()
            ->where('status', 'draft')
            ->orderBy('id')
            ->first();
    }

    /**
     * Revizyon numarası: kök numara + `-R<revision>`.
     *
     * Kök, mevcut numaradan `-R<n>` eki ATILARAK bulunur — böylece
     * `QTE-000007-R2`'nin revizyonu `QTE-000007-R2-R3` değil,
     * `QTE-000007-R3` olur ve numara zincir boyunca okunabilir kalır
     * (sözleşme §6).
     */
    public function revisionQuoteNumber(string $currentNumber, int $revision): string
    {
        $root = preg_replace('/-R\d+$/', '', $currentNumber) ?? $currentNumber;

        return $root.'-R'.$revision;
    }

    public function delete(Quote $quote): void
    {
        $quote->delete();
    }

    /**
     * Kalemleri TAMAMEN değiştirir: önce hepsi silinir, sonra gelen liste
     * `position` sırasıyla yeniden yazılır.
     *
     * NEDEN "eşleştir ve güncelle" DEĞİL: istemci kalem listesini bir bütün
     * olarak gönderir (ekranda satır ekleyip silip sürükler); id bazlı
     * eşleştirme, silinen satırların id'lerini takip etmeyi ve olmayan bir
     * id gönderildiğinde ne olacağını tanımlamayı gerektirirdi. Kalem
     * id'leri teklifin DIŞINDA hiçbir yerde referans edilmez (yabancı anahtar
     * yok, API'de kalem ucu yok) ve bu işlem yalnızca `draft` teklifte
     * mümkündür (QuoteService::assertItemsEditable) — dolayısıyla id'lerin
     * yenilenmesinin görünür bir maliyeti yoktur.
     *
     * `quote_items` softDeletes TAŞIMAZ; buradaki `delete()` gerçek bir
     * DELETE'tir ve satırlar geride kalmaz.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function replaceItems(Quote $quote, array $items): void
    {
        $quote->items()->delete();

        $position = 1;

        foreach ($items as $item) {
            $item['quote_id'] = $quote->id;
            $item['position'] = $position++;

            QuoteItem::query()->create($item);
        }

        $quote->unsetRelation('items');

        /*
         * Protocol §2.3 #1 / §1.5 - quote items are NOT a sync table: they ride
         * inside the quote's `items` payload (K-F). The bulk delete above stays
         * exactly as it is, and no per-item tombstone is owed. What IS owed is
         * the owner's version: editing only line amounts leaves every column of
         * `quotes` untouched, so without this bump the totals a client shows
         * would silently diverge from the server's.
         */
        SyncVersionBumper::bump($quote);
    }

    /**
     * `quote_number` SUNUCU üretir — istemciden asla kabul edilmez
     * (StoreQuoteRequest'te tanımlı değildir).
     *
     * Sıra numarası MEVCUT EN BÜYÜK NUMARADAN türetilir, `max(id)`'den değil:
     * teklif numarası kullanıcıya gösterilen ve elle aranan bir belge
     * numarasıdır; `quotes` tablosunda id boşlukları oluştuğunda (silinen
     * kayıtlar) numaraların id ile birlikte atlaması yerine numara dizisinin
     * kendi sürekliliğini koruması beklenir.
     *
     * Soft-delete edilmiş teklifler de sayılır (`withTrashed`): silinmiş bir
     * teklifin numarasını yeniden kullanmak, denetim izinde aynı numaranın
     * iki farklı belgeyi işaret etmesine yol açardı.
     *
     * Numara üzerindeki `%` ve `_` LIKE joker karakterleri değildir; sabit
     * önek `QTE-` ile filtreleniyoruz, kullanıcı girdisi yok — enjeksiyon
     * yüzeyi bulunmuyor.
     */
    public function nextQuoteNumber(): string
    {
        // REVİZYONLAR DIŞLANIR (`%-R%`): `QTE-000007-R2` kök diziye yeni bir
        // sıra numarası EKLEMEZ, mevcut bir belgenin sürümüdür. Dahil
        // edilseydi bir revizyon açmak, sonraki yeni teklifin numarasını
        // gereksiz yere ileri atardı.
        $latest = Quote::withTrashed()
            ->where('quote_number', 'like', 'QTE-%')
            ->where('quote_number', 'not like', '%-R%')
            ->orderBy('quote_number', 'desc')
            ->value('quote_number');

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, 4)) + 1;

        // Eşzamanlılığa karşı ASIL koruma QuoteService::create() içindeki
        // atomik kilit + unique index üzerinden yeniden denemedir; buradaki
        // döngü yalnızca "numara dizisinde boşluk varken çakışma" gibi veri
        // kaynaklı durumları çözer.
        do {
            $number = 'QTE-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $sequence++;
        } while (Quote::withTrashed()->where('quote_number', $number)->exists());

        return $number;
    }

    // -----------------------------------------------------------------
    // Sorgu kurulumu
    // -----------------------------------------------------------------

    /**
     * @param  Builder<Quote>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Quote>
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // PARANTEZLİ GRUPLAMA ŞART: yoksa buradaki OR'lar, dışarıdaki
            // `filter[...]` where'lerinin yanına düz OR olarak eklenir ve
            // filtreler sızar (aranan kelime tüm filtreleri geçersiz kılar).
            $query->where(function (Builder $query) use ($term): void {
                $query->where('quote_number', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        foreach (['deal_id', 'company_id', 'contact_id'] as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null) {
                $query->where($key, $filters[$key]);
            }
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        // `null` = filtre yok; `true`/`false` = süresi dolmuş / dolmamış.
        if (array_key_exists('expired', $filters) && $filters['expired'] !== null) {
            $filters['expired']
                ? $this->expiry->scopeExpired($query)
                : $this->expiry->scopeNotExpired($query);
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveSort(?string $sort): array
    {
        if (empty($sort)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        $direction = 'asc';
        $column = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $column = substr($sort, 1);
        }

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        return [$column, $direction];
    }
}
