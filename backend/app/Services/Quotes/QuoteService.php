<?php

namespace App\Services\Quotes;

use App\Models\Product;
use App\Models\Quote;
use App\Models\Setting;
use App\Repositories\QuoteRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Teklif iş mantığı. Controller ince kalır; para hesabı QuoteCalculator'a
 * (docs/QUOTE-FINANCIALS.md), durum geçişleri QuoteStatusMachine'e, sorgular
 * QuoteRepository'ye delegedir.
 *
 * Bu sınıf dört kritik iş kuralının sahibidir:
 *   1. Toplamlar İSTEMCİDEN ALINMAZ, her yazmada kalemlerden yeniden
 *      hesaplanır (§ persistTotals).
 *   2. Gönderilmiş bir teklifin kalemleri/tutarı DEĞİŞTİRİLEMEZ
 *      (§ assertAmountsEditable) — değişiklik REVİZYON gerektirir.
 *   3. `quote_number` sunucu üretir ve eşzamanlı isteklerde çakışmaz
 *      (§ create).
 *   4. Revizyon zinciri (§ revise, sözleşme §6).
 */
class QuoteService
{
    /**
     * Kalemlerin ve tutarın kilitlendiği durumlarda `PATCH /api/quotes/{id}`
     * ile DEĞİŞTİRİLEMEYEN alanlar.
     *
     * `items`, `discount_type` ve `discount_value` tutarı doğrudan
     * değiştirir. `currency` tutarın birimini değiştirir — 100.000 TRY'lik bir
     * teklifi 100.000 EUR yapmak, kalemlere hiç dokunmadan müşteriye
     * gönderilen rakamı katlardı. `company_id`/`contact_id` ise belgenin
     * MUHATABIDIR: gönderilmiş bir teklifin kime gönderildiğini sonradan
     * değiştirmek, arşivi olmayan bir şeyin kaydını üretir.
     *
     * KİLİTLENMEYENLER ve gerekçeleri: `title`, `notes`, `terms` yalnızca
     * sunum metnidir; `valid_until` uzatılabilir olmalıdır (geçerlilik süresi
     * uzatmak günlük bir satış hareketidir ve tutarı değiştirmez);
     * `deal_id` idari bir bağdır, belgenin içeriği değildir.
     *
     * @var array<int, string>
     */
    protected const AMOUNT_LOCKED_FIELDS = [
        'items', 'discount_type', 'discount_value', 'currency', 'company_id', 'contact_id',
    ];

    /**
     * `quote_items` tablosunda gerçekten kolonu olan alanlar.
     *
     * @var array<int, string>
     */
    protected const ITEM_COLUMNS = [
        'product_id', 'name', 'description', 'quantity', 'unit_price',
        'discount_percent', 'tax_rate', 'line_total',
    ];

    /**
     * Revizyonda YENİ kayda kopyalanan başlık alanları. Damgalar
     * (`sent_at`/`accepted_at`/`rejected_at`), `status`, `quote_number`,
     * `revision`, `parent_quote_id` ve `valid_until` KOPYALANMAZ — hepsi
     * revizyona özel olarak yeniden üretilir.
     *
     * @var array<int, string>
     */
    protected const REVISION_COPIED_FIELDS = [
        'title', 'deal_id', 'company_id', 'contact_id', 'currency', 'notes', 'terms',
        'discount_type', 'discount_value',
    ];

    /**
     * Revize edilebilen durumlar (sözleşme §6).
     *
     * `draft` YOK: zaten düzenlenebilir, kopyalamak yalnızca ikinci bir
     * yarım belge üretirdi. `accepted` YOK: kabul edilmiş taahhüt revize
     * edilmez; değişiklik gerekiyorsa BAĞIMSIZ yeni bir teklif açılır — aksi
     * hâlde "kabul edilen teklifin bir revizyonu var" gibi hangisinin
     * bağlayıcı olduğu belirsiz bir zincir doğardı.
     *
     * @var array<int, string>
     */
    protected const REVISABLE_STATUSES = ['sent', 'rejected', 'expired'];

