// Sayfa gezinme takibi — route değişiminde bir "page visit" açar ve o
// sayfada geçirilen süreyi sunucuya periyodik heartbeat ile bildirir.
// Süreyi SUNUCU hesaplar (bkz. ROADMAP R5); istemci yalnızca "hâlâ
// buradayım" sinyali gönderir, `duration_seconds` gibi bir alan YOK.
//
// Yalnızca kimliği doğrulanmış rotalarda anlamlı olduğu için `AppLayout`
// içinde bir kez mount edilir — `/login` ve `/change-password` bu ağacın
// dışında olduğundan zaten hiç izlenmez (bkz. AppLayout.tsx).
import { useEffect, useMemo, useRef } from 'react'
import { useLocation, useMatches } from 'react-router-dom'
import { api } from '../lib/axios'
import { useAuth } from '../features/auth/hooks/useAuth'

const HEARTBEAT_INTERVAL_MS = 30_000
const MAX_PATH_LENGTH = 2048

type CreatePageVisitResponse = {
  data: { id: number }
}

// Saf sayısal ("42") veya UUID benzeri segmentleri dinamik kabul eder.
// Yalnızca `useMatches()` route param'ı çözemediğinde (aşağıdaki
// `normalizeRoute` yorumuna bakın) devreye giren bir yedek.
const DYNAMIC_SEGMENT_RE = /^\d+$|^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/**
 * `pathname`'i log sayfasında gruplanabilir bir "route" desenine indirger
 * (örn. `/deals/42` -> `/deals/:id`).
 *
 * Öncelikli strateji: React Router'ın kendi eşleştirmesinden gelen
 * `params` nesnesini kullanmak — bu, router.tsx'e hiç dokunmadan (bu
 * şeridin dosya sahipliği dışında) her yeni dinamik rotada otomatik
 * çalışır. `params`'ın anahtar sırası, path pattern'indeki `:param`
 * sırasıyla eşleşir (React Router bunu path'i soldan sağa eşleştirirken
 * doldurur), bu yüzden segmentleri soldan sağa tarayıp sıradaki param
 * değeriyle karşılaşınca onu tüketiyoruz. Bu, iki farklı param'ın aynı
 * değere sahip olduğu (örn. `/orgs/42/deals/42`) uç durumda bile doğru
 * segmenti doğru isimle değiştirir — değere göre arama yapsaydık ikinci
 * param birinciyi ezerdi.
 *
 * `params` boşsa (statik rota) veya bir segment hiçbir param'a karşılık
 * gelmiyorsa, saf sayısal/UUID segmentleri `:id` ile normalize eden regex
 * yedeğine düşülür.
 */
function normalizeRoute(pathname: string, params: Record<string, string | undefined>): string {
  const paramEntries = Object.entries(params).filter(
    (entry): entry is [string, string] => entry[1] !== undefined,
  )
  let paramIndex = 0

  const segments = pathname.split('/').map((segment) => {
    if (segment === '') return segment

    const nextParam = paramEntries[paramIndex]
    if (nextParam && segment === nextParam[1]) {
      paramIndex += 1
      return `:${nextParam[0]}`
    }

    if (DYNAMIC_SEGMENT_RE.test(segment)) return ':id'
    return segment
  })

  return segments.join('/')
}

