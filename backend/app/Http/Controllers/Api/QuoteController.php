<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quotes\CalculateQuoteRequest;
use App\Http\Requests\Quotes\IndexQuoteRequest;
use App\Http\Requests\Quotes\StatusQuoteRequest;
use App\Http\Requests\Quotes\StoreQuoteRequest;
use App\Http\Requests\Quotes\UpdateQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Quote;
use App\Services\Quotes\QuoteCalculationException;
use App\Services\Quotes\QuoteCalculator;
use App\Services\Quotes\QuotePdfService;
use App\Services\Quotes\QuoteService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * QuoteService devri. İş mantığı burada DEĞİL — para hesabı QuoteCalculator,
 * durum geçişleri QuoteStatusMachine, sorgular QuoteRepository içindedir.
 *
 * KALEMLER İÇİN AYRI UÇ YOKTUR: kalemler teklifin bir parçasıdır ve
 * `POST /api/quotes` ile `PATCH /api/quotes/{quote}` gövdesindeki `items`
 * dizisiyle bir bütün olarak gönderilir. Ayrı bir
 * `POST /api/quotes/{quote}/items` ucu, her kalem ekleme/silme işleminden
 * sonra teklifin toplamlarını ayrıca yeniden hesaplamayı ve iki isteğin
 * arasında toplamı kalemleriyle çelişen bir teklif bırakmayı gerektirirdi.
 */
class QuoteController extends Controller
{
    public function __construct(protected QuoteService $quotes) {}

    public function index(IndexQuoteRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Quote::class);

        $paginator = $this->quotes->list($request->filters());

