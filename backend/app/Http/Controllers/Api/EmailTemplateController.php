<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreEmailTemplateRequest;
use App\Http\Requests\Settings\UpdateEmailTemplateRequest;
use App\Http\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Services\Settings\EmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `/api/settings/email-templates*` — `settings.manage`.
 *
 * BU FAZDA E-POSTA GÖNDERİLMEZ: şablonlar saklanır ve önizlenir
 * (`MAIL_MAILER=log`, kapalı devre). `/send` ya da `/test` gibi bir uç
 * KASITLI olarak yoktur — gerekçe EmailTemplateService sınıf dokümanında.
 *
 * ÖNİZLEME UCU DA YOK: önizleme, şablon gövdesindeki `{{ degisken }}`
 * yer tutucularının örnek değerlerle değiştirilmesinden ibarettir ve yanıt
 * `variables` listesini zaten taşır. Sunucuya gidip gelmek, kullanıcı
 * yazarken canlı önizleme göstermeyi imkânsız kılardı (her tuşta bir istek).
 */
class EmailTemplateController extends Controller
{
    public function __construct(protected EmailTemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json([
            'data' => EmailTemplateResource::collection(
                $this->templates->list($request->boolean('include_inactive', true))
            ),
        ]);
    }

    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        $this->authorizeSettings();

        $template = $this->templates->create($request->validated());

        return (new EmailTemplateResource($template))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorizeSettings();

        $template = $this->templates->update($emailTemplate, $request->validated());

        return (new EmailTemplateResource($template))->response();
    }

    /**
     * Gerçek silme (CustomField'dan kasıtlı fark): şablona bağlı hiçbir kayıt
     * yoktur, dolayısıyla silinen bir şablon yanında veri götürmez. Geçici
     * olarak devre dışı bırakmak isteyen `PATCH ... { "is_active": false }`
     * kullanır.
     */
    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorizeSettings();

        $this->templates->delete($emailTemplate);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    protected function authorizeSettings(): void
    {
        abort_unless(Gate::allows('settings.manage'), Response::HTTP_FORBIDDEN);
    }
}