    public function __construct(
        protected QuoteRepository $quotes,
        protected QuoteStatusMachine $statusMachine,
    ) {}

    /**
     * `GET /api/quotes`.
     *
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->quotes->paginate($filters, $perPage);
    }

    public function find(int $id): Quote
    {
        return $this->quotes->findOrFail($id);
    }

    /**
     * `POST /api/quotes`.
     *
     * -------------------------------------------------------------------
     * EŞZAMANLI OLUŞTURMADA `quote_number` ÇAKIŞMASI — İKİ KATMANLI KORUMA
     * -------------------------------------------------------------------
     * 1. ATOMİK KİLİT (Cache::lock): numara üretimi + kayıt aynı kilidin
     *    altında yapılır, dolayısıyla iki istek AYNI numarayı okuyamaz.
     *    Üretimde önbellek Redis'tir (`CACHE_STORE=redis`), yani kilit
     *    süreçler ve PHP-FPM işçileri ARASINDA geçerlidir. `block(5)` ile
     *    ikinci istek beklemeye alınır, reddedilmez.
     * 2. UNIQUE INDEX + YENİDEN DENEME: kilit altyapısı erişilemezse veya
     *    kilit zaman aşımına uğrarsa son savunma `quotes.quote_number`
     *    üzerindeki unique index'tir. Bu durumda veritabanı hatası 500'e
     *    dönüşmez; işlem yeni bir numarayla YENİDEN denenir (en fazla 5 kez).
     *
     * Tek başına unique index YETMEZDİ (kullanıcı sebepsiz 500 görürdü), tek
     * başına kilit de yetmezdi (Redis düşerse numara tekilliği kaybolurdu).
     *
     * @param  array<string, mixed>  $data  'items' anahtarı içerir.
     */
    public function create(array $data, int $creatorId): Quote
    {
        return $this->withNumberRetry(fn () => $this->createWithinLock($data, $creatorId));
    }

    /**
     * `PATCH /api/quotes/{quote}`.
     *
     * `status` ve toplam alanları buraya HİÇ ULAŞMAZ — UpdateQuoteRequest
     * onları `missing` kuralıyla 422'ye çevirir.
     *
     * @param  array<string, mixed>  $data  'items' anahtarı içerebilir.
     */
    public function update(Quote $quote, array $data): Quote
    {
        $this->assertAmountsEditable($quote, $data);

        return DB::transaction(function () use ($quote, $data) {
            $hasItems = array_key_exists('items', $data);
            $items = $data['items'] ?? [];
            unset($data['items']);

            $discountChanged = array_key_exists('discount_type', $data)
                || array_key_exists('discount_value', $data);

            if (! empty($data)) {
                $this->quotes->update($quote, $data);
            }

            // Kalemler ya da indirim girdisi değiştiyse toplamlar YENİDEN
            // hesaplanır. Yüzde tipi indirimde kalem değişimi zaten
            // `discount_amount`'ı da değiştirir (sözleşme §5) — bu yüzden
            // kalem değişikliği tek başına da yeniden hesap tetikler.
            if ($hasItems) {
                $this->applyItems($quote, $items);
            } elseif ($discountChanged) {
                $this->recalculateFromStoredItems($quote);
            }

            return $this->quotes->findOrFail((int) $quote->id);
        });
    }

    public function delete(Quote $quote): void
    {
        $this->quotes->delete($quote);
    }

    /**
     * `POST /api/quotes/{quote}/send` — `draft → sent`.
     *
     * KALEMİ OLMAYAN TEKLİF GÖNDERİLEMEZ: sıfır tutarlı, boş bir belge
     * müşteriye "teklifimiz ektedir" diye gider ve karşı taraf bunu bir hata
     * değil, bir teklif olarak okur. Ayrıca gönderimden sonra tutar kilidi
     * (assertAmountsEditable) devreye girdiği için kalemler ARTIK
     * EKLENEMEZ — boş gönderilen bir teklif kalıcı olarak boş kalırdı.
     *
     * Zaten gönderilmiş (veya sonuçlanmış) bir teklifte 422
     * INVALID_STATUS_TRANSITION döner; kontrol QuoteStatusMachine'in
     * `lockForUpdate`'li geçiş tablosundadır, burada tekrarlanmaz.
     */
    public function send(Quote $quote): Quote
    {
        if ($quote->status === 'draft' && $quote->items()->count() === 0) {
            $this->deny(
                'Kalemi olmayan bir teklif gönderilemez.',
                'QUOTE_HAS_NO_ITEMS',
                ['items' => ['Teklif gönderilmeden önce en az bir kalem eklenmelidir.']],
            );
        }

        $sent = $this->statusMachine->transition($quote, 'sent');

        return $this->quotes->findOrFail((int) $sent->id);
    }

