// SLA geri sayımı — `docs/SLA-DESIGN.md` §6 "İstemci geri sayım kuralı" ve görev tanımının
// ⚠️ uyarısının TEK uygulaması. BAŞKA HİÇBİR bileşen `sla_due_at`'i `Date.now()` ile
// KARŞILAŞTIRMAZ; hepsi bu hook'tan geçer.
//
// ==============================================================================================
// NEDEN performance.now() VE Date.now() DEĞİL
// ==============================================================================================
// Kullanıcının bilgisayarının SAAT AYARI yanlışsa (birkaç dakika ileri/geri, hatta NTP
// senkronu bozuksa saatler mertebesinde), `Date.now()` ile sunucudan gelen mutlak `sla_due_at`
// karşılaştırması ihlali OLDUĞUNDAN FARKLI gösterir — iki kullanıcı aynı ticket için farklı şey
// görebilir. `performance.now()` ise İŞLETİM SİSTEMİ SAAT AYARINDAN BAĞIMSIZ, yalnızca
// monoton (hep ileri akan, asla geri sıçramayan) bir sayaçtır; sistem saati kullanıcı tarafından
// değiştirilse bile etkilenmez. Doğru desen (dokümandaki adımlarla birebir):
//   1. Sunucu yanıtı geldiği an `t0 = performance.now()` ve `r0 = sla_remaining_seconds` (SUNUCU
//      HESABI) kaydedilir.
//   2. Ekrandaki sayaç `r0 - (performance.now() - t0) / 1000` gösterir — yalnızca YEREL, monoton
//      geçen süreyi SUNUCUNUN verdiği başlangıç değerinden düşer; sunucunun mutlak `sla_due_at`
//      tarihiyle hiçbir zaman doğrudan aritmetik yapılmaz.
//   3. `sla_paused === true` iken sayaç `r0`'da DONUK kalır (duraklamada geçen süre SUNUCUDA zaten
//      `sla_paused_seconds`'a birikir; istemcinin ayrıca eritmesi ÇİFT SAYIM olurdu) — bu durumda
//      gösterilen değer doğrudan `r0`'dır (aşağıya bkz.), ayrıca eritme YAPILMAZ.
//   4. `r0` her DEĞİŞTİĞİNDE (yeni fetch, 60 sn'lik refetch veya `useTicketRealtime`'ın
//      `.ticket.sla.warning`/`.ticket.sla.breached` olayıyla yamadığı `sla_remaining_seconds`)
//      `t0`/`r0` YENİDEN kaydedilir — böylece uyku/sekme askıya alma sonrası sapma da düzelir.
//
// İHLAL GERÇEĞİ HER ZAMAN SUNUCUNUNDUR: yerel sayaç sıfırın altına inince arayüz "aşıldı"
// DAVRANIŞI gösterir (ilerleme çubuğu dolar, metin "İhlal" yazar) ama bu yalnızca bir tahmindir;
// `ticket.sla_breached` sunucudan geldiğinde onunla OR'lanır (aşağıya bkz.) — sunucu "ihlal değil"
// derse (ör. duraklamaya POZİTİF kalanla girilmiş bir ticket, §5.3) yerel tahmin onu geçersiz
// KILAMAZ, yalnızca EKLER: `isBreached = sunucu.sla_breached || yerelKalan <= 0`.
//
// ==============================================================================================
// SAFLIK (React Compiler / eslint `react-hooks/purity`, `react-hooks/refs`, `set-state-in-effect`)
// ==============================================================================================
// Render sırasında `ref.current` OKUNAMAZ ve `performance.now()` gibi saf-olmayan bir fonksiyon
// ÇAĞRILAMAZ; bir efektin gövdesinde KOŞULSUZ/DOĞRUDAN `setState` çağırmak da (kademeli render'a
// yol açtığı için) önerilmez. Bu üç kısıtı BİRDEN karşılayan desen:
// - `t0`/`r0` referansı YALNIZCA bir efektte (render DIŞINDA) `performance.now()` ile hesaplanır
//   ve bir REF'e yazılır — efekt içinde `setState` YOKTUR, yalnızca ref mutasyonu vardır.
// - `serverRemaining` değiştiğinde eritilmiş görüntü değeri (`tickValue`) `DealFormModal`'daki
//   `openKey`/`lastOpenKey` ile AYNI, React'ın resmen önerdiği "render sırasında state sıfırlama"
//   deseniyle `null`'a çekilir (efekt YOK, saf bir karşılaştırma + `setState`).
// - Saniyelik eritme YALNIZCA `setInterval`'ın CALLBACK'i içinde `setState` çağırır — bu, lint
//   kuralının açıkça izin verdiği "dış sistemden (saat) güncelleme aboneliği" deseninin ta
//   kendisidir.
// - Render, ne ref'e ne `performance.now()`'a dokunur: `paused`/`null` durumunda doğrudan
//   `serverRemaining`'i (saf, prop'tan türetilmiş) döner; akan durumda `tickValue`'yu (state) döner,
//   `tickValue` henüz ilk tik'i almadıysa (`null`) yine `serverRemaining`'e düşer — ki bu ANLIK
//   olarak zaten DOĞRU değerdir (t=t0'da kalan = r0, yaklaşıklık değil).
import { useEffect, useRef, useState } from 'react'

