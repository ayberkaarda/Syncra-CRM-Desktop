<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\AssignLeadRequest;
use App\Http\Requests\Leads\CheckDuplicatesRequest;
use App\Http\Requests\Leads\ConvertLeadRequest;
use App\Http\Requests\Leads\IndexLeadRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\ContactResource;
use App\Http\Resources\DuplicateCandidateResource;
use App\Http\Resources\LeadResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Leads\DuplicateDetector;
use App\Services\Leads\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * İnce controller: yetkilendirme (Policy) + Form Request doğrulaması +
 * LeadService devri. İş mantığı burada değil, LeadService/LeadRepository
 * içinde yer alır.
 */
class LeadController extends Controller
{
    public function __construct(protected LeadService $leads) {}

    public function index(IndexLeadRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $paginator = $this->leads->list($request->filters());

        return response()->json([
            'data' => LeadResource::collection($paginator->items()),
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

    public function store(StoreLeadRequest $request): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $data = $request->validated();

        // Bilgi amaçlı: istemci daha önce /check-duplicates ile sormamış
        // olabilir. Bu ZORLAYICI değildir, sadece bir uyarıdır. KAYIT
        // OLUŞMADAN ÖNCE çalıştırılmalı — yoksa lead kendi kendisiyle
        // eşleşir ve her oluşturma "duplicate" görünür.
        $warnings = $this->leads->findDuplicateWarnings([
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'company_name' => $data['company_name'] ?? null,
        ]);

        $lead = $this->leads->create($data);

        $meta = [];

        if ($warnings->isNotEmpty()) {
            $meta['duplicate_warning'] = DuplicateCandidateResource::collection($warnings);
        }

        $resource = new LeadResource($lead);

        if (! empty($meta)) {
            $resource = $resource->additional(['meta' => $meta]);
        }

        return $resource->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Lead $lead): JsonResponse
    {
        Gate::authorize('view', $lead);

        $lead = $this->leads->find($lead->id);

        $this->loadRelatedRecords($lead);

        return (new LeadResource($lead))->response();
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        Gate::authorize('update', $lead);

        $lead = $this->leads->update($lead, $request->validated());

        return (new LeadResource($lead))->response();
    }

    public function destroy(Lead $lead): JsonResponse
    {
        Gate::authorize('delete', $lead);

        $this->leads->delete($lead);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function checkDuplicates(CheckDuplicatesRequest $request): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $candidates = app(DuplicateDetector::class)
            ->findCandidates($request->duplicateInput(), $request->excludeLeadId());

        return DuplicateCandidateResource::collection($candidates)->response();
    }

    public function convert(ConvertLeadRequest $request, Lead $lead): JsonResponse
    {
        Gate::authorize('convert', $lead);

        $result = $this->leads->convert($lead, $request->validated(), $request->user());

        return response()->json([
            'data' => [
                'contact' => $this->wrapIfResourceExists(ContactResource::class, $result['contact'] ?? null),
                'company' => $this->wrapIfResourceExists(CompanyResource::class, $result['company'] ?? null),
                'deal' => $result['deal'] ?? null,
                'lead' => new LeadResource($this->leads->find($result['lead']->id)),
            ],
        ]);
    }

    public function assign(AssignLeadRequest $request, Lead $lead): JsonResponse
    {
        Gate::authorize('assign', $lead);

        $lead = $this->leads->assign($lead, (int) $request->validated()['owner_id']);

        return (new LeadResource($lead))->response();
    }

    /**
     * C şeridinin `ContactResource`/`CompanyResource`'ı henüz yoksa dönüşüm
     * sonucundaki model yine de düz dizi olarak (`toArray()`) döner —
     * uç 500 vermez, sadece sarmalama daha az zengindir.
     */
    protected function wrapIfResourceExists(string $resourceClass, mixed $model): mixed
    {
        if ($model === null) {
            return null;
        }

        if (class_exists($resourceClass)) {
            return new $resourceClass($model);
        }

        return $model->toArray();
    }

    /**
     * =========================================================================
     * Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli (docs/PHASE-INTL.md
     * §3, docs/PHASE-AUDIT.md §5.1 C3 satırı)
     * =========================================================================
     *
     * DİKKAT — bu, PHASE-AUDIT §5.1'in "en az şu çiftler" listesinde YOK:
     * bulk listede hiçbir `lead ↔ X` çifti tarif edilmiyor. Yine de
     * `LeadDetailPage` `Bağlanacak sayfalar`da adı geçen bir sayfa ve şemada
     * GERÇEKTEN VAR OLAN tek yön bu: `Lead belongsTo convertedContact/
     * convertedCompany/convertedDeal` (dönüşüm sonrası doğan FK'ler).
     * `LeadResource` bugün bu alanları yalnız çıplak ID olarak döndürüyor
     * (`converted_contact_id` vb.) ve `LeadDetailPage` bağlantı metnini
     * jenerik bir etiketle ("Kişiye git") basıyor — hedefin adını/başlığını
     * hiç göstermiyor. Bu yüzden ismi taşıyan tam nesneyi de ekliyoruz.
     *
     * TERS YÖN YOK: `contacts`/`companies`/`deals` tablolarında bir
     * `lead_id`/`converted_from_lead_id` kolonu YOK (bkz. migration taraması)
     * — yani "bu kişi hangi lead'den geldi" sorusu şemada cevaplanamıyor.
     * UYDURULMADI, atlandı.
     *
     * Yetki: her alt-alan yalnızca ilgili modülün `view` Policy'si `true`
     * dönerse (ve lead gerçekten o alana dönüştürüldüyse) yüklenir.
     */
    private function loadRelatedRecords(Lead $lead): void
    {
        // Lead::convertedContact()/convertedCompany()/convertedDeal() GERÇEK
        // BelongsTo ilişkileridir (Models dosyasına dokunulmadı) — `load()` ile
        // eager-load edilir, `Contact`/`Company`/`Deal` import'ları yalnızca
        // `Gate::allows('viewAny', ...)` çağrısı için gerekli.
        if ($lead->converted_contact_id !== null && Gate::allows('viewAny', Contact::class)) {
            $lead->load(['convertedContact' => fn ($q) => $q->select(['id', 'first_name', 'last_name'])]);
        }

        if ($lead->converted_company_id !== null && Gate::allows('viewAny', Company::class)) {
            $lead->load(['convertedCompany' => fn ($q) => $q->select(['id', 'name'])]);
        }

        if ($lead->converted_deal_id !== null && Gate::allows('viewAny', Deal::class)) {
            $lead->load(['convertedDeal' => fn ($q) => $q->select(['id', 'title'])]);
        }
    }
}