export function usePageTracking(): void {
  const location = useLocation()
  const matches = useMatches()
  const { status } = useAuth()

  const lastMatch = matches[matches.length - 1]
  const matchPathname = lastMatch?.pathname ?? location.pathname

  const route = useMemo(() => {
    const params = (lastMatch?.params ?? {}) as Record<string, string | undefined>
    return normalizeRoute(matchPathname, params)
  }, [matchPathname, lastMatch])
  const path = useMemo(
    () => (location.pathname + location.search).slice(0, MAX_PATH_LENGTH),
    [location.pathname, location.search],
  )

  // Her route değişiminde artan bir "nesil" sayacı: hızlı A -> B -> C
  // gezinmelerinde A'nın POST yanıtı B'ye geçtikten SONRA dönerse, bu sayaç
  // yanıtın hâlâ güncel gezinmeye ait olup olmadığını doğrular. `AbortController`
  // zaten eski isteği iptal edip promise'i reddettiriyor (birincil savunma);
  // generation kontrolü ikinci bir güvenlik katmanı — abort sinyali adaptör
  // tarafından yutulup `.then` yine de tetiklenirse bile yanlış visit id'siyle
  // heartbeat başlatılmasını engeller.
  const generationRef = useRef(0)
  const heartbeatTimerRef = useRef<number | null>(null)

  useEffect(() => {
    // Kimliği doğrulanmamışken (veya henüz belli değilken) hiç istek atma —
    // login sayfasında 401 üretip interceptor'ı gereksiz tetiklememek için.
    if (status !== 'authenticated') return

    const generation = ++generationRef.current
    const controller = new AbortController()
    const title = document.title

    // `visibilitychange` ile senkron tutulan bir bayrak — heartbeat tick'i
    // her seferinde `document.visibilityState`'i doğrudan okumak yerine bunu
    // kontrol eder. Sekme arka plana geçince atlanır, öne dönünce (event
    // tetiklenir tetiklenmez, bir sonraki tick'i beklemeden) kaldığı yerden
    // devam eder.
    let isHidden = document.visibilityState === 'hidden'
    function handleVisibilityChange() {
      isHidden = document.visibilityState === 'hidden'
    }
    document.addEventListener('visibilitychange', handleVisibilityChange)

    function stopHeartbeat() {
      if (heartbeatTimerRef.current !== null) {
        window.clearInterval(heartbeatTimerRef.current)
        heartbeatTimerRef.current = null
      }
    }

    function startHeartbeat(visitId: number) {
      stopHeartbeat()
      heartbeatTimerRef.current = window.setInterval(() => {
        // Sekme arka plandaysa "burada geçirilen süre" sayılmamalı.
        if (isHidden) return
        api.patch(`/api/page-visits/${visitId}/heartbeat`).catch(() => {
          // Sessizce yut: ağ hatası, 403 (PASSWORD_CHANGE_REQUIRED — axios
          // interceptor zaten yönlendirmeyi yapar), 5xx vb. Sayfa ziyareti
          // loglamak ikincil bir iştir, kullanıcıya asla yansımaz.
          console.debug('[usePageTracking] heartbeat isteği başarısız oldu, yok sayılıyor')
        })
      }, HEARTBEAT_INTERVAL_MS)
    }

    api
      .post<CreatePageVisitResponse>(
        '/api/page-visits',
        { route, path, title },
        { signal: controller.signal },
      )
      .then(({ data }) => {
        // Bu yanıt artık güncel gezinmeye ait değilse (kullanıcı çoktan
        // başka bir rotaya geçti) yok say — yanlış visit id'sine heartbeat
        // başlatma.
        if (generationRef.current !== generation) return
        startHeartbeat(data.data.id)
      })
      .catch(() => {
        // Sessizce yut. Beklenen durumlar: `must_change_password=true` iken
        // 403 PASSWORD_CHANGE_REQUIRED (interceptor yönlendirir, biz sadece
        // takibi başlatmayız), istek iptali (route hızlıca değişti), ağ
        // hatası. Hiçbiri kullanıcıya toast olarak yansımaz.
        console.debug('[usePageTracking] page-visit isteği başarısız oldu, yok sayılıyor')
      })

    return () => {
      controller.abort()
      stopHeartbeat()
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
    // `title` efekt gövdesi içinde `document.title`'dan okunuyor (dışarıdan
    // gelen reaktif bir değer değil), bu yüzden dep listesinde yer almıyor.
    // `route`/`path`/`status` her route değişiminde (ve login/logout ile)
    // efekti yeniden tetiklemek için yeterli.
  }, [route, path, status])
}