    /**
     * `PATCH /api/quotes/{quote}/status`.
     *
     * -------------------------------------------------------------------
     * TEKLİF KABUL EDİLİNCE BAĞLI FIRSATA (deal) NE OLUR: HİÇBİR ŞEY
     * -------------------------------------------------------------------
     * Karar: deal'a OTOMATİK HİÇBİR YAZMA YAPILMAZ. Ne aşama taşınır, ne
     * tutar güncellenir, ne de `status` değiştirilir. Üç gerekçe:
     *
     *  1. FAZ 7'NİN OPTİMİSTİK KİLİDİNİ BAYPAS EDER. `deals` tablosu
     *     `version` kolonuyla korunur ve aşama değişimi yalnızca
     *     `PATCH /api/deals/{deal}/move` ucundan, fractional index ve 409
     *     çakışma çözümüyle yapılır. Buradan doğrudan bir aşama yazmak, o
     *     ucun tüm eşzamanlılık güvencelerini ve panodaki sıralama mantığını
     *     atlamak demektir; aynı anda pano üzerinde kartı sürükleyen bir
     *     kullanıcının değişikliği sessizce ezilir.
     *  2. KULLANICIYI ŞAŞIRTIR. Teklif ekranında bir düğmeye basmak, başka
     *     bir modüldeki kartı görünmez şekilde oynatır; "neden bu fırsat
     *     kazanıldı'ya geçti?" sorusunun cevabı hiçbir ekranda yazmaz.
     *  3. EŞLEME BELİRSİZ. Bir fırsata BİRDEN FAZLA teklif bağlanabilir
     *     (`quotes.deal_id` çoktan bire). İkinci teklif reddedilirse ilkinin
     *     taşıdığı fırsat geri mi alınacaktır? Otomatik kural bu soruya
     *     tutarlı bir cevap veremez; insan verebilir.
     *
     * BUNUN YERİNE: kabul olayı `activity_log`'a `quotes.accepted` olarak,
     * `deal_id` özelliğiyle birlikte yazılır. Faz 10 (Bildirimler) bu olaydan
     * fırsat sahibine "teklif kabul edildi — fırsatı kazanıldı aşamasına
     * taşımak ister misiniz?" bildirimi üretebilir; karar kullanıcının, yazma
     * işlemi Faz 7'nin kendi ucunun olur.
     */
    public function changeStatus(Quote $quote, string $status, ?string $reason = null): Quote
    {
        $from = (string) $quote->status;
        $updated = $this->statusMachine->transition($quote, $status);

        $this->logStatusChange($updated, $from, $status, $reason);

        return $this->quotes->findOrFail((int) $updated->id);
    }

