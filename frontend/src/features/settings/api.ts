// Ayarlar modülü veri katmanı — ham istek fonksiyonları + query key sözlüğü. TanStack Query
// hook'ları (toast/cache orkestrasyonu) `hooks/` altında, ayrı dosyalarda (bkz. görev tanımı).
//
// Hata gövdesi tüm uçlarda `{ errors: { message, code, fields? } }` OLMASI GEREKİRDİ ama
// gerçek backend, bazı uçlarda (ör. `STAGE_HAS_OPEN_DEALS`, izin matrisi hataları) `code` ve
// ilgili ek alanları HEM `errors` içinde HEM kökte tekrar ediyor. `readErrorBody` bu iki
// katmanı birleştirip tek bir düz obje döner (kök öncelikli değil — `errors` her zaman en az
// kök kadar günceldir, ikisi de aynı değeri taşıyor; birleştirme yalnızca güvenlik ağı).
import axios from 'axios'
import { api } from '../../lib/axios'
import type {
  AutomationRule,
  AutomationRuleCreatePayload,
  AutomationRuleUpdatePayload,
  AutomationRulesResponse,
  CustomField,
  CustomFieldCreatePayload,
  CustomFieldUpdatePayload,
  CustomFieldsResponse,
  EmailTemplate,
  EmailTemplatePayload,
  ExchangeRate,
  ExchangeRatesResponse,
  ManualExchangeRatePayload,
  PermissionMatrix,
  PipelineStage,
  PipelineStageCreatePayload,
  PipelineStagePayload,
  RoleUpdateResult,
  SettingValue,
  SettingsResponse,
  StageHasOpenDealsPayload,
} from './types'

export const settingsKeys = {
  all: ['settings'] as const,
  settings: ['settings', 'list'] as const,
  pipelineStages: ['settings', 'pipeline-stages'] as const,
  customFields: ['settings', 'custom-fields'] as const,
  emailTemplates: ['settings', 'email-templates'] as const,
  permissionMatrix: ['settings', 'permission-matrix'] as const,
  exchangeRates: ['settings', 'exchange-rates'] as const,
  automationRules: ['settings', 'automation-rules'] as const,
  automationRuleUserOptions: ['settings', 'automation-rules', 'user-options'] as const,
}

// ---------------------------------------------------------------------------
// Hata gövdesi yardımcıları — `errors.*` ve kök seviye alanları birleştirip okur.
// ---------------------------------------------------------------------------

function readErrorBody(error: unknown): Record<string, unknown> | undefined {
  if (!axios.isAxiosError(error)) return undefined
  const body = error.response?.data as Record<string, unknown> | undefined
  if (!body) return undefined
  const nested = (body.errors as Record<string, unknown> | undefined) ?? {}
  // Kök seviye alanlar yalnızca `errors` içinde karşılığı yoksa fallback olarak eklenir.
  return { ...body, ...nested }
}

export function getErrorCode(error: unknown): string | undefined {
  const code = readErrorBody(error)?.code
  return typeof code === 'string' ? code : undefined
}

/** `STAGE_HAS_OPEN_DEALS` (422) gövdesini çıkarır; farklı bir hata/uçsa `null` döner. */
export function extractStageHasOpenDeals(error: unknown): StageHasOpenDealsPayload | null {
  const body = readErrorBody(error)
  if (!body || body.code !== 'STAGE_HAS_OPEN_DEALS') return null
  const openDealsCount = body.open_deals_count
  const availableStages = body.available_stages
  if (typeof openDealsCount !== 'number' || !Array.isArray(availableStages)) return null
  return { open_deals_count: openDealsCount, available_stages: availableStages as { id: number; name: string }[] }
}

/** `STAGE_IS_SYSTEM` (422) — `is_won`/`is_lost` aşamayı pasifleştirme denemesi. */
export function isStageSystemError(error: unknown): boolean {
  return getErrorCode(error) === 'STAGE_IS_SYSTEM'
}

/** `UNKNOWN_PERMISSION` (422) gövdesindeki `unknown_permissions` listesi. */
export function extractUnknownPermissions(error: unknown): string[] | null {
  const body = readErrorBody(error)
  if (!body || body.code !== 'UNKNOWN_PERMISSION') return null
  const list = body.unknown_permissions
  return Array.isArray(list) ? (list as string[]) : []
}

