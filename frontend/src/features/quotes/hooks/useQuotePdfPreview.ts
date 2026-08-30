// Teklif PDF önizleme kartı için blob tabanlı yükleyici — bkz. `QuoteDetailPage` "PDF Önizleme"
// kartı ve `api/quotesApi.ts`'teki `fetchQuotePdfBlob` yorumu: backend `SecurityHeaders`
// middleware'i HER yanıtta `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` gönderiyor
// (clickjacking koruması — KASITLI, KALDIRILMAYACAK). SPA (`:5173`) ile API (`:8000`) FARKLI
// origin'de olduğundan çapraz-origin `<iframe src="http://localhost:8000/...">` çerçevelenemez.
// Çözüm: PDF'i axios ile (oturum çerezi + XSRF taşıyarak) bayt olarak çek, `URL.createObjectURL()`
// ile SAME-ORIGIN bir `blob:` URL üret, iframe'e ONU ver — tarayıcı `blob:` URL'lerini
// `X-Frame-Options` denetiminden hiç geçirmez.
//
// KARAR: react-query DEĞİL, elle `useEffect` + `useState`. Gerekçe: react-query cache'i
// serileştirilebilir veri için tasarlıdır; bir `Blob`'u query cache'inde tutmak onu `gcTime`
// boyunca bellekte canlı tutar ve `URL.revokeObjectURL()` çağrısını cache yaşam döngüsüyle
// (invalidate/gc, StrictMode'un cache paylaşımı) senkronize etmeyi gerektirir — "hangi
// cache olayı revoke'u tetikler" sorusunu gereksiz bir katmana taşır. Burada tek tüketici
// (bu kart) olduğundan blob'un yaşam döngüsü zaten bileşenin mount/unmount'una bağlıdır;
// klasik "fetch-in-effect + cancelled bayrağı" deseni (bkz. `useSlaCountdown.ts`,
// `usePageTracking.ts`) blob'u ÜRETEN effect'in TEMİZLİĞİNDE onu revoke etmeyi garantiler —
// React 18 StrictMode'un mount→cleanup→mount çift çalıştırmasında bile: ilk (atılan) mount'un
// isteği cleanup anına kadar dönmediğinden `objectUrl` orada hâlâ `null`'dır (revoke edilecek
// bir şey yok), gerçek blob URL yalnızca kalıcı kalan ikinci mount'ta üretilir ve YALNIZCA o
// effect'in kendi cleanup'ında (unmount veya `quoteId` değişince) serbest bırakılır.
import { useEffect, useState } from 'react'
import { fetchQuotePdfBlob, getQuotePdfErrorMessage } from '../api/quotesApi'

export type QuotePdfPreviewState =
  | { status: 'loading' }
  | { status: 'success'; url: string }
  | { status: 'error'; message: string }

const LOADING: QuotePdfPreviewState = { status: 'loading' }

/**
 * `quoteId` tanımlı olduğu sürece PDF'i indirip bir `blob:` URL üretir. `quoteId` `undefined`
 * ise (teklif henüz yüklenmedi / bulunamadı) İSTEK ATILMAZ — çağıran taraf `quote?.id` geçer.
 */
export function useQuotePdfPreview(quoteId: number | undefined): QuotePdfPreviewState {
  // `quoteId` değişince eski sonucu RENDER SIRASINDA sıfırlar — `useSlaCountdown.ts`'teki
  // `lastServerRemaining` deseniyle AYNI, React'ın resmen desteklediği "render sırasında state
  // ayarlama" tekniği. Bunun yerine bir effect içinde KOŞULSUZ/SENKRON `setState` çağırmak
  // `react-hooks/set-state-in-effect` (hard error) ihlal ederdi; bu yüzden sıfırlama effect'e
  // DEĞİL render'a taşındı — aşağıdaki effect'te `setState` YALNIZCA promise `.then`/`.catch`
  // içinde (asenkron, effect gövdesine göre senkron DEĞİL) çağrılır.
  const [lastQuoteId, setLastQuoteId] = useState(quoteId)
  const [state, setState] = useState<QuotePdfPreviewState>(LOADING)
  if (quoteId !== lastQuoteId) {
    setLastQuoteId(quoteId)
    setState(LOADING)
  }

  useEffect(() => {
    if (quoteId === undefined) return

    let cancelled = false
    let objectUrl: string | null = null

    fetchQuotePdfBlob(quoteId)
      .then((blob) => {
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setState({ status: 'success', url: objectUrl })
      })
      .catch(async (error: unknown) => {
        const message = await getQuotePdfErrorMessage(error)
        if (cancelled) return
        setState({ status: 'error', message })
      })

    return () => {
      cancelled = true
      // Bu effect'in ÜRETTİĞİ blob URL'i (varsa) serbest bırak — yukarıdaki dosya başı
      // yorumundaki StrictMode akışına bkz.
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [quoteId])

  return state
}