    /**
     * `POST /api/quotes/{quote}/revise` — sözleşme §6 revizyon zinciri.
     *
     * Gönderilmiş bir teklifin tutarı kilitlidir; müşteri pazarlık ettiğinde
     * doğru hareket eski belgeyi düzeltmek değil, YENİ bir sürüm açmaktır.
     * Bu metot o sürümü açar ve eskisine BAĞLAR:
     *
     *   - `parent_quote_id` BİR ÖNCEKİ revizyonu gösterir (köke değil), böylece
     *     pazarlık turlarının sırası zincirden okunabilir.
     *   - `quote_number` kök numaradan türer: `QTE-000007` → `QTE-000007-R2`.
     *     Kök, mevcut numaradan `-R<n>` eki atılarak bulunur; böylece R2'nin
     *     revizyonu R2-R3 değil, R3 olur.
     *   - ESKİ KAYDA DOKUNULMAZ. `revised` diye yeni bir durum eklenmedi:
     *     eski teklifin durumu müşteriye ne olduğunun kaydıdır ("gönderildi",
     *     "reddedildi") ve bizim yeni bir sürüm açmamız o gerçeği
     *     değiştirmez. "Daha yeni revizyonu var" bilgisi child sorgusuyla
     *     bulunur.
     *
     * TEKRAR ÇAĞRI KORUMASI: parent'ın zaten `draft` bir child'ı varsa yeni
     * kayıt AÇILMAZ, mevcut olan döner. Aksi hâlde düğmeye iki kez basmak iki
     * yarım revizyon üretir ve hangisinin gönderileceği belirsizleşir.
     */
    public function revise(Quote $quote): Quote
    {
        if (! in_array($quote->status, self::REVISABLE_STATUSES, true)) {
            $detail = $quote->status === 'draft'
                ? 'Taslak teklif zaten düzenlenebilir; revizyona gerek yoktur.'
                : 'Kabul edilmiş bir teklif revize edilemez. Bağımsız yeni bir teklif oluşturun.';

            $this->deny(
                'Bu teklif revize edilemez.',
                'QUOTE_NOT_REVISABLE',
                ['status' => [$detail]],
            );
        }

        $existing = $this->quotes->findDraftChild($quote);

        if ($existing !== null) {
            return $this->quotes->findOrFail((int) $existing->id);
        }

        return $this->withNumberRetry(fn () => $this->reviseWithinLock($quote));
    }

    // -----------------------------------------------------------------
    // İç yardımcılar
    // -----------------------------------------------------------------

