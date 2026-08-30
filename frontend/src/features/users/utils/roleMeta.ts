// Rol adı etiket çözücü — Faz 14 / İz D takip düzeltmesi.
//
// KÖK NEDEN: `roles.name` (backend) BİZİM seed ettiğimiz bir taksonomidir (bkz.
// `RolePermissionSeeder.php`), müşteri verisi DEĞİLDİR — dolayısıyla `docs/PHASE-INTL.md`
// §1.5'teki "DB'den gelen veri çevrilmez" ölçütü burada YANLIŞ uygulanmıştı. Doğru soru
// "DB'den mi geliyor" değil "kim yazdı": seed edilen rol adları bizim taksonomimiz →
// çevrilir. Müşterinin kendi girdiği veri (tag, custom field, firma adı...) bu kapsamın
// DIŞINDADIR ve bu dosya onlara dokunmaz.
//
// HAM AD ASLA DEĞİŞMEZ: `roles.name` kod-sahipli bir KİMLİKTİR — rol adını değiştiren bir
// uç yok (`UpdateRolePermissionsRequest` yalnızca izinleri günceller) ve
// `usePermission.ts`'teki `SUPER_ADMIN_ROLE = 'Super Admin'` karşılaştırması ham ada karşı
// yapılır. Bu yüzden burada SADECE GÖSTERİM çözülür — ham `name` hiçbir yerde mutasyona
// uğramaz, yalnızca render noktalarında `roleLabel()` çıktısı basılır.
//
// MODÜL SEVİYESİNDE `t()` SONUCU SAKLANMAZ (Faz 14'ün tekrarlayan hata sınıfı — dil
// değişiminde donar): `roleLabel` her çağrıda `t`'yi parametre olarak alır, tıpkı
// `activityTypeMeta.ts` / `ticketStatusMeta.ts` deseninde olduğu gibi.
//
// BİLİNMEYEN ROL: sözlükte olmayan bir rol adı (örn. ileride eklenecek özel bir rol)
// `defaultValue: name` sayesinde ÇÖKMEDEN ham adı basar.
import type { TFunction } from 'i18next'

/** Ham `roles.name` (backend, seed edilmiş) → `enums` namespace anahtarı. */
const ROLE_NAME_TO_KEY: Record<string, string> = {
  'Super Admin': 'role.super_admin',
  Admin: 'role.admin',
  'Satış Müdürü': 'role.sales_manager',
  'Satış Temsilcisi': 'role.sales_rep',
  'Destek Temsilcisi': 'role.support_agent',
  İzleyici: 'role.viewer',
}

/**
 * Ham rol adını (`roles.name`) aktif dile çevrilmiş etikete çözer.
 *
 * Sözlükte olmayan bir rol adı (özel/bilinmeyen rol) için ham adın kendisine düşer —
 * hem eşleme bulunamadığında hem de `enums` sözlüğünde anahtar eksik olduğunda.
 */
export function roleLabel(name: string, t: TFunction): string {
  const key = ROLE_NAME_TO_KEY[name]
  if (!key) return name
  return t(`enums:${key}`, { defaultValue: name })
}
