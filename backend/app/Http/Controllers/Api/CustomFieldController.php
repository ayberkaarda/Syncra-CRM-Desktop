<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCustomFieldRequest;
use App\Http\Requests\Settings\UpdateCustomFieldRequest;
use App\Http\Resources\CustomFieldResource;
use App\Models\CustomField;
use App\Services\Settings\CustomFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Özel alan TANIMLARI — İki uç ailesi, tek controller
 * (PipelineStageController ile aynı desen ve aynı gerekçe).
 *
 * -----------------------------------------------------------------------------
 * `GET /api/custom-fields?entity_type=leads` (Faz 6) — İZİN YOK
 * -----------------------------------------------------------------------------
 * Bu bir FORM ŞEMASI ucudur: "lead formunda hangi ek alanlar var". Kimliği
 * doğrulanmış her kullanıcı erişebilir; asıl veri koruması ilgili modülün
 * kendi `.view` izninde. `entity_type` ZORUNLUDUR ve yalnızca AKTİF alanlar
 * döner — pasif bir alan forma çizilmemelidir.
 *
 * -----------------------------------------------------------------------------
 * `/api/settings/custom-fields*` (Faz 10) — `settings.manage`
 * -----------------------------------------------------------------------------
 * Alan EDİTÖRÜ. Aynı satırlar, farklı yetki alanı ve farklı varsayılanlar:
 * `entity_type` OPSİYONELDİR (ekran tüm kayıt tiplerini tek tabloda gösterir)
 * ve PASİF alanlar da döner — pasif bir alan yalnızca oradan geri açılabilir.
 *
 * Faz 6 ucunun davranışı hiç değişmedi; yeni davranış rota adına göre ayrılır.
 */
class CustomFieldController extends Controller
{
    /**
     * @var array<int, string>
     */
    public const ENTITY_TYPES = ['leads', 'contacts', 'companies', 'deals', 'tickets', 'products'];

    protected const SETTINGS_INDEX_ROUTE = 'settings.custom-fields.index';

    public function __construct(protected CustomFieldService $customFields) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->routeIs(self::SETTINGS_INDEX_ROUTE)) {
            return $this->settingsIndex($request);
        }

        $validated = $request->validate([
            'entity_type' => ['required', Rule::in(self::ENTITY_TYPES)],
        ], [
            'entity_type.required' => 'entity_type parametresi zorunludur.',
            'entity_type.in' => 'Geçersiz entity_type. Geçerli değerler: '.implode('|', self::ENTITY_TYPES),
        ]);

        $fields = CustomField::query()
            ->forEntity($validated['entity_type'])
            ->active()
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => CustomFieldResource::collection($fields),
        ]);
    }

    /**
     * `POST /api/settings/custom-fields`.
     */
    public function store(StoreCustomFieldRequest $request): JsonResponse
    {
        $this->authorizeSettings();

        $field = $this->customFields->create($request->validated());

        return (new CustomFieldResource($field))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * `PATCH /api/settings/custom-fields/{customField}`.
     */
    public function update(UpdateCustomFieldRequest $request, CustomField $customField): JsonResponse
    {
        $this->authorizeSettings();

        $field = $this->customFields->update($customField, $request->validated());

        return (new CustomFieldResource($field))->response();
    }

    /**
     * `DELETE /api/settings/custom-fields/{customField}` — SİLMEZ,
     * PASİFLEŞTİRİR. `custom_field_values` satırlarına dokunulmaz; alan
     * yeniden aktifleştirilirse eski veri olduğu gibi geri gelir (gerekçe:
     * CustomFieldService sınıf dokümanı).
     *
     * Bu yüzden yanıt 204 DEĞİL 200 + kaydın kendisidir: uç bir kaydı yok
     * etmez, DURUMUNU değiştirir — ve istemcinin listedeki satırı silmek
     * yerine "pasif" olarak yeniden çizebilmesi için güncel hâli gerekir.
     */
    public function destroy(CustomField $customField): JsonResponse
    {
        $this->authorizeSettings();

        return (new CustomFieldResource($this->customFields->deactivate($customField)))->response();
    }

    /**
     * Ayarlar ekranının listesi.
     */
    protected function settingsIndex(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        $validated = $request->validate([
            'entity_type' => ['sometimes', Rule::in(self::ENTITY_TYPES)],
        ], [
            'entity_type.in' => 'Geçersiz entity_type. Geçerli değerler: '.implode('|', self::ENTITY_TYPES),
        ]);

        $fields = $this->customFields->list(
            $validated['entity_type'] ?? null,
            $request->boolean('include_inactive', true),
        );

        return response()->json([
            'data' => CustomFieldResource::collection($fields),
            'meta' => [
                'entity_types' => self::ENTITY_TYPES,
                'types' => CustomFieldService::TYPES,
                'option_types' => CustomFieldService::OPTION_TYPES,
            ],
        ]);
    }

    protected function authorizeSettings(): void
    {
        abort_unless(Gate::allows('settings.manage'), Response::HTTP_FORBIDDEN);
    }
}
