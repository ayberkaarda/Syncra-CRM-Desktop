// Aşama adı çözücü — Kanban sütunları, huni, filtre seçenekleri ve rozetlerin TÜMÜ bu
// fonksiyondan geçer (bkz. görev tanımı, PHASE-INTL izi: "Sales Funnel Türkçe kalıyor" hatası).
//
// `pipeline_stages.name` HEM bizim çekirdek taksonomimizi (7 seed aşama) HEM DE müşterinin
// kendi verisini (admin'in yeniden adlandırdığı ya da yeni oluşturduğu aşamalar) aynı kolonda
// taşır — backend `name_key` ile ikisini ayırır (bkz. `backend/app/Models/PipelineStage.php`,
// migration `2026_08_25_960001_add_name_key_to_pipeline_stages_table.php`):
//
//   - `name_key` DOLU  → satır bizim taksonomimizdendir VE admin ismini hiç değiştirmemiştir.
//                        `enums:pipelineStage.<name_key>` anahtarı kullanılır.
//   - `name_key` NULL  → isim MÜŞTERİ VERİSİDİR (admin yeniden adlandırmış ya da yeni aşama
//                        oluşturmuş) — çeviriye SOKULMAZ, ham `name` olduğu gibi basılır.
//
// `defaultValue: stage.name` KASITLIDIR: anahtar `locales/*/enums.json`de henüz yoksa (paralel
// bir şerit yazıyor olabilir) ya da eksikse arayüz KIRILMAZ, orijinal isme düşer.
import type { TFunction } from 'i18next'

export type StageLabelSource = {
  name: string
  name_key?: string | null
}

/**
 * `t` her çağrıda PARAMETRE olarak alınır, modül seviyesinde SAKLANMAZ — aksi halde dil
 * değişiminde etiketler donardı (bu fazda tekrarlanan hata sınıfı, bkz. görev tanımı).
 */
export function stageLabel(t: TFunction, stage: StageLabelSource): string {
  if (!stage.name_key) return stage.name
  return t(`enums:pipelineStage.${stage.name_key}`, { defaultValue: stage.name })
}