// ---------------------------------------------------------------------------
// Genel ayarlar (Şirket Profili sekmesi bu grubu kullanır)
// ---------------------------------------------------------------------------

export async function fetchSettings(): Promise<SettingsResponse> {
  const { data } = await api.get<SettingsResponse>('/api/settings')
  return data
}

/** Gövde `{"company.name": "...", "quote.validity_days": 30, ...}` — yalnızca değişen
 *  anahtarlar, `type`'a uygun GERÇEK tipte (string/number/boolean/nesne). Yanıt `GET` ile AYNI
 *  şekli (tüm liste + `meta.groups`) döner, tekil kayıt DEĞİL. */
export async function updateSettingsRequest(patch: Record<string, SettingValue>): Promise<SettingsResponse> {
  const { data } = await api.patch<SettingsResponse>('/api/settings', patch)
  return data
}

// ---------------------------------------------------------------------------
// Pipeline aşamaları
// ---------------------------------------------------------------------------

export async function fetchPipelineStages(): Promise<PipelineStage[]> {
  const { data } = await api.get<{ data: PipelineStage[] }>('/api/settings/pipeline-stages')
  return data.data
}

/** `position` / `is_active` GÖNDERİLMEZ — backend 422 döner (yeni aşama otomatik sona
 *  eklenir ve her zaman aktif başlar). */
export async function createPipelineStageRequest(payload: PipelineStageCreatePayload): Promise<PipelineStage> {
  const { data } = await api.post<{ data: PipelineStage }>('/api/settings/pipeline-stages', payload)
  return data.data
}

export async function updatePipelineStageRequest(id: number, payload: PipelineStagePayload): Promise<PipelineStage> {
  const { data } = await api.patch<{ data: PipelineStage }>(`/api/settings/pipeline-stages/${id}`, payload)
  return data.data
}

/** Gövde TÜM aşamaları (pasifler dahil) içermeli — eksikse backend 422
 *  `STAGE_REORDER_INCOMPLETE` döner. Çağıran taraf filtrelenmemiş tam listeyi göndermeli. */
export async function reorderPipelineStagesRequest(orderedIds: number[]): Promise<PipelineStage[]> {
  const { data } = await api.post<{ data: PipelineStage[] }>('/api/settings/pipeline-stages/reorder', {
    ordered_ids: orderedIds,
  })
  return data.data
}

// ---------------------------------------------------------------------------
// Özel alanlar
// ---------------------------------------------------------------------------

export async function fetchCustomFields(): Promise<CustomFieldsResponse> {
  const { data } = await api.get<CustomFieldsResponse>('/api/settings/custom-fields')
  return data
}

export async function createCustomFieldRequest(payload: CustomFieldCreatePayload): Promise<CustomField> {
  const { data } = await api.post<{ data: CustomField }>('/api/settings/custom-fields', payload)
  return data.data
}

/** `entity_type`/`key` bu payload'ta hiç YOK — düzenleme formu ikisini de göndermez (bkz.
 *  `types.ts` → `CustomFieldUpdatePayload`). */
export async function updateCustomFieldRequest(id: number, payload: CustomFieldUpdatePayload): Promise<CustomField> {
  const { data } = await api.patch<{ data: CustomField }>(`/api/settings/custom-fields/${id}`, payload)
  return data.data
}

/** Silme DEĞİL pasifleştirme — backend `is_active=false` yapar, kaydı kaldırmaz. */
export async function deactivateCustomFieldRequest(id: number): Promise<void> {
  await api.delete(`/api/settings/custom-fields/${id}`)
}

// ---------------------------------------------------------------------------
// E-posta şablonları
// ---------------------------------------------------------------------------

export async function fetchEmailTemplates(): Promise<EmailTemplate[]> {
  const { data } = await api.get<{ data: EmailTemplate[] }>('/api/settings/email-templates')
  return data.data
}

export async function createEmailTemplateRequest(payload: EmailTemplatePayload): Promise<EmailTemplate> {
  const { data } = await api.post<{ data: EmailTemplate }>('/api/settings/email-templates', payload)
  return data.data
}