        return response()->json([
            'data' => QuoteResource::collection($paginator->items()),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * `POST /api/quotes/calculate` — KALICI OLMAYAN canlı hesap ucu.
     *
     * -------------------------------------------------------------------
     * NEDEN SUNUCUDA: TEK DOĞRULUK KAYNAĞI
     * -------------------------------------------------------------------
     * Teklif formunda kullanıcı kalem eklerken canlı toplam görmek ister.
     * Bunu istemcide hesaplamak, docs/QUOTE-FINANCIALS.md §3'teki KDV oranı
     * grubu bazlı "en büyük kalan" indirim dağıtımını JavaScript'te İKİNCİ
     * KEZ uygulamak demektir — ve iki uygulamanın farkı ancak biri diğerini
     * yanlışlarken, yani müşteriye yanlış tutar gittikten sonra görülür.
     *
     * Satır toplamı (`miktar × fiyat × (1 - indirim)`) istemcide güvenle
     * hesaplanabilir; `subtotal`/`tax_amount`/`total` hesaplanamaz. Bu uç,
     * KAYDETMEDEN tam olarak kaydedilecek olan rakamları döndürür: form
     * ekranındaki toplam ile veritabanına yazılacak toplam aynı `bcmath`
     * kodundan gelir.
     *
     * HİÇBİR ŞEY KAYDETMEZ. Ne teklif, ne kalem, ne denetim izi satırı;
     * `QuoteService`e hiç uğramaz, doğrudan saf hesap sınıfını çağırır.
     * Yanıt, girdinin saf bir fonksiyonudur.
     *
     * -------------------------------------------------------------------
     * BOŞ `items` → 200, TÜM TOPLAMLAR 0 (422 DEĞİL)
     * -------------------------------------------------------------------
     * Karar: boş liste GEÇERLİ bir girdidir. Üç gerekçe:
     *  1. Kalemsiz teklif zaten geçerli bir kayıttır — `POST /api/quotes`
     *     onu kabul eder (yalnızca `/send` en az bir kalem ister). Hesap
     *     ucunun, kaydetme ucunun kabul ettiği bir durumu reddetmesi
     *     tutarsız olurdu.
     *  2. Form ilk açıldığında ve kullanıcı son satırı sildiğinde istemci bu
     *     ucu çağırır. 422 döndürmek, arayüzde tamamen meşru bir duruma
     *     kırmızı bir doğrulama hatası bastırırdı.
     *  3. QuoteCalculator zaten boş girdide sıfırlar döndürüyor ve bu davranış
     *     QuoteCalculatorTest ile kilitli — burada farklı davranmak, hesap
     *     sınıfıyla onu açan uç arasında sözleşme farkı yaratırdı.
     *
     * `items` boşken `discount_value > 0` gönderilirse 422 döner: indirim
     * ara toplamı (0) aşamaz. Bu kural da hesap sınıfının kendisinden gelir,
     * burada tekrarlanmaz.
     *
     * -------------------------------------------------------------------
     * İZİN: `quotes.create` VEYA `quotes.update`
     * -------------------------------------------------------------------
     * İkisinden BİRİ yeterlidir. `quotes.create` tek başına yetmezdi:
     * gönderilmemiş bir teklifi düzenleyen kullanıcı da canlı toplam görmek
     * zorundadır ve rol yönetimi (Faz 2) çalışma anında "güncelleyebilir ama
     * oluşturamaz" bir rol tanımlanmasına izin verir — o kullanıcının
     * düzenleme formu sessizce 403 alırdı.
     *
     * Yalnızca `quotes.view` ise fazla gevşek olurdu: bu uç "teklif oku"
     * değil "teklif KURGULA" eylemine aittir. Yanıtın kendisi bir sır
     * taşımasa da (girdinin saf fonksiyonudur), yetki sözlüğünün bir eylemi
     * doğru isimle karşılaması, ileride "kim teklif hazırlayabilir"
     * sorusunun tek yerden cevaplanmasını sağlar.
     *
     * `Gate::authorize` YERİNE `canAny`: bu uç bir Quote ÖRNEĞİ üzerinde
     * çalışmaz ve QuotePolicy'de karşılığı olan bir yetenek yoktur ("iki
     * izinden biri" bir policy metodu değil, düz bir izin sorusudur). Policy
     * deseni tercih edilirse `QuotePolicy::calculate()` üç satırlık bir
     * eklemedir; o dosya bu turda başka bir sahibin.
     */
    public function calculate(CalculateQuoteRequest $request): JsonResponse
    {
        if (! $request->user()->canAny(['quotes.create', 'quotes.update'])) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validated();

        try {
            $result = QuoteCalculator::calculate(
                $validated['items'] ?? [],
                $validated['discount_value'] ?? 0,
                (string) ($validated['discount_type'] ?? QuoteCalculator::DISCOUNT_AMOUNT),
            );
        } catch (QuoteCalculationException $e) {
            // QuoteService::deny() ile AYNI zarf (ROADMAP standardı).
            // Kasıtlı küçük tekrar: bu uç QuoteService'e hiç uğramadığı için
            // oradaki `protected deny()`'e erişemez. Üçüncü bir çağıran
            // çıkarsa doğru hamle, zarfı paylaşılan bir exception renderer'a
            // taşımaktır — iki çağıran için ayrı bir soyutlama erken olurdu.
            throw new HttpResponseException(response()->json([
                'errors' => [
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode,
                    'fields' => $e->fields,
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return response()->json(['data' => $result]);
    }

    public function store(StoreQuoteRequest $request): JsonResponse
    {
        Gate::authorize('create', Quote::class);

        $quote = $this->quotes->create($request->validated(), (int) $request->user()->id);

        return (new QuoteResource($quote))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Quote $quote): JsonResponse
    {
        Gate::authorize('view', $quote);

        $quote = $this->quotes->find((int) $quote->id);

        $this->loadRelatedRecords($quote);

        return (new QuoteResource($quote))->response();
    }

    public function update(UpdateQuoteRequest $request, Quote $quote): JsonResponse
    {
        Gate::authorize('update', $quote);

        $quote = $this->quotes->update($quote, $request->validated());

        return (new QuoteResource($quote))->response();
    }

    /**
     * Kabul veya red edilmiş teklifler silinemez — QuotePolicy::delete()
     * bunu reddedip Gate::authorize üzerinden 403 üretir (gerekçe o
     * policy'nin dokümanında).
     */
    public function destroy(Quote $quote): JsonResponse
    {
        Gate::authorize('delete', $quote);

        $this->quotes->delete($quote);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * `POST /api/quotes/{quote}/send` — `draft → sent`.
     *
     * `quotes.send` izniyle korunur (`quotes.update` DEĞİL): gönderim ayrı
     * bir yetkidir ve izin sözlüğünde ayrı bir satırdır. Kalemi olmayan bir
     * teklif 422 `QUOTE_HAS_NO_ITEMS`, zaten gönderilmiş bir teklif 422
     * `INVALID_STATUS_TRANSITION` alır.
     */
    public function send(Quote $quote): JsonResponse
    {
        Gate::authorize('send', $quote);

        return (new QuoteResource($this->quotes->send($quote)))->response();
    }

    /**
     * `PATCH /api/quotes/{quote}/status` — durumun DEĞİŞTİĞİ (gönderim
     * dışındaki) TEK yer.
     *
     * `quotes.update` izniyle korunur; izin sözlüğünde `quotes.status` diye
     * bir satır yoktur ve teklifi sonuçlandırmak onu güncellemenin bir
     * biçimidir. Geçersiz geçiş 422 `INVALID_STATUS_TRANSITION` döner.
     */
    public function status(StatusQuoteRequest $request, Quote $quote): JsonResponse
    {
        Gate::authorize('update', $quote);

        $validated = $request->validated();

        $quote = $this->quotes->changeStatus(
            $quote,
            (string) $validated['status'],
            isset($validated['reason']) ? (string) $validated['reason'] : null,
        );

        return (new QuoteResource($quote))->response();
    }

    /**
     * `POST /api/quotes/{quote}/revise` — docs/QUOTE-FINANCIALS.md §6.
     *
     * `quotes.create` izniyle korunur (`quotes.update` DEĞİL): bu uç mevcut
     * kaydı DEĞİŞTİRMEZ, YENİ bir teklif oluşturur. Gönderilmiş bir teklifi
     * düzenleme yetkisi olan ama yeni teklif açma yetkisi olmayan bir
     * kullanıcının buradan yeni kayıt üretebilmesi, izin sözlüğünü anlamsız
     * kılardı.
     *
     * `draft` (zaten düzenlenebilir) ve `accepted` (kabul edilmiş taahhüt)
     * tekliflerde 422 `QUOTE_NOT_REVISABLE` döner. Parent'ın zaten bir
     * `draft` revizyonu varsa yeni kayıt AÇILMAZ, mevcut olan döner — bu
     * yüzden yanıt 201 değil 200'dür.
     */
    public function revise(Quote $quote): JsonResponse
    {
        Gate::authorize('create', Quote::class);

        return (new QuoteResource($this->quotes->revise($quote)))->response();
    }

    /**
     * `GET /api/quotes/{quote}/pdf` — teklif belgesinin PDF çıktısı.
     *
     * `quotes.view` izniyle korunur: PDF, detay ekranının basılabilir
     * hâlidir; teklifi görebilen onu yazdırabilmelidir.
     *
     * Üretim QuotePdfService'in (Faz 9 / C) işidir; burada yalnızca
     * yetkilendirme ve yanıt başlıkları vardır. `inline` disposition seçildi
     * (`attachment` değil): kullanıcı önce tarayıcıda gözden geçirmek ister,
     * indirme yine de tek tıktır.
     */
    public function pdf(Quote $quote): Response
    {
        Gate::authorize('view', $quote);

        $pdfs = app(QuotePdfService::class);
        $quote = $this->quotes->find((int) $quote->id);

        return response($pdfs->render($quote), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdfs->filename($quote).'"',
        ]);
    }

    /**
     * =========================================================================
     * Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli (docs/PHASE-INTL.md
     * §3, docs/PHASE-AUDIT.md §5.1 C3 satırı)
     * =========================================================================
     *
     * Bu şeridin kapattığı tek yön: `teklif → firma` / `teklif → fırsat` /
     * `teklif → kişi`. Ters yönler (`firma → teklifler`, `fırsat → teklifler`)
     * ZATEN CompanyController/DealController::loadRelatedRecords()'ta var —
     * burada TEKRARLANMAZ (bkz. o dosyaların "Quote → company/deal BASILMAZ"
     * notu, bu şerit onu kapatıyor). `teklif → kişi` yönünün bir eşleniği
     * (ContactController'da "kişi → teklifler") şu an YOK — bu şeridin dosya
     * sahipliği `ContactController`/`ContactResource`'ı kapsamıyor, o yön
     * atlandı (bkz. görev raporu).
     *
     * NEDEN YENİ SORGU YOK: `Quote::company()`/`deal()`/`contact()` GERÇEK
     * `BelongsTo` ilişkileridir ve `QuoteRepository::DETAIL_RELATIONS` zaten
     * `show()`'un çağırdığı `QuoteService::find()` içinde bunları `with()`
     * ile eager-load ediyor (bkz. o repository sabiti). Bu metot burada
     * SIFIR ek sorgu ekler — yalnızca zaten bellekte olan ilişkiyi, izinliyse,
     * `RelatedGroupData` sözleşmesine (`{total, items}`) sarar.
     *
     * Desen `LeadController::loadRelatedRecords()`'un tekil (to-one) ilişki
     * biçimiyle BİREBİR aynıdır (count() + limitli get() yerine tek satır —
     * zaten en fazla 1 kayıt olabilecek bir ilişki için o iki sorgu de
     * anlamsız olurdu): FK `null` değilse VE ilgili modülün `viewAny`
     * Policy'si (`Gate::allows`) `true` dönerse `setRelation()` ile sahte
     * "ilişki" eklenir; aksi halde `relationLoaded()` false kalır ve
     * `QuoteResource` o anahtarı `related` altına HİÇ KOYMAZ (§5.1 C1 ile
     * aynı kural — boş grup başlığı bile sızıntıdır).
     */
    private function loadRelatedRecords(Quote $quote): void
    {
        if ($quote->company_id !== null && $quote->relationLoaded('company') && $quote->company
            && Gate::allows('viewAny', Company::class)) {
            $quote->setRelation('relatedCompany', [
                'total' => 1,
                'items' => [[
                    'id' => $quote->company->id,
                    'name' => $quote->company->name,
                ]],
            ]);
        }

        if ($quote->deal_id !== null && $quote->relationLoaded('deal') && $quote->deal
            && Gate::allows('viewAny', Deal::class)) {
            $quote->setRelation('relatedDeal', [
                'total' => 1,
                'items' => [[
                    'id' => $quote->deal->id,
                    'title' => $quote->deal->title,
                ]],
            ]);
        }

        if ($quote->contact_id !== null && $quote->relationLoaded('contact') && $quote->contact
            && Gate::allows('viewAny', Contact::class)) {
            $quote->setRelation('relatedContact', [
                'total' => 1,
                'items' => [[
                    'id' => $quote->contact->id,
                    'full_name' => $quote->contact->full_name,
                ]],
            ]);
        }
    }
}
