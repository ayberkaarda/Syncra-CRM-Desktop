// Auth katmanı paylaşılan tipleri — backend sözleşmesiyle birebir eşleşir
// (bkz. Faz 2 / Dalga 1 görev tanımı).

export type Role = {
  id: number
  name: string
}

export type User = {
  id: number
  name: string
  email: string
  department: string | null
  /** Kişisel arayüz dili (`tr`/`en`/`de`/`fr`) — pre-auth localStorage seçiminin OTORİTESİ (§1.3). */
  locale: string
  /** Kişisel görüntüleme para birimi (İz E; şema aynı göçte eklendi — PHASE-INTL §2.3). */
  preferred_currency: string
  is_active: boolean
  must_change_password: boolean
  last_login_at: string | null
  created_at: string
  role: Role | null
  /** Örnek: ["users.view", "deals.create", ...] */
  permissions: string[]
}

export type LoginPayload = {
  email: string
  password: string
  remember?: boolean
}

export type ForgotPasswordPayload = {
  email: string
}

/** `PATCH /api/me/preferences` — iki alan da opsiyonel, gönderilen doğrulanır. */
export type UpdatePreferencesPayload = {
  locale?: string
  preferred_currency?: string
}

export type ChangePasswordPayload = {
  current_password: string
  password: string
  password_confirmation: string
}

/** Tüm uçların döndürdüğü hata gövdesi şekli: `{ errors: { message, code, fields? } }`. */
export type ApiError = {
  errors: {
    message: string
    code: string
    fields?: Record<string, string[]>
  }
}