export async function updateEmailTemplateRequest(id: number, payload: EmailTemplatePayload): Promise<EmailTemplate> {
  const { data } = await api.patch<{ data: EmailTemplate }>(`/api/settings/email-templates/${id}`, payload)
  return data.data
}

/** ÖZEL ALANLARIN/AŞAMALARIN AKSİNE bu GERÇEK bir silmedir (204, kayıt kalıcı olarak
 *  kaldırılır) — pasifleştirme DEĞİL. Şablonun `is_active` alanı ayrı bir kavramdır. */
export async function deleteEmailTemplateRequest(id: number): Promise<void> {
  await api.delete(`/api/settings/email-templates/${id}`)
}

// ---------------------------------------------------------------------------
// Rol / izin matrisi
// ---------------------------------------------------------------------------

export async function fetchPermissionMatrix(): Promise<PermissionMatrix> {
  const { data } = await api.get<{ data: PermissionMatrix }>('/api/settings/permission-matrix')
  return data.data
}

/** Gövde TAM SENKRON — delta DEĞİL. `[]` geçerlidir (rolün tüm izinlerini kaldırır). Yanıt
 *  tüm matris değil, yalnızca güncellenen rol (`RoleResource`). */
export async function updateRolePermissionsRequest(roleId: number, permissions: string[]): Promise<RoleUpdateResult> {
  const { data } = await api.patch<{ data: RoleUpdateResult }>(`/api/settings/roles/${roleId}/permissions`, {
    permissions,
  })
  return data.data
}

// ---------------------------------------------------------------------------
// Kur (döviz) — Faz 14 / İz E (docs/PHASE-INTL.md §2.1, §2.6)
// ---------------------------------------------------------------------------

/** Desteklenen HER para birimi için bir satır döner (henüz kur girilmemişse `rate: null`,
 *  bkz. `types.ts` → `ExchangeRateRow`). */
export async function fetchExchangeRates(): Promise<ExchangeRatesResponse> {
  const { data } = await api.get<ExchangeRatesResponse>('/api/settings/exchange-rates')
  return data
}

/** Aynı gün + aynı para birimi için ikinci giriş UPSERT'tir (backend `unique(currency,
 *  rate_date)` üzerinden) — istemci tarafında ayrı bir "zaten var" kontrolü GEREKMEZ. */
export async function createManualExchangeRateRequest(payload: ManualExchangeRatePayload): Promise<ExchangeRate> {
  const { data } = await api.post<{ data: ExchangeRate }>('/api/settings/exchange-rates', payload)
  return data.data
}

// ---------------------------------------------------------------------------
// Otomasyon kuralları — Faz 14 / İz F, Attio C4 (docs/PHASE-INTL.md §3)
// ---------------------------------------------------------------------------

export async function fetchAutomationRules(): Promise<AutomationRulesResponse> {
  const { data } = await api.get<AutomationRulesResponse>('/api/settings/automation-rules')
  return data
}

export async function createAutomationRuleRequest(payload: AutomationRuleCreatePayload): Promise<AutomationRule> {
  const { data } = await api.post<{ data: AutomationRule }>('/api/settings/automation-rules', payload)
  return data.data
}

export async function updateAutomationRuleRequest(id: number, payload: AutomationRuleUpdatePayload): Promise<AutomationRule> {
  const { data } = await api.patch<{ data: AutomationRule }>(`/api/settings/automation-rules/${id}`, payload)
  return data.data
}

/** GERÇEK silme (204) — e-posta şablonlarıyla AYNI semantik, pasifleştirme DEĞİL. */
export async function deleteAutomationRuleRequest(id: number): Promise<void> {
  await api.delete(`/api/settings/automation-rules/${id}`)
}

export type AutomationUserOption = { id: number; name: string }

/** `/api/users` `users.view` ister — izni olmayan kullanıcı 403 alır; çağıran taraf
 *  `isForbidden` ile "sabit kullanıcı" seçeneğini gizler (bkz. `useDealOwnerOptions`
 *  ile AYNI desen, `features/deals/api/boardApi.ts`). */
export async function fetchAutomationUserOptions(): Promise<AutomationUserOption[]> {
  const { data } = await api.get<{ data: AutomationUserOption[] }>('/api/users', { params: { per_page: 100 } })
  return data.data
}
