<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\StoreAutomationRuleRequest;
use App\Http\Requests\Automation\UpdateAutomationRuleRequest;
use App\Http\Resources\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `/api/settings/automation-rules*` — Faz 14 / İz F, C4 (docs/PHASE-INTL.md §3).
 *
 * İnce controller: Form Request şema doğrulaması + Policy (izin-eşlemeli, bkz.
 * `AutomationRulePolicy`) + servis devri. Diğer Ayarlar controller'larından (settings.manage
 * tek satır Gate) FARKLI olarak burada GERÇEK bir Policy sınıfı var — çünkü yetkilendirme
 * tek bir sabit izne değil, İSTEKTEKİ trigger/action seçimine bağlı (PHASE-AUDIT §5.4).
 */
class AutomationRuleController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', AutomationRule::class);

        $rules = AutomationRule::query()->with('creator')->latest()->get();

        return response()->json([
            'data' => AutomationRuleResource::collection($rules),
            'meta' => [
                'triggers' => AutomationCatalog::TRIGGERS,
                'actions' => AutomationCatalog::ACTIONS,
                'title_placeholders' => AutomationCatalog::TITLE_PLACEHOLDERS,
            ],
        ]);
    }

    public function store(StoreAutomationRuleRequest $request): JsonResponse
    {
        $data = $request->validated();

        Gate::authorize('create', [
            AutomationRule::class,
            $data['trigger_type'],
            $data['trigger_config'],
            $data['action_type'],
            $data['action_config'],
        ]);

        $rule = AutomationRule::create([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
            'trigger_type' => $data['trigger_type'],
            'trigger_config' => $data['trigger_config'],
            'action_type' => $data['action_type'],
            'action_config' => $data['action_config'],
            'created_by' => $request->user()->getKey(),
        ]);

        $rule->load('creator');

        return (new AutomationRuleResource($rule))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAutomationRuleRequest $request, AutomationRule $automationRule): JsonResponse
    {
        $data = $request->validated();

        $touchesConfig = array_key_exists('trigger_type', $data) || array_key_exists('action_type', $data);

        if ($touchesConfig) {
            Gate::authorize('update', [
                $automationRule,
                $data['trigger_type'] ?? $automationRule->trigger_type,
                $data['action_type'] ?? $automationRule->action_type,
                $data['action_config'] ?? $automationRule->action_config,
            ]);
        } else {
            Gate::authorize('toggle', $automationRule);
        }

        $automationRule->update($data);
        $automationRule->load('creator');

        return (new AutomationRuleResource($automationRule))->response();
    }

    public function destroy(AutomationRule $automationRule): JsonResponse
    {
        Gate::authorize('delete', $automationRule);

        $automationRule->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
