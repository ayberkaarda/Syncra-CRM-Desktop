// Şifre politikası ön kontrolü (istemci tarafı) + kriptografik olarak güvenli şifre üretici.
// Asıl doğrulama daima backend'de yapılır; burası yalnızca canlı UX geri bildirimi içindir.
//
// Faz 14 / İz D: kurallar/güç için ANAHTAR taşınır (bkz. `activityTypeMeta.ts`'teki aynı
// gerekçe) — bu dosya saf bir yardımcı (React/i18next bağımsız), çeviri tüketici bileşende
// (`UserFormModal`/`ResetPasswordModal`/`ChangePasswordPage`) `users:passwordRules.*` /
// `users:passwordStrength.*` anahtarlarıyla `rule.id`/`PASSWORD_STRENGTH_KEYS[score]` üzerinden
// çözülür. Miras `rule.label`/`strengthLabel` SABİT TÜRKÇE alanları (dalga 1'de yalnızca
// `ChangePasswordPage.tsx` onları tükettiği için bırakılmıştı) bu fazda KALDIRILDI — o sayfa da
// artık `rule.id` + çeviri anahtarına geçti (bkz. `ChangePasswordPage.tsx`).
export type PasswordRuleId = 'length' | 'upper' | 'lower' | 'digit' | 'special'

export type PasswordRule = {
  id: PasswordRuleId
  met: boolean
}

/** 0-5 arası, karşılanan kural sayısı — `users:passwordStrength.*` anahtarlarıyla aynı sırada. */
export type PasswordStrengthScore = 0 | 1 | 2 | 3 | 4 | 5

export type PasswordEvaluation = {
  rules: PasswordRule[]
  score: PasswordStrengthScore
  isValid: boolean
}

export const PASSWORD_MIN_LENGTH = 12

/** `users:passwordStrength.*` anahtarları — `score`'a göre sırayla (bkz. `PasswordEvaluation.score`). */
export const PASSWORD_STRENGTH_KEYS = ['veryWeak', 'veryWeak', 'weak', 'medium', 'good', 'strong'] as const

/** Şifre politikası: en az 12 karakter, büyük+küçük harf, rakam, özel karakter. */
export function evaluatePassword(password: string): PasswordEvaluation {
  const ruleIds: PasswordRuleId[] = ['length', 'upper', 'lower', 'digit', 'special']
  const met: Record<PasswordRuleId, boolean> = {
    length: password.length >= PASSWORD_MIN_LENGTH,
    upper: /[A-Z]/.test(password),
    lower: /[a-z]/.test(password),
    digit: /[0-9]/.test(password),
    special: /[^A-Za-z0-9]/.test(password),
  }

  const rules: PasswordRule[] = ruleIds.map((id) => ({ id, met: met[id] }))
  const score = rules.filter((rule) => rule.met).length as PasswordStrengthScore

  return {
    rules,
    score,
    isValid: rules.every((rule) => rule.met),
  }
}

// Karışıklığa yol açabilecek karakterler (0/O, 1/l/I vb.) bilinçli olarak dışarıda bırakıldı.
const LOWER = 'abcdefghijkmnpqrstuvwxyz'
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
const DIGITS = '23456789'
const SPECIAL = '!@#$%^&*()-_=+[]{}'
const ALL = LOWER + UPPER + DIGITS + SPECIAL

/** `crypto.getRandomValues` tabanlı, [0, max) aralığında yansız rastgele tamsayı. */
function secureRandomInt(max: number): number {
  const buffer = new Uint32Array(1)
  crypto.getRandomValues(buffer)
  return buffer[0] % max
}

function pickChar(charset: string): string {
  return charset[secureRandomInt(charset.length)]
}

/**
 * Şifre politikasının tamamını karşılayan rastgele bir şifre üretir.
 * GÜVENLİK: `Math.random()` DEĞİL, kriptografik olarak güvenli `crypto.getRandomValues` kullanılır.
 */
export function generateStrongPassword(length = 16): string {
  const required = [pickChar(LOWER), pickChar(UPPER), pickChar(DIGITS), pickChar(SPECIAL)]
  const rest = Array.from({ length: Math.max(length - required.length, 0) }, () => pickChar(ALL))
  const chars = [...required, ...rest]

  // Fisher-Yates shuffle — zorunlu 4 karakterin hep baştaki sabit sırada durmaması için.
  for (let i = chars.length - 1; i > 0; i--) {
    const j = secureRandomInt(i + 1)
    ;[chars[i], chars[j]] = [chars[j], chars[i]]
  }

  return chars.join('')
}