export type SlaCountdownInput = {
  sla_remaining_seconds: number | null
  sla_paused: boolean
  sla_breached: boolean
  sla_total_seconds: number
} | null | undefined

export type SlaCountdownState = {
  /** Eritilmiş kalan saniye. `sla_remaining_seconds` sunucudan `null` geldiyse (resolved/closed
   * sonrası, ya da ticket henüz yüklenmediyse) `null` — aktif bir hedef yok demektir. */
  remainingSeconds: number | null
  isPaused: boolean
  /** Sunucu gerçeği ile yerel (iyimser) tahminin OR'u — bkz. dosya başındaki gerekçe. */
  isBreached: boolean
  /** `(sla_total_seconds - kalan) / sla_total_seconds`, 0-1 arasına kırpılmış — ilerleme çubuğu için. */
  progress: number
}

export function useSlaCountdown(ticket: SlaCountdownInput): SlaCountdownState {
  const serverRemaining = ticket?.sla_remaining_seconds ?? null
  const paused = ticket?.sla_paused ?? false

  const baselineRef = useRef<{ t0: number; r0: number } | null>(null)

  // Sunucudan yeni bir değer geldiğinde eritilmiş görüntüyü sıfırla — render sırasında, efekt
  // KULLANMADAN (bkz. dosya başındaki saflık notu). `tickValue === null` iken render bloğu
  // `serverRemaining`'e düşer; bu, t=t0 anında zaten DOĞRU değerdir.
  const [lastServerRemaining, setLastServerRemaining] = useState(serverRemaining)
  const [tickValue, setTickValue] = useState<number | null>(null)
  if (serverRemaining !== lastServerRemaining) {
    setLastServerRemaining(serverRemaining)
    setTickValue(null)
  }

  // Referans noktasını (t0/r0) YALNIZCA efektte, `performance.now()` ile kaydeder — efekt
  // gövdesinde `setState` YOKTUR, yalnızca ref mutasyonu vardır.
  useEffect(() => {
    baselineRef.current = serverRemaining === null ? null : { t0: performance.now(), r0: serverRemaining }
  }, [serverRemaining])

  // Akan (duraklamamış, aktif hedefi olan) bir sayaç için saniyede bir eritilmiş değeri
  // CALLBACK içinde state'e yazar (dış saatten abonelik deseni).
  useEffect(() => {
    if (serverRemaining === null || paused) return
    const interval = setInterval(() => {
      const baseline = baselineRef.current
      if (!baseline) return
      setTickValue(baseline.r0 - (performance.now() - baseline.t0) / 1000)
    }, 1000)
    return () => clearInterval(interval)
  }, [serverRemaining, paused])

  const remainingSeconds = serverRemaining === null ? null : paused ? serverRemaining : (tickValue ?? serverRemaining)

  const totalSeconds = ticket?.sla_total_seconds ?? 0
  const localOverdue = remainingSeconds !== null && remainingSeconds <= 0
  const isBreached = !!ticket?.sla_breached || localOverdue

  let progress = 0
  if (totalSeconds > 0 && remainingSeconds !== null) {
    const elapsed = totalSeconds - remainingSeconds
    progress = Math.min(1, Math.max(0, elapsed / totalSeconds))
  }

  return { remainingSeconds, isPaused: paused, isBreached, progress }
}