    /**
     * Numara üretimi gerektiren bir işlemi, unique index ihlalinde en fazla
     * 5 kez yeniden dener.
     *
     * @param  callable(): Quote  $operation
     */
    protected function withNumberRetry(callable $operation): Quote
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return $this->guardWithLock($operation);
            } catch (QueryException $e) {
                // Numara kapılmış: unique index tetiklendi. Transaction geri
                // alındığı için yarım kayıt kalmaz; bir sonraki turda numara
                // yeniden üretilir.
                if ($attempts >= 5 || ! $this->isQuoteNumberCollision($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * İşlemi atomik kilit altında çalıştırır; kilit ALTYAPISI kullanılamıyorsa
     * kilitsiz devam eder.
     *
     * `$entered` bayrağı ŞART: `block()` kapanışı çalıştırdığı için, kapanışın
     * İÇİNDEN gelen bir hata da buraya düşer. Bayrak olmadan, iş mantığından
     * gelen bir hatada (ör. geçersiz kalem) işlem bir kez daha denenirdi.
     *
     * @param  callable(): Quote  $operation
     */
    protected function guardWithLock(callable $operation): Quote
    {
        $entered = false;

        $callback = function () use ($operation, &$entered): Quote {
            $entered = true;

            return $operation();
        };

        try {
            return Cache::lock('quotes:next-number', 10)->block(5, $callback);
        } catch (Throwable $e) {
            if ($entered) {
                throw $e;
            }

            // Kilit alınamadı (zaman aşımı) veya önbellek sürücüsüne
            // erişilemedi: numara üretimi kilitsiz denenir; unique index +
            // yeniden deneme hâlâ devrededir.
            return $operation();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createWithinLock(array $data, int $creatorId): Quote
    {
        return DB::transaction(function () use ($data, $creatorId) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $data['status'] = 'draft';
            $data['created_by'] = $creatorId;
            $data['quote_number'] = $this->quotes->nextQuoteNumber();
            $data['currency'] = $data['currency'] ?? 'TRY';
            $data['discount_type'] = $data['discount_type'] ?? QuoteCalculator::DISCOUNT_AMOUNT;
            $data['discount_value'] = $data['discount_value'] ?? 0;
            $data['revision'] = 1;
            $data['parent_quote_id'] = null;

            if (! array_key_exists('valid_until', $data) || $data['valid_until'] === null) {
                $data['valid_until'] = $this->defaultValidUntil();
            }

            if (! array_key_exists('terms', $data) || $data['terms'] === null) {
                $data['terms'] = $this->defaultTerms();
            }

            // Toplamlar önce sıfırlanır; gerçek değerleri applyItems()
            // hesaplayıp yazar. Böylece hesap TEK yerde yapılır ve
            // "oluştururken bir formül, güncellerken başka bir formül"
            // ihtimali ortadan kalkar.
            $data['subtotal'] = 0;
            $data['discount_amount'] = 0;
            $data['tax_amount'] = 0;
            $data['total'] = 0;

            $quote = $this->quotes->create($data);

            $this->applyItems($quote, $items);

            return $this->quotes->findOrFail((int) $quote->id);
        });
    }

    protected function reviseWithinLock(Quote $parent): Quote
    {
        return DB::transaction(function () use ($parent) {
            $revision = ((int) $parent->revision) + 1;

            $data = [];

            foreach (self::REVISION_COPIED_FIELDS as $field) {
                $data[$field] = $parent->{$field};
            }

            $data['status'] = 'draft';
            $data['created_by'] = $parent->created_by;
            $data['parent_quote_id'] = $parent->id;
            $data['revision'] = $revision;
            $data['quote_number'] = $this->quotes->revisionQuoteNumber($parent->quote_number, $revision);
            // Geçerlilik yeniden başlar: eski teklifin `valid_until`'ini
            // kopyalamak, doğduğu anda süresi dolmuş bir revizyon üretebilirdi.
            $data['valid_until'] = $this->defaultValidUntil();
            $data['sent_at'] = null;
            $data['accepted_at'] = null;
            $data['rejected_at'] = null;
            // Donmuş kur DEVRALINMAZ (PHASE-INTL §2.3): revizyon YENİ bir
            // ticari tekliftir ve kuru kendi gönderim tarihini yansıtmalıdır;
            // ebeveyn kendi donmuş kurunu korur. REVISION_COPIED_FIELDS beyaz
            // listesi bu iki kolonu zaten taşımıyor — buradaki açık null,
            // listeye ileride yanlışlıkla eklenmelerine karşı ikinci bir
            // savunma ve niyetin okunur hâlidir.
            $data['exchange_rate'] = null;
            $data['exchange_rate_date'] = null;
            $data['subtotal'] = 0;
            $data['discount_amount'] = 0;
            $data['tax_amount'] = 0;
            $data['total'] = 0;

            $child = $this->quotes->create($data);

            // Kalemler DERİN KOPYALANIR. `product_id` de kopyalanır ama
            // fiyatlar ürünün GÜNCEL değerinden yeniden okunmaz: revizyonun
            // başlangıç noktası, müşterinin gördüğü son tekliftir. Ürün
            // fiyatı bu arada değiştiyse bunu satış temsilcisi bilerek
            // günceller.
            $items = $parent->items()->orderBy('position')->get()
                ->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_percent' => (float) $item->discount_percent,
                    'tax_rate' => (float) $item->tax_rate,
                ])
                ->all();

            $this->applyItems($child, $items);

            return $this->quotes->findOrFail((int) $child->id);
        });
    }

    /**
     * Kalemleri ürün anlık kopyalarıyla zenginleştirir, hesabı yapar,
     * kalemleri ve toplamları yazar.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     */
    protected function applyItems(Quote $quote, array $rawItems): void
    {
        $prepared = $this->hydrateFromProducts($rawItems);
        $result = $this->calculate($prepared, $quote);

        $rows = [];

        foreach ($result['items'] as $item) {
            $rows[] = array_intersect_key($item, array_flip(self::ITEM_COLUMNS));
        }

        $this->quotes->replaceItems($quote, $rows);
        $this->persistTotals($quote, $result);
    }

    /**
     * Kalemlere dokunmadan, veritabanındaki mevcut kalemlerden toplamları
     * yeniden hesaplar (indirim girdisi tek başına değiştiğinde).
     */
    protected function recalculateFromStoredItems(Quote $quote): void
    {
        $items = $quote->items()->orderBy('position')->get()
            ->map(fn ($item) => [
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount_percent' => (float) $item->discount_percent,
                'tax_rate' => (float) $item->tax_rate,
            ])
            ->all();

        $this->persistTotals($quote, $this->calculate($items, $quote));
    }

    /**
     * QuoteCalculator'ı çağırır ve saf katmanın domain hatasını HTTP
     * sözleşmesine (422) çevirir. Çeviri TEK yerde yapılır.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function calculate(array $items, Quote $quote): array
    {
        try {
            return QuoteCalculator::calculate(
                $items,
                $quote->discount_value ?? 0,
                (string) ($quote->discount_type ?? QuoteCalculator::DISCOUNT_AMOUNT),
            );
        } catch (QuoteCalculationException $e) {
            $this->deny($e->getMessage(), $e->errorCode, $e->fields);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function persistTotals(Quote $quote, array $result): void
    {
        // TOPLAMLAR İSTEMCİDEN ALINMAZ. StoreQuoteRequest/UpdateQuoteRequest
        // `subtotal`, `discount_amount`, `tax_amount` ve `total`'ı hiç
        // tanımlamaz; buraya yalnızca QuoteCalculator'ın ürettiği değerler
        // yazılır. Aksi hâlde arayüzdeki bir yuvarlama farkı ya da kötü
        // niyetli bir istek, kalemleriyle uyuşmayan bir belge üretebilirdi.
        //
        // `discount_amount` de HESAPLANMIŞ bir çıktıdır: kullanıcı yalnızca
        // `discount_type` + `discount_value` girer (sözleşme §5).
        $this->quotes->update($quote, [
            'subtotal' => $result['subtotal'],
            'discount_amount' => $result['discount_amount'],
            'tax_amount' => $result['tax_amount'],
            'total' => $result['total'],
        ]);
    }

    /**
     * ÜRÜN ANLIK KOPYASI (Faz 3 kararı).
     *
     * `product_id` verilen kalemler, ürünün O ANKİ `name`, `description`,
     * `unit_price` ve `tax_rate` değerlerini kaleme KOPYALAR. Kopyalanan
     * değer VARSAYILANDIR, KİLİT DEĞİL: istek gövdesinde aynı alan
     * gönderilmişse kullanıcının değeri kazanır (özel fiyat, pazarlık
     * sonucu farklı oran, kalem adına eklenen açıklama).
     *
     * `array_key_exists` kullanılır, `??` değil: kullanıcının bilinçli olarak
     * gönderdiği `null` (ör. açıklamayı boşaltmak) ile alanı hiç göndermemek
     * farklı şeylerdir.
     *
     * Ürünler TEK sorguda çekilir — kalem başına `Product::find()` çağırmak
     * 50 kalemlik bir teklifte 50 sorgu demek olurdu.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array<int, array<string, mixed>>
     */
    protected function hydrateFromProducts(array $rawItems): array
    {
        $productIds = array_values(array_filter(array_map(
            fn (array $item) => $item['product_id'] ?? null,
            $rawItems
        )));

        $products = $productIds === []
            ? collect()
            : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        $defaultTaxRate = $this->defaultTaxRate();
        $prepared = [];

        foreach ($rawItems as $item) {
            $product = isset($item['product_id']) ? $products->get($item['product_id']) : null;

            $prepared[] = [
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'] ?? $product?->name,
                'description' => array_key_exists('description', $item)
                    ? $item['description']
                    : $product?->description,
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                    ? $item['unit_price']
                    : ($product?->unit_price ?? 0),
                'discount_percent' => $item['discount_percent'] ?? 0,
                'tax_rate' => array_key_exists('tax_rate', $item) && $item['tax_rate'] !== null
                    ? $item['tax_rate']
                    : ($product?->tax_rate ?? $defaultTaxRate),
            ];
        }

        return $prepared;
    }

    /**
     * -------------------------------------------------------------------
     * GÖNDERİLMİŞ BİR TEKLİFİN KALEMLERİ VE TUTARI DEĞİŞTİRİLEMEZ
     * -------------------------------------------------------------------
     * `draft` dışındaki her durumda (`sent`, `accepted`, `rejected`,
     * `expired`) tutarı etkileyen alanlar kilitlidir.
     *
     * Gerekçe: teklif müşteriye ULAŞTIĞI andan itibaren bir çalışma kağıdı
     * değil, karşı tarafta bir kopyası bulunan BELGEDİR. Tutarını sonradan
     * sessizce değiştirebilmek, müşterinin elindeki nüsha ile sistemdeki
     * kaydın farklılaşması demektir — anlaşmazlıkta hangisinin geçerli olduğu
     * tartışmaya açılır.
     *
     * Değişiklik gerekiyorsa doğru hareket REVİZYONDUR
     * (`POST /api/quotes/{quote}/revise`): eskisi gönderildiği hâliyle
     * arşivde kalır, yenisi kendi numarasıyla (`-R2`) ve zincir bağıyla
     * doğar.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertAmountsEditable(Quote $quote, array $data): void
    {
        if ($quote->status === 'draft') {
            return;
        }

        $attempted = array_values(array_intersect(self::AMOUNT_LOCKED_FIELDS, array_keys($data)));

        if ($attempted === []) {
            return;
        }

        $this->deny(
            'Gönderilmiş bir teklifin kalemleri ve tutarı değiştirilemez.',
            'QUOTE_LOCKED',
            array_fill_keys($attempted, [
                'Bu teklif "'.$quote->status.'" durumunda olduğu için tutarını etkileyen alanlar '.
                'değiştirilemez. Değişiklik için POST /api/quotes/{quote}/revise ile revizyon oluşturun.',
            ]),
        );
    }

    /**
     * Durum değişikliğini gerekçesiyle birlikte denetim izine yazar.
     *
     * `reason` için KOLON AÇILMADI: gerekçe, doğal yeri olan `activity_log`'a
     * düşer — orada zaten "kim, ne zaman, hangi durumdan hangisine" bilgisi
     * vardır ve Faz 5'in log ekranı bunu gösterir. Ayrı bir kolon yalnızca SON
     * gerekçeyi tutabilirdi; log tüm geçmişi tutar.
     */
    protected function logStatusChange(Quote $quote, string $from, string $to, ?string $reason): void
    {
        $properties = [
            'quote_id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'from' => $from,
            'to' => $to,
            'total' => (float) $quote->total,
            'currency' => $quote->currency,
        ];

        if ($reason !== null && $reason !== '') {
            $properties['reason'] = $reason;
        }

        if ($quote->deal_id !== null) {
            // Faz 10'un "teklif kabul edildi" bildirimi bu özellikten fırsatı
            // bulacak; deal'a OTOMATİK yazma yapılmaz (bkz. changeStatus()).
            $properties['deal_id'] = $quote->deal_id;
        }

        activity('crm')
            ->performedOn($quote)
            ->withProperties($properties)
            ->log("quotes.{$to}");
    }

    protected function isQuoteNumberCollision(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000'
            && str_contains($e->getMessage(), 'quote_number');
    }

    protected function defaultValidUntil(): string
    {
        $days = (int) (Setting::get('quote.validity_days') ?? 30);

        return now()->addDays(max(1, $days))->toDateString();
    }

    protected function defaultTerms(): ?string
    {
        $terms = Setting::get('quote.terms');

        return $terms === null ? null : (string) $terms;
    }

    protected function defaultTaxRate(): float
    {
        return (float) (Setting::get('quote.default_tax_rate') ?? 20);
    }

    /**
     * ROADMAP standardındaki hata zarfı (bootstrap/app.php'nin
     * `HttpResponseException` kuralı sayesinde olduğu gibi geçer).
     *
     * @param  array<string, array<int, string>>  $fields
     *
     * @throws HttpResponseException
     */
    protected function deny(string $message, string $code, array $fields): never
    {
        throw new HttpResponseException(response()->json([
            'errors' => [
                'message' => $message,
                'code' => $code,
                'fields' => $fields,
            ],
        ], 422));
    }
}
