// Ortak "semantik renk adı" -> `Badge` varyantı eşleyicisi. Backend'de `tags.color` ve (Faz 7'de)
// `pipeline_stages.color` gibi kolonlar hex değil, sabit bir token-adı sözlüğü taşır
// (`primary`/`success`/`danger`/`warning`/`neutral`/`info`). `info` design system'de bir
// `Badge` varyantı olarak YOK — en yakın anlam olarak `primary`'ye eşlenir; yeni bir token/varyant
// eklenmez, mevcut beşiyle çözülür.
//
// Bilinmeyen/null bir değer SESSİZCE `neutral`'a düşer (hata fırlatmaz) — kullanıcı ileride
// Ayarlar'dan elle bir renk adı girebilir; beklenmedik bir değer arayüzü kırmamalı.
//
// ÖNEMLİ: Beyaz liste dışı bir değeri ASLA className'e enterpolasyon yapmayın (ör.
// `bg-${color}-tint`) — Tailwind v4 içerik taraması yalnızca kaynakta LİTERAL olarak yazılmış
// sınıf adlarını görür; dinamik olarak inşa edilen bir sınıf adı üretilen CSS'te hiç bulunmaz ve
// sessizce stilsiz kalır. Bu yüzden eşleme sabit bir obje üzerinden (literal anahtar erişimi ile)
// yapılır, string interpolation ile değil.
import type { BadgeProps } from '../ui'

export type BadgeVariant = NonNullable<BadgeProps['variant']>

export type TokenColorName = 'primary' | 'success' | 'danger' | 'warning' | 'neutral' | 'info'

const TOKEN_BADGE_VARIANT_MAP: Record<TokenColorName, BadgeVariant> = {
  primary: 'primary',
  success: 'success',
  danger: 'danger',
  warning: 'warning',
  neutral: 'neutral',
  info: 'primary',
}

/**
 * Backend'in gönderdiği semantik renk adını (`tags.color`, ileride `pipeline_stages.color`) bir
 * `Badge` varyantına çevirir. Beyaz liste dışı/boş bir değer sessizce `neutral`'a düşer.
 */
export function tokenBadgeVariant(color: string | null | undefined): BadgeVariant {
  if (!color) return 'neutral'
  return TOKEN_BADGE_VARIANT_MAP[color as TokenColorName] ?? 'neutral'
}
