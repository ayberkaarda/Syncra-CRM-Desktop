# DESKTOP-ARCHITECTURE — Repo Yerleşimi, Platform Adaptörü ve Tauri Kabuğu

> **Statü: BAĞLAYICI MİMARİ SÖZLEŞMESİ.** Bu doküman `SYNCDESKTOP.md` §3 (repo yerleşimi),
> §6 (Tauri uygulaması) ve §7 (frontend adaptörü) maddelerini **gerçek dosya adları ve F0
> keşfinde doğrulanmış olgularla** somutlaştırır. F3 (Tauri kabuğu + adaptör) bu dokümana
> göre inşa edilir; sapma gerekirse önce bu doküman güncellenir, sonra kod yazılır.
>
> **Kardeş sözleşme:** `docs/DESKTOP-SYNC-PROTOCOL.md` — backend sync/auth endpoint gövdeleri
> (`SYNCDESKTOP.md` §4), `syncra-sync` crate modül API'si (§5) ve çakışma algoritması **orada**
> tarif edilir; burada TEKRAR TARİF EDİLMEZ, yalnızca referans verilir.
>
> **Bu bir PLAN'dır — üretim kodu içermez.** Aşağıdaki kod blokları imza/şekil sözleşmesidir;
> gövdeler F3'te yazılır.
>
> İlgili sözleşmeler: `SYNCDESKTOP.md` (üst şartname), `docs/DESKTOP-SYNC-PROTOCOL.md`,
> `docs/PHASE-INTL.md` (i18n çekirdeği), `docs/AUTH-FLOWS.md` (mevcut web auth akışı),
> `docs/DESIGN-SYSTEM.md`. Doğrulama tarihi: **2026-08-30** (F0 keşfi).
>
> **Dil kuralı (`SYNCDESKTOP.md` §0.6):** metin Türkçe; kod, identifier, path, dosya adı,
> i18n anahtarı İngilizce.

---

## 0. Belge Statüsü ve Doğrulama Yöntemi

Bu dokümandaki her olgusal iddia F0 keşfinde **dosya okunarak** doğrulandı ve `dosya:satır`
ile işaretlendi. Doğrulanmamış hiçbir şey iddia edilmez; karara bağlanmamış her şey §11'de
"açık karar" olarak listelenir. İşaretsiz bir cümle "doğrulandı" anlamına gelmez —
doğrulanmış olanlar açık referans taşır.

| İşaret | Anlam |
|---|---|
| `dosya:satır` | O dosyanın o satırında görüldü (2026-08-30) |
| **KARAR** | Bu dokümanda verilen, F3'ü bağlayan karar |
| **TUZAK** | Sessizce bozan, uygulama sırasında ayağa dolanacak bilinen tehlike |
| **AÇIK** | Karara bağlanmadı — §11'de listeli, F3 öncesi cevap gerekir |

---

## 1. Amaç ve Kapsam

### 1.1 Bu doküman ne bağlar

1. `desktop/` ağacının dosya-dosya yerleşimi ve **dizin sahipliği** (§2).
2. `Platform` / `DataSource` adaptörünün TypeScript **imza sözleşmesi**, `EngineEvent` →
   TanStack Query invalidation eşlemesi ve `frontend/` tarafındaki **minimum dokunuş listesi** (§3).
3. `desktop/vite.desktop.config.ts`'in her ayarı ve **gerekçesi** (§4).
4. Tauri plugin listesi, komut **yüzeyi** (ad + sorumluluk), `capabilities/default.json`
   kapsamı, düzeltilmiş CSP, pencere/tray topolojisi (§5).
5. Desktop realtime kurulumu ve 7 kanalın desktop karşılığı (§6).
6. `desktop` i18n namespace'inin sözleşmesi (§7).
7. Online-only sınırının UI'da nasıl işaretleneceği (§8).
8. Yerel (cihaz-lokal) durumun nerede yaşayacağı (§9).

### 1.2 Bu doküman ne bağlamaz — KAPSAM DIŞI

| Konu | Nerede |
|---|---|
| `/api/sync/{manifest,pull,push}` istek/yanıt gövdeleri, işleme kuralları | `docs/DESKTOP-SYNC-PROTOCOL.md` (§4) |
| `POST /api/auth/device`, `/api/me/devices` gövdeleri, lockout | `docs/DESKTOP-SYNC-PROTOCOL.md` (§4.3) |
| `sync_version` sayacı, observer'lar, migration'lar, tombstone | `docs/DESKTOP-SYNC-PROTOCOL.md` (§4.2) |
| `syncra-sync` crate modül API'si, lokal şema, outbox sıralaması, retention | `docs/DESKTOP-SYNC-PROTOCOL.md` (§5) |
| Çakışma algoritması, `ConflictDetector`, çözüm semantiği | `docs/DESKTOP-SYNC-PROTOCOL.md` (§4.4, §5) |
| Tehdit modeli / STRIDE | `docs/DESKTOP-THREAT-MODEL.md` (F6'da yazılır) |
| CI / paketleme iş akışları | F7 (`SYNCDESKTOP.md` §10) |

Bu dokümanda crate ya da backend yüzeyine değinildiği her yerde **yalnızca çağıran taraf**
tarif edilir (hangi komut hangi motoru çağırır, hangi olay hangi cache'i tazeler); çağrılan
tarafın davranışı protokol dokümanının konusudur.

### 1.3 Değişmeyecek olan: web build

**KARAR A1.** Web bundle'ının davranışı **değişmez**. `frontend/` altındaki her dokunuş
(§3.7 tablosu) web tarafında **davranış-eşdeğer** olmak zorundadır; `npm run build` ve
`npx tsc -p tsconfig.app.json --noEmit` her fazda yeşil kalır (`SYNCDESKTOP.md` §0.4).
Desktop'a özgü hiçbir bağımlılık (`@tauri-apps/*`) `frontend/package.json`'a **girmez**.

---

## 2. Repo Yerleşimi (`SYNCDESKTOP.md` §3'ün somutlaştırılması)

### 2.1 Mevcut durum — doğrulanmış

Repoda **hiç** `Cargo.toml`, `src-tauri/`, `desktop/` ya da `.github/` yoktur; monorepo
bugün `backend/` + `frontend/` + `docs/` + `dev.bat`'tan ibarettir. Aşağıdaki ağacın
tamamı yenidir.

`frontend/` mevcut hâli (doğrulandı): `vite.config.ts` **8 satır** — yalnızca
`react()` + `tailwindcss()` plugin'leri; `resolve`, `define`, `base`, `build`,
`server` blokları **yok**. `tsconfig.app.json`'da `paths` **yok**; `src` altında
`from '@/...'` biçiminde **tek bir import bile yok** — tüm iç importlar görelidir
(`src/features/deals/api/dealsApi.ts:8` → `'../../../lib/axios'`).

### 2.2 Hedef ağaç

```
Syncra-CRM/
├── backend/                                   # F1 — bkz. docs/DESKTOP-SYNC-PROTOCOL.md
├── frontend/
│   ├── vite.config.ts                         # DEĞİŞMEZ (KARAR A1)
│   ├── package.json                           # DEĞİŞMEZ — @tauri-apps/* buraya girmez
│   ├── scripts/check-i18n-bootstrap.mjs       # F3'te güncellenebilir — §3.8 TUZAK
│   └── src/
│       ├── platform/                          # YENİ — web + paylaşılan sözleşme
│       │   ├── types.ts                       #   Platform, DataSource, ConnState, ...
│       │   ├── web.ts                         #   webPlatform (mevcut axios/echo'ya delegasyon)
│       │   └── index.ts                       #   PlatformProvider / usePlatform / get-setPlatform
│       └── i18n/locales/{tr,en,de,fr}/desktop.json   # YENİ — 28. namespace (§7)
├── desktop/
│   ├── package.json                           # desktop-only bağımlılıklar + build:desktop script
│   ├── tsconfig.json                          # frontend/tsconfig.app.json'ı genişletir + paths
│   ├── vite.desktop.config.ts                 # §4
│   ├── index.html                             # desktop giriş HTML'i (frontend/index.html'in ikizi)
│   ├── src/
│   │   ├── main.desktop.tsx                   # giriş: setPlatform + i18n kapısı + PlatformProvider + App
│   │   ├── platform/desktop.ts                # desktopPlatform (invoke tabanlı)
│   │   └── bridge/                            # invoke sarmalayıcıları + event köprüsü
│   │       ├── invoke.ts                      #   typed invoke + SyncError → {code,message}
│   │       ├── events.ts                      #   EngineEvent aboneliği → queryClient
│   │       └── realtime.ts                    #   Echo(bearer) → handle_realtime (§6)
│   ├── crates/syncra-sync/                    # F2 — bkz. docs/DESKTOP-SYNC-PROTOCOL.md §5
│   └── src-tauri/
│       ├── Cargo.toml
│       ├── tauri.conf.json                    # §4.2, §5.4, §5.5
│       ├── capabilities/default.json          # §5.3
│       └── src/{main.rs,lib.rs,commands/,os/,state.rs}   # §5.2
├── docs/DESKTOP-ARCHITECTURE.md               # bu doküman
├── docs/DESKTOP-SYNC-PROTOCOL.md              # kardeş sözleşme
└── docs/DESKTOP-THREAT-MODEL.md               # F6
```

### 2.3 Dizin sahipliği — çakışma önleme

`SYNCDESKTOP.md` K13 gereği paralel fazlar ayrı worktree'lerde çalışır. Sahiplik tablosu,
iki şeridin aynı dosyaya yazmasını **yapısal olarak** imkânsız kılmak içindir.

| Yol | Sahip faz | İçerik | Diğer fazlara kapalı mı |
|---|---|---|---|
| `frontend/src/platform/*` | F3 | `Platform` tipi, web implementasyonu, provider | Evet — yalnız F3 yazar |
| `frontend/src/lib/axios.ts` · `lib/echo.ts` | F3 | §3.7 dokunuşları | Evet |
| `frontend/src/i18n/locales/*/desktop.json` | F3 (iskelet) → F4 (dolum) | `desktop` namespace | Hayır — F4 anahtar ekler |
| `desktop/src/**` | F3 | giriş + desktop platform + bridge | Evet |
| `desktop/src-tauri/**` | F3 (kabuk) → F5 (OS özellikleri) | Tauri uygulaması | Hayır — F5 `os/` altına yazar |
| `desktop/crates/syncra-sync/**` | F2 | sync motoru | **Evet — F3 buraya YAZMAZ** |
| `backend/**` | F1 | sync/auth katmanı | **Evet — F3 buraya YAZMAZ** |

**KARAR A2 — `desktop.ts` neden `desktop/` altında.** `SYNCDESKTOP.md` §3 `desktop.ts`'i
bilerek `frontend/src/platform/` dışında bırakır. Gerekçe burada kayıtlıdır: `desktop.ts`
`@tauri-apps/api`'ye bağımlıdır; `frontend/src` altında dursaydı (a) `frontend/package.json`
desktop bağımlılığı taşımak zorunda kalır, (b) `tsc -p tsconfig.app.json` web tarafında
çözülemeyen import görür, (c) yanlış bir statik import web bundle'ına Tauri kodu sızdırır.

**KARAR A3 — seçim noktası entry'dir, `index.ts` değil.** `frontend/src/platform/index.ts`
**`desktop.ts`'i import ETMEZ** (edemez: A2). Seçim, giriş dosyasında yapılır:
`frontend/src/main.tsx` hiçbir şey yapmaz (varsayılan `webPlatform` yürürlüktedir),
`desktop/src/main.desktop.tsx` ilk render'dan **önce** `setPlatform(desktopPlatform)` çağırır
ve ağacı `<PlatformProvider value={desktopPlatform}>` ile sarar. Böylece web girişi
(`main.tsx`) **hiç değişmez** ve web bundle'ında Tauri kodu bulunmaz.

`setPlatform()` / `getPlatform()` çifti React ağacının dışındaki tüketiciler (`lib/axios.ts`,
`lib/echo.ts`) içindir; `usePlatform()` React tarafı içindir ve provider yoksa `webPlatform`'a
düşer. Bu desen projede yerleşiktir: `router.tsx:317-322` auth handler'larını aynı biçimde,
runtime'da kaydeder.

---

## 3. Platform Adaptörü (`SYNCDESKTOP.md` §7)

### 3.1 Doğrulanmış zemin — adaptör neden ucuz

| Olgu | Kanıt | Adaptör açısından sonucu |
|---|---|---|
| Tek axios instance | `frontend/src/lib/axios.ts:14-22` — `baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000'`, `withCredentials: true`, `withXSRFToken: true` | HTTP için **tek** müdahale noktası |
| `fetch()` kullanımı `src` altında **SIFIR** | `grep -rn "\bfetch("` → yalnız `prefetch`/`refetch` eşleşmeleri | Hiçbir bileşen API katmanını bypass etmiyor |
| `import axios` yapan 10 dosya yalnız `isAxiosError` type-guard'ı için | istek atmıyorlar | Bu 10 dosya dokunuş listesinde **değil** |
| Her api modülü `<domain>Keys` `as const` factory export eder | `dealsApi.ts:13`, `companiesApi.ts:32`, `tasksApi.ts:18` | Invalidation eşlemesi tek yerden kurulabilir |
| Auth handler'ları runtime'da kayıt ediliyor | `router.tsx:317-322` | `setPlatform()` aynı deseni izler — projede yerleşik |

`lib/axios.ts` ayrıca `getCsrfCookie`, `registerUnauthorizedHandler`,
`registerPasswordChangeHandler`, `getErrorMessage`, `getFieldErrors` export eder;
interceptor 419'da CSRF'i yenileyip **tam bir kez** retry eder (`:83-87`),
401 ve 403 `USER_DEACTIVATED`'de `onUnauthorized`'ı, 403 `PASSWORD_CHANGE_REQUIRED`'da
yönlendirme handler'ını çağırır.

**Sonuç:** UI bileşenleri veri katmanına **yalnızca** api modülleri üzerinden erişir. Adaptör
bu katmanın altına girer; bileşen kodu **değişmez** (`SYNCDESKTOP.md` §7.1 gereği).

### 3.2 İki çağrı deseni — doğrulandı

| Desen | Nasıl | Feature'lar |
|---|---|---|
| **(a) Hook-export** | api modülü doğrudan TanStack Query hook'u export eder | deals, contacts, tasks, tickets, quotes, companies, leads, products, price-lists, exchange, users, logs, search, saved-views, activities |
| **(b) Fonksiyon-export + `hooks/` sarmalayıcı** | api modülü düz `fetchX` / `xRequest` export eder, feature'ın `hooks/` klasörü sarar | settings, chat, notifications, dashboard, reports |

**KARAR A4.** `DataSource` **(b) desenine göre** tasarlanır: düz `Promise` döndüren
fonksiyonlar. (a) desenindeki modüller hook gövdesindeki `queryFn`'i `platform.data.*`'a
delege eder; hook imzası, dönüş tipi ve query key'i **değişmez**. Bu, bileşen tarafında
sıfır değişiklik anlamına gelir ve iki desenin de tek bir adaptörle karşılanmasını sağlar.

### 3.3 `frontend/src/platform/types.ts` — sözleşme

```ts
// ~40 LOC hedefi; gövde yok, yalnız tip.

export type PlatformKind = 'web' | 'desktop'
export type ConnState = 'online' | 'offline'
export type Capability =
  | 'offline' | 'deep-link' | 'hotkey' | 'tray' | 'files' | 'clipboard' | 'screenshot'

/** Her komut/HTTP hatasının tek normalize şekli. `code` i18n anahtarına çevrilir (§7.3). */
export interface PlatformError { code: string; message: string; fields?: Record<string, string[]> }

/** `SYNCDESKTOP.md` §8 — offline'da reddedilen aksiyon. */
export interface OnlineOnlyError extends PlatformError { code: 'ONLINE_ONLY'; action: string }

export interface HttpClient {
  get<T>(url: string, config?: unknown): Promise<T>
  post<T>(url: string, body?: unknown, config?: unknown): Promise<T>
  put<T>(url: string, body?: unknown, config?: unknown): Promise<T>
  patch<T>(url: string, body?: unknown, config?: unknown): Promise<T>
  delete<T>(url: string, config?: unknown): Promise<T>
}

/**
 * MEVCUT api modüllerinin çağrı yüzeyini AYNEN yansıtır (`SYNCDESKTOP.md` §7.1).
 * Alan adları = feature dizin adları; metot adları = mevcut fonksiyon adları
 * (`deals.list(params)`, `deals.move(id, body)`, ...). Tam metot listesi F3'te,
 * mevcut api dosyalarından birebir türetilir — yeni metot İCAT EDİLMEZ.
 */
export interface DataSource {
  deals: DealsSource; contacts: ContactsSource; companies: CompaniesSource
  leads: LeadsSource; tasks: TasksSource; tickets: TicketsSource
  quotes: QuotesSource; activities: ActivitiesSource; chat: ChatSource
  notifications: NotificationsSource; search: SearchSource
  /* RO tablolar (products, price-lists, exchange, saved-views, users) §8 tablosuna göre */
}

export interface RealtimeAdapter {
  connect(): void
  disconnect(): void
  /** Kanal aboneliği; web'de Echo, desktop'ta Echo(bearer) + engine köprüsü (§6). */
  channel(name: string): RealtimeChannel
  state(): ConnState
}

export interface Platform {
  kind: PlatformKind
  http: HttpClient
  data: DataSource
  connectivity: { isOnline(): boolean; subscribe(cb: (s: ConnState) => void): () => void }
  realtime: RealtimeAdapter
  notify(n: AppNotification): void
  capabilities: Set<Capability>
  onlineOnly<T>(action: string, fn: () => T): T | OnlineOnlyError
}
```

**Not — `onlineOnly` imzası.** `SYNCDESKTOP.md` §7.1'deki `onlineOnly<T>(fn)` burada
`onlineOnly<T>(action, fn)` olarak somutlaştırıldı: tooltip anahtarı
`desktop.onlineOnly.<action>` (§8) aksiyon adını **çağrı yerinde** bilmek zorundadır;
aksi hâlde hangi anahtarın basılacağı belirsiz kalır. Bu bir imza somutlaştırmasıdır,
yeni özellik değildir (bkz. §12 S9).

### 3.4 `frontend/src/platform/web.ts` — sorumluluk

~50 LOC. **Hiçbir yeni davranış üretmez**; mevcut modüllere delegasyondur.

| Üye | Web implementasyonu |
|---|---|
| `kind` | `'web'` |
| `http` | `lib/axios.ts`'teki `api` instance'ı (cookie + XSRF + 419 retry aynen) |
| `data` | Mevcut api modüllerinin fonksiyonlarına doğrudan delegasyon |
| `connectivity` | `navigator.onLine` + `online`/`offline` event'leri |
| `realtime` | `lib/echo.ts`'teki mevcut Echo instance'ı |
| `notify` | `components/ui`'deki `toast` |
| `capabilities` | **boş küme** — web'de offline/hotkey/tray/deep-link yok |
| `onlineOnly` | `fn()`'i **koşulsuz** çalıştırır (web zaten online-only'dir) |

### 3.5 `desktop/src/platform/desktop.ts` — sorumluluk

| Üye | Desktop implementasyonu |
|---|---|
| `kind` | `'desktop'` |
| `http` | axios instance, `Authorization: Bearer <device token>`; **cookie/XSRF YOK** (§6.4 TUZAK 2) |
| `data` | `invoke('query'\|'get'\|'mutate'\|'search')` — komut yüzeyi §5.2, gövdeler `docs/DESKTOP-SYNC-PROTOCOL.md` §5 |
| `connectivity` | `SyncStatus.online` (motor otoritedir; `navigator.onLine` **kullanılmaz** — LAN var ama sunucu yok durumunu yanlış raporlar) |
| `realtime` | Echo(bearer) → `handle_realtime` (§6) |
| `notify` | `tauri-plugin-notification` (uygulama arka plandayken native), aksi hâlde `toast` |
| `capabilities` | `{offline, deep-link, hotkey, tray, files, screenshot}` + `clipboard` **yalnız opt-in açıkken** (K10) |
| `onlineOnly` | `SyncStatus.online === false` ise `fn` **çağrılmaz**, `OnlineOnlyError` döner |

### 3.6 `EngineEvent` → TanStack Query invalidation

**KARAR A5.** `EngineEvent::TablesChanged(Vec<Entity>)` (bkz. `docs/DESKTOP-SYNC-PROTOCOL.md`
§5) `desktop/src/bridge/events.ts` içinde **açık bir eşleme tablosuyla** query key'e çevrilir.
Otomatik/tahmini eşleme (entity adını çoğullayıp key üretmek) **YASAK** — doğrulanmış karşı
örnekler:

| Modül | `Keys` gerçek değeri | Kanıt |
|---|---|---|
| search | `['global-search']` (❗ `['search']` değil) | `features/search/api/searchApi.ts:20-21` |
| deals board | `['deals','board']` — ayrı factory | `features/deals/api/boardApi.ts:23-24` |
| exchange | `['exchange-rates','current']` (❗ `all` alanı yok) | `features/exchange/api/exchangeRatesApi.ts:13-14` |
| price-lists | `['price-lists']` — tire | `features/price-lists/api/priceListsApi.ts:18-19` |
| saved-views | `['saved-views']` — tire | `features/saved-views/api/savedViewsApi.ts:9-10` |
| products (custom fields) | `['custom-fields','products']` | `features/products/api/productsApi.ts:23` |

Eşleme tablosunun doğrulanmış çekirdeği (F3'te RO tablolarla tamamlanır — §11 D-5):

| `Entity` | Invalidate edilecek key(ler) | Kanıt |
|---|---|---|
| `Deal` | `['deals']` + `['deals','board']` | `dealsApi.ts:13-18`, `boardApi.ts:23-24` |
| `Company` | `['companies']` | `companiesApi.ts:32-38` |
| `Contact` | `['contacts']` | `contactsApi.ts:35-36` |
| `Lead` | `['leads']` | `leadsApi.ts:21-22` |
| `Task` | `['tasks']` (list + calendar aynı kök) | `tasksApi.ts:18-24` |
| `Ticket` | `['tickets']` | `ticketsApi.ts:21-22` |
| `Quote` / `QuoteItem` | `['quotes']` | `quotesApi.ts:23-24` |
| `Activity` | `['activities']` | `activitiesApi.ts:9-10` |
| `Message` / `Conversation` | `['chat']` | `features/chat/api.ts:23-24` |
| `Notification` | `notificationsKeys.lists` + `notificationsKeys.unreadCount` | `features/notifications/hooks/useNotifications.ts:45-46` |

`['deals','detail',id]` (`dealsApi.ts:16`) gibi parametreli anahtarlar **kök prefix** ile
geçersizleştirilir — TanStack Query prefix eşleşmesi alt anahtarları kapsar; satır bazlı
invalidation'a gerek yoktur ve `TablesChanged` zaten tablo granülerliğindedir.

`EngineEvent`'in diğer varyantlarının UI karşılığı:

| Olay | UI etkisi |
|---|---|
| `StatusChanged` | Connectivity bar + tray ikonu + pending/conflict rozetleri |
| `ConflictAdded` | Conflict Inbox rozeti +1 (sessiz üzerine yazma YASAK — K6) |
| `StorageWarning` | Storage ayarları uyarısı (K8 tavanları) |
| `AuthLost` | Mevcut 401 yoluyla aynı davranış: auth store temizlenir → `/login` (`router.tsx:317-320`) |
| `ProtocolMismatch` | Tam ekran "güncelleme gerekli" kapısı; sync durur |

### 3.7 Minimum dokunuş listesi — 3 yeni + 5 mevcut = **8 dosya**

`SYNCDESKTOP.md` §7.1 hedefi ≤15 dosyadır; liste bunun **çok altında** kaldı. Sebebi §3.1'de
doğrulandı: `fetch()` kullanımı sıfır, tek axios instance, bileşenlerin API bypass'ı yok.
(`SYNCDESKTOP.md` §12 açık soru 3'ün cevabı: **hiçbir bileşen API'yi doğrudan çağırmıyor.**)

| # | Path | Neden dokunuluyor | ~LOC | Web'e etkisi |
|---|---|---|---|---|
| 1 | `frontend/src/platform/types.ts` | **YENİ** — `Platform`/`DataSource` tip sözleşmesi | ~40 | yok (tip) |
| 2 | `frontend/src/platform/web.ts` | **YENİ** — mevcut axios/echo/toast'a delegasyon | ~50 | yok (davranış-eşdeğer) |
| 3 | `frontend/src/platform/index.ts` | **YENİ** — `PlatformProvider`/`usePlatform`/`get-setPlatform`, varsayılan `webPlatform` | ~10 | yok |
| 4 | `frontend/src/lib/axios.ts` | `baseURL` (`:15`) platformdan; auth taşıma şekli (cookie ↔ bearer); `window.location.pathname === '/login'` (`:89`) tek kaynaktan okunur | ~15 | davranış-eşdeğer |
| 5 | `frontend/src/lib/echo.ts` | `VITE_REVERB_*` (`:68-72`) platform config'inden; authorizer (`:77-87`) desktop'ta bearer + `/api/broadcasting/auth` (§6) | ~20 | davranış-eşdeğer |
| 6 | `frontend/src/stores/themeStore.ts` | zustand `persist` name `'syncra-theme'` (`:18`) — **§9 kararına bağlı** | ~5 | §9'a bağlı |
| 7 | `frontend/src/i18n/index.ts` | `LOCALE_STORAGE_KEY = 'syncra-locale'` (`:19`; okuma `:105`, yazma `:261`) — **§9 kararına bağlı** | ~5 | §9'a bağlı |
| 8 | `frontend/src/components/layout/AppLayout.tsx` | `SIDEBAR_STORAGE_KEY = 'syncra-sidebar'` (`:13`; okuma `:19`, yazma `:46`) — **§9 kararına bağlı** | ~5 | §9'a bağlı |

> 6, 7 ve 8 numaralı satırlar **§9.2'deki ölçüme bağlıdır**: Tauri webview'ında `localStorage`
> kalıcı ise bu üç dosyaya **hiç dokunulmaz** ve liste 5 dosyaya iner.

Ek olarak `frontend/src/index.css`'e tek satırlık `@source` eklenir (§4.4) — CSS davranışı
değişmediği için dokunuş listesine sayılmaz, ama F3 raporunun "dokunulan dosyalar"
bölümünde belirtilir.

### 3.8 ⚠️ TUZAK — `check-i18n-bootstrap.mjs` kaynak metnine statik assert yapıyor

**`frontend/scripts/check-i18n-bootstrap.mjs` `src/i18n/index.ts` ve `src/main.tsx`'in
KAYNAK METNİNE düzenli ifadeyle assert eder** (`:50-53` yolları sabitler). Bu betiği
`npm run i18n:check-bootstrap` çalıştırır ve `SYNCDESKTOP.md` §0.4 regresyon kapısındadır.
**Dokunuş listesinin 7. maddesi (`src/i18n/index.ts`) bu betiği kırar.**

Betiğin `i18n/index.ts` üzerinde aradığı **tam** kalıplar (`check-i18n-bootstrap.mjs:101-246`):

| # | Aranan kalıp | Kırılırsa |
|---|---|---|
| 1a | `const <x> = resolveInitialLocale()` | "acilis dili bir degiskene alinmazsa…" |
| 1b | `lng: <x>` (aynı değişken) | "i18n.init() icinde `lng: <x>` bulunamadi" |
| 1c | `export const <x>: Promise<void> = <bootstrap>()` | "acilis sozu DISA ACMIYOR" |
| 1d | bootstrap gövdesinde `ensureBundlesLoaded(<x>)`, `changeLanguage(<x>)`, `if (<x> === DEFAULT_LOCALE) return`, `catch (`, `console.warn(`, `changeLanguage(DEFAULT_LOCALE)` | dört ayrı hata |
| 1e | `main.tsx`'te `i18nReady` import'u + `createRoot(`'un kapının **ARDINDA** olması | "flash geri gelir" |
| 1f | `'./locales/tr/*.json'` globu `eager: true`; `'./locales/{en,de,fr}/*.json'` globu eager **DEĞİL** | bundle kararı bozuldu |
| 1g | `missingKeyHandler` gövdesinde `throw new Error(` | dev/test throw kaybolmuş |

Betiğin ikinci katmanı **davranışsaldır**: `i18n/index.ts`'in gerçek kaynağını ayrı Node alt
süreçlerinde çalıştırır (`import.meta.glob` ve `import.meta.env` sahteleriyle) ve `en/de/fr/tr`
senaryolarını ölçer.

**KARAR A6.** `src/i18n/index.ts`'e dokunulursa `check-i18n-bootstrap.mjs` **aynı commit'te**
güncellenir; `npm run i18n:check-bootstrap` yeşil olmadan F3 kapanmaz. Dokunuşun şekli
mümkün olduğunca yukarıdaki kalıpları bozmayacak biçimde seçilir (yalnız `readStoredLocale`
ve `setLocale` gövdelerindeki depolama çağrısını değiştirmek; `resolveInitialLocale()`i
**senkron** bırakmak). `resolveInitialLocale()`i asenkronlaştırmak 1a/1b/1d'yi topluca
kırar — **YASAK**.

**KARAR A7 — açılış kapısı desktop girişinde de var.** Betik yalnız `src/main.tsx`'e bakar;
`desktop/src/main.desktop.tsx` **kapsam dışıdır**. Desktop girişi `main.tsx:19-25`'teki
kapıyı (`void i18nReady.then(() => createRoot(...).render(...))`) **birebir** tekrarlamak
zorundadır; aksi hâlde `en/de/fr` seçili bir kullanıcıda desktop arayüzü sessizce Türkçe
basar ve hiçbir kapı bunu yakalamaz. Betiğin ikinci giriş dosyasını da kapsaması §11 D-6'da
açık karardır.

---

## 4. `desktop/vite.desktop.config.ts`

### 4.1 Vite sürümü — **8.2.0'da kalınıyor**

**KARAR A8.** `frontend/package.json`'daki `vite: ^8.2.0` **korunur**; Tauri için
düşürülmez. Gerekçeler (2026-08-30'da doğrulandı):

- Tauri'nin resmi `create-tauri-app` `template-react-ts` şablonu `vite ^8.0.16` gönderiyor.
- Tauri monorepo'sunun kendisi Vite 8 üzerinde.
- `@tailwindcss/vite` Vite 8'i **v4.2.2'den beri** destekliyor; projede `^4.3.3`.
- `@tauri-apps/cli@2.11.4` `engines: node >= 10`; ortamda Node 26.7.0.

Vite 8 Rollup → **Rolldown** geçişi yaptı (`build.rollupOptions` → `rolldownOptions`,
uyumluluk katmanı mevcut). `frontend/vite.config.ts` 8 satırdır ve `rollupOptions`,
`define`, `base` **içermez** → bu geçişten **etkilenmez**.

### 4.2 Ayar listesi ve gerekçeleri

| Ayar | Değer | Gerekçe |
|---|---|---|
| `root` | `desktop/` (config'in bulunduğu dizin) | Giriş HTML'i ve entry desktop tarafında |
| `plugins` | `[react(), tailwindcss()]` | `frontend/vite.config.ts` ile birebir aynı plugin kümesi |
| `resolve.alias['@']` | `../frontend/src` | Desktop entry'sinin paylaşılan koda erişimi. **Not:** `frontend/src` içi importlar tamamen görelidir (`@` kullanan tek dosya yok), bu yüzden alias **yalnız desktop entry'si için** gereklidir; `desktop/tsconfig.json`'a **eşleşen `paths`** yazılmazsa `tsc` çözemez |
| `publicDir` | `../frontend/public` | `favicon.png`, `apple-touch-icon.png`, `logo-mark.png` tek kaynaktan servis edilir, kopyalanmaz |
| `base` | **varsayılan (`/`) — DEĞİŞTİRİLMEZ** | §4.3 (1) |
| `build.outDir` | `dist` (desktop kökü) | `tauri.conf.json` → `frontendDist` buraya bakar |
| `server.port` | `1420` | Tauri `devUrl`'i **yoklar**; sabit port zorunlu |
| `server.strictPort` | `true` | ⚠️ Vite portu kaydırırsa Tauri **yanlış URL'e** bağlanır ve boş pencere açılır. Kaymak yerine hata vermek doğru davranıştır |
| `server.fs.allow` | `['..']` | Kaynaklar config root'unun **dışında** (`../frontend/src`); Vite varsayılan olarak root dışına servis etmez |
| `server.watch.ignored` | `['**/src-tauri/**']` | Rust derleme çıktıları HMR döngüsü tetiklemesin |
| `clearScreen` | `false` | `tauri dev` Rust derleyici çıktısını basar; Vite ekranı silerse hata mesajları kaybolur |
| `envPrefix` | `['VITE_', 'TAURI_ENV_*']` | Tauri'nin hedef platform/arch değişkenleri istemciye geçebilsin |
| `envDir` | §4.5 — **AÇIK (D-2)** | Varsayılanı config root'udur → `.env` `desktop/` içinde aranır |
| `define` | **kullanılmaz** | `__PLATFORM__` web tarafında tanımsız kalır; seçim entry'de yapılır (KARAR A3) — §11 D-1 |

### 4.3 Asset ve routing doğrulamaları — Tauri kaynak kodundan

Aşağıdakiler Tauri kaynak kodu (`crates/tauri/src/manager/mod.rs`, `get_asset`) okunarak
doğrulandı; F3'te varsayıma dayanmamak için buraya işlenmiştir.

**(1) Kök-mutlak asset yolları ÇALIŞIR.** Protokol handler baştaki `/`'ı söker ve yolu
`frontendDist` köküne göre çözer. Dolayısıyla mevcut kök-mutlak referanslar olduğu gibi
çalışır ve **`base` varsayılan `/` kalmalıdır**:

| Referans | Dosya |
|---|---|
| `/favicon.png`, `/apple-touch-icon.png` | `frontend/index.html:6-7` |
| `/logo-mark.png` | `Sidebar.tsx:124`, `LoginPage.tsx:108`, `ChangePasswordPage.tsx:120` |

**(2) SPA fallback zinciri var → hash router GEREKMEZ.** Aynı fonksiyon sırasıyla
`path` → `path.html` → `path/index.html` → `index.html` dener. Sonuçlar:

- `createBrowserRouter` (`frontend/src/router.tsx:37`) desktop'ta **olduğu gibi** çalışır;
  `createHashRouter`'a geçiş **YASAK** (gereksiz ve kırıcı).
- `lib/axios.ts:89`'daki `window.location.pathname === '/login'` karşılaştırması **aynen
  kalır**. Hash router'a geçilseydi `pathname` her zaman `/index.html` olurdu ve bu
  karşılaştırma sessizce hep `false` dönerdi — yani asıl risk hash router'a geçmektir.

**(3) Webview origin'i.** Windows'ta `http://tauri.localhost`, macOS/Linux'ta
`tauri://localhost`. Bu, §6.4 TUZAK 2'nin ve §5.5'teki CSP'nin girdisidir.

**(4) `import.meta.glob` alias üzerinden çalışır.** `src/i18n/index.ts:55,60`'taki göreli
desenler (`'./locales/tr/*.json'`, `'./locales/{en,de,fr}/*.json'`) **import eden modülün
dosya yoluna** göre çözülür, config root'una göre değil. Alias'lı bir entry'den yüklendiğinde
de 27 namespace × 4 dil aynen bulunur.

### 4.4 ⚠️ Tek gerçek risk: Tailwind v4 içerik taraması

Tailwind v4'ün otomatik içerik keşfi **çalışma dizini bazlıdır**. `desktop/` root'undan
çalışırken `../frontend/src` ağacını **kaçırabilir**; sonuç: hata yok, uyarı yok, sadece
bazı utility sınıfları CSS'te **yok** — sessizce bozulan sınıfından bir arıza.

**KARAR A9 — önlem.** `frontend/src/index.css`'e `@source "./";` eklenir. Direktif CSS
dosyasının **kendi konumuna** görelidir; web build'i için davranış-nötrdür (Tailwind zaten
o ağacı tarıyor), desktop build'inde ise tarama kökünü açıkça sabitler. `index.css` şu an
`@import 'tailwindcss'` ile başlıyor (`frontend/src/index.css:1`); `@source` import
bloğunun ardına eklenir.

**Kabul ölçütü:** `npm run build` (web) ile `npm run build:desktop` çıktılarındaki CSS
boyutları karşılaştırılır; anlamlı fark = tarama kaçağı.

### 4.5 Env değişkeni stratejisi — **AÇIK (D-2)**

`frontend/.env.example` bugün beş değişken taşıyor (doğrulandı): `VITE_API_URL`,
`VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`.

Vite'ın `envDir` varsayılanı **config root'udur** → `desktop/vite.desktop.config.ts` ile
`.env` dosyaları `desktop/` içinde aranır, `frontend/` içindekiler **okunmaz**:

| Seçenek | Artı | Eksi |
|---|---|---|
| **S1:** `envDir: '../frontend'` | Tek `.env`; web ile desktop asla ayrışmaz | Desktop'a özgü değişken eklenemez (web `.env`'ini kirletir) |
| **S2:** ayrı `desktop/.env*` | Desktop kendi API host'unu taşır | İki dosya elle senkron tutulur; sessiz ayrışma riski |

Ek kısıt: kapalı devre, kurulum başına değişebilen bir API host'u `VITE_API_URL`'in
build-time sabitliğiyle ve §5.5'teki (build-time sabit) CSP ile çelişir — §11 D-3.

---

## 5. Tauri Uygulaması (`SYNCDESKTOP.md` §6.1–6.3)

### 5.1 Plugin listesi

| Plugin | Sorumluluk | İlk kullanan faz |
|---|---|---|
| `tauri-plugin-notification` | Native bildirim (SLA, görev, mention) | F5-2 |
| `tauri-plugin-global-shortcut` | Quick-capture hotkey (`CmdOrCtrl+Shift+Space`) | F5-3 |
| `tauri-plugin-deep-link` | `syncra://` şeması | F5-4 |
| `tauri-plugin-autostart` | Opt-in oturum açılışında başlatma | F5-7 |
| `tauri-plugin-updater` | Minisign imzalı güncelleme (§6.5) | F7 |
| `tauri-plugin-window-state` | Pencere konum/boyut kalıcılığı | F5-7 |
| `tauri-plugin-single-instance` | Tek örnek + deep link'i mevcut pencereye iletme | F3 |
| `tauri-plugin-clipboard-manager` | Clipboard yakalama (opt-in, varsayılan KAPALI — K10) | F5-6 |
| `tauri-plugin-dialog` | Dosya seçici | F5-5 |
| `tauri-plugin-fs` | Cache dizini + seçilen dosyalar (dar scope, §5.3) | F5-5 |
| `tauri-plugin-os` | Platform/arch tespiti (`device_fingerprint`, `platform` alanı) | F3 |
| `tauri-plugin-process` | Yeniden başlatma (updater sonrası) | F7 |
| `tauri-plugin-shell` | **yalnız `open`** — harici link tarayıcıda | F3 |
| `tauri-plugin-log` | `tracing` çıktısının dosyaya yazımı (PII filtreli — §9 güvenlik listesi) | F3 |

### 5.2 Komut yüzeyi (`src-tauri/src/commands/`)

**Yalnız ad ve sorumluluk.** Her komut `syncra-sync` motoruna delegasyondur; gövdeler ve
motor API'si `docs/DESKTOP-SYNC-PROTOCOL.md` §5'tedir.

| Modül | Komut | Sorumluluk | Motor / uç karşılığı |
|---|---|---|---|
| `auth` | `login` | E-posta/parola + cihaz bilgisiyle device token alma | `SyncEngine::login` |
| | `restore` | Keychain'deki token ile oturum geri yükleme | `restore_session` |
| | `logout` | Oturum kapatma (pending varsa `force` gerekir) | `logout` |
| | `list_devices` | Kullanıcının cihaz listesi | `GET /api/me/devices` |
| | `revoke_device` | Cihaz token'ı iptali | `DELETE /api/me/devices/{token}` |
| `data` | `query` | Beyaz listeli `NamedQuery` çalıştırma (**ham SQL YASAK**) | `query` |
| | `get` | Tek kayıt | `get` |
| | `mutate` | Outbox'a yaz + lokal uygula | `mutate` |
| | `search` | Lokal FTS5 araması | `search` |
| `sync` | `sync_now` | Manuel push→pull turu | `sync_now` |
| | `status` | Anlık `SyncStatus` | `status` |
| | `conflicts` | Conflict Inbox listesi | `conflicts` |
| | `resolve_conflict` | KeepMine / TakeServer / Merge | `resolve_conflict` |
| | `download_archive` | Retention penceresini genişletme (K12) | `download_archive` |
| `storage` | `stats` | Disk/outbox kullanımı | `storage_stats` |
| | `update_settings` | Retention gün / MB / outbox tavanı (K8) | `update_settings` |
| | `clear_local` | Lokal DB + dosya cache silme | motor + FS |
| `files` | `cache_quote_pdf` | Teklif PDF'ini cache'e indirme | HTTP + FS |
| | `open_cached` | Cache'lenmiş dosyayı OS ile açma | `shell:open` |
| | `attach_from_paths` | Drag-drop dosyayı kuyruğa alma | `mutate` |
| | `screenshot_to_ticket` | Bölge seçimi → PNG → ticket eki | F5-8 |
| `os` | `set_badge` | Taskbar/dock rozeti | plugin |
| | `register_hotkey` | Hotkey kaydı + çakışma tespiti | plugin |
| | `set_autostart` | Autostart opt-in | plugin |
| | `notify` | Native bildirim | plugin |

**KARAR A10 — hata sözleşmesi.** Her komut hata durumunda `{ code, message }` JSON döner
(`SYNCDESKTOP.md` §6.2). `code`, UI'da `desktop.errors.<code>` i18n anahtarına çevrilir (§7.3).
Bilinmeyen `code` için `desktop.errors.unknown` basılır ve ham `code` konsola yazılır.
Eksik anahtar sessizce geçemez: `missingKeyHandler` dev/test'te **throw** eder ve bu davranış
`check-i18n-bootstrap.mjs` 1g kontrolüyle kilitlidir.

### 5.3 `capabilities/default.json` — dar kapsam

```jsonc
{
  "identifier": "default",
  "windows": ["main", "quick-capture"],
  "permissions": [
    "core:default",
    "core:window:allow-set-focus", "core:window:allow-show", "core:window:allow-hide",
    "notification:default",
    "global-shortcut:allow-register", "global-shortcut:allow-unregister",
    "deep-link:default",
    "autostart:default",
    "updater:default",
    "dialog:allow-open",
    "os:allow-platform", "os:allow-arch",
    "process:allow-restart",
    { "identifier": "shell:allow-open", "allow": [{ "url": "https://*" }, { "url": "http://*" }] },
    { "identifier": "fs:scope", "allow": ["$APPDATA/syncra/**", "$TEMP/syncra/**"] }
  ]
}
```

Kurallar (`SYNCDESKTOP.md` §6.3 + §9):

- `clipboard-manager:allow-read-text` **statik olarak verilmez**; clipboard opt-in
  açıldığında **runtime'da** devreye alınır (K10 — varsayılan kapalı).
- `shell` yalnız `open`; komut çalıştırma yetkisi **YOK**.
- `fs` scope yalnız iki kök + dialog ile kullanıcının seçtiği dosyalar.
- `quick-capture` penceresi ana pencerenin iznini **genişletmez**; aynı capability'de
  listelenmesinin tek sebebi aynı `mutate` yüzeyini kullanmasıdır.

### 5.4 Pencere ve tray topolojisi

| Bileşen | Etiket | Özellikler | Kaynak |
|---|---|---|---|
| Ana pencere | `main` | Normal; `window-state` ile konum/boyut kalıcı; kapatma → tray'e (ayarla değiştirilebilir, varsayılan §11 D-8) | §6.4 |
| Quick-capture | `quick-capture` | 480×360, frameless, always-on-top; hotkey ile açılır; **offline çalışır** | §6.4 |
| Tray | — | İkon durumu: online / offline / syncing / conflict. Menü: Open, Sync now, Quick capture, Pause sync, Quit | §6.4 |

Tray ikonu `EngineEvent::StatusChanged`'den beslenir (§3.6) — UI ile tray **aynı**
`SyncStatus` kaynağını okur; iki ayrı durum makinesi **YASAK**.

### 5.5 CSP — düzeltilmiş

`SYNCDESKTOP.md` §6.3'teki CSP eksiktir; aşağıdaki üç düzeltme **zorunludur** (§12 S1–S3).

**Düzeltme 1 — `ipc:` olmadan `invoke()` engellenir.** Tauri 2 IPC çağrıları `connect-src`
politikasına tabidir. `ipc: http://ipc.localhost` eklenmezse **her komut** CSP tarafından
bloke edilir; uygulama hiç çalışmaz.

**Düzeltme 2 — `style-src 'unsafe-inline'` nonce ile geçersizleşir.** Tauri `style-src`'a
kendi nonce'ını eklediği anda tarayıcı, spesifikasyon gereği `'unsafe-inline'`ı **yok sayar**.
Inline `style=""` niteliği üreten kütüphaneler sessizce bozulur. Çözüm: `style-src-attr
'unsafe-inline'` ayrıca verilir — `style-src-attr` nonce'tan etkilenmez.

**Düzeltme 3 — dev'de CSP hiç uygulanmaz.** `tauri dev`'de webview doğrudan `devUrl`'i
(`http://localhost:1420`) yükler; `tauri.conf.json`'daki `csp` **devreye girmez**. Bu,
"dev'de çalıştı, prod'da beyaz ekran" sınıfından bir tuzaktır. Ayrı bir geliştirme
politikası isteniyorsa `app.security.devCsp` alanı kullanılır.

Prod CSP (`tauri.conf.json` → `app.security.csp`):

```
default-src 'self';
connect-src 'self' ipc: http://ipc.localhost https://<api-host> wss://<reverb-host>;
img-src 'self' data: https://<api-host>;
style-src 'self' 'unsafe-inline';
style-src-attr 'unsafe-inline';
font-src 'self' data:;
object-src 'none';
frame-ancestors 'none'
```

`font-src 'self' data:` gerekçesi: `frontend/src/index.css:4-7` Poppins'i `@fontsource`
üzerinden **self-host** eder (kapalı devre kararı — `docs/DESIGN-SYSTEM.md` §9); dış font
CDN'i yoktur.

`<api-host>` / `<reverb-host>` build-time yer tutucudur ve §4.5'teki env kararına bağlıdır;
kurulum başına değişen bir API host'u CSP'yi kırar — §11 D-3.

**F3 kabul ölçütü:** `tauri build --debug` ile üretilen binary'de (dev sunucusu **kapalıyken**)
login → bootstrap → liste akışı çalışmalı; konsolda CSP ihlali **sıfır** olmalı.

---

## 6. Realtime Mimarisi

### 6.1 Mevcut web kurulumu — doğrulandı

`frontend/src/lib/echo.ts`: `broadcaster: 'reverb'` (`:67`), `window.Pusher = Pusher`
(`:62-64`), config `VITE_REVERB_APP_KEY/HOST/PORT/SCHEME` (`:68-72`). Authorizer (`:77-87`)
`POST /broadcasting/auth`'u **CSRF'li axios instance'ı** ile atar — yani **cookie ile
yetkilendirir, bearer ile değil**. Bağlantı durumu pusher-js'in `state_change` event'inden
okunur (`:93-98`); yeniden bağlanma tamamen pusher-js'in işidir.

### 6.2 Kanal envanteri — 7 kanal

| Kanal | Abone olan dosya:satır | Event | Desktop karşılığı |
|---|---|---|---|
| `presence-online` | `hooks/usePresence.ts:22` | presence join/leave | **Doğrudan UI** — mini-pull yok (§6.3 istisnası) |
| `private-deals` | `features/deals/hooks/useDealRealtime.ts:32` | `.deal.moved` | `handle_realtime` → `deals` mini-pull |
| `private-tickets` | `features/tickets/hooks/useTicketRealtime.ts:32` | `.ticket.sla.warning`, `.ticket.sla.breached` | `handle_realtime` → `tickets` mini-pull + native bildirim |
| `private-dashboard` | `features/dashboard/hooks/useDashboardSocket.ts:24` | dashboard tazeleme | Online ise doğrudan invalidate; offline'da son cache (§8) |
| `private-logs` | `features/logs/hooks/useActivityStream.ts:15` | activity akışı | **Online-only** (§8) — mini-pull yok |
| `private-user.{id}` | `useRealtimeSession.ts:40`, `useNotificationSocket.ts:62`, `useChatUnread.ts:65`, `useTaskReminders.ts:40` (**4 hook paylaşır**) | oturum düşürme, bildirim, okunmamış sayacı, görev hatırlatma | `handle_realtime` → `notifications`/`tasks` mini-pull + native bildirim |
| `private-conversation.{id}` | `features/chat/hooks/conversationChannel.ts:22-23` (**referans sayan registry**) | mesaj | `handle_realtime` → `messages` mini-pull |

`private-user.{id}`'in dört ayrı hook tarafından paylaşılması ve `private-conversation.{id}`'in
referans sayan bir registry ile yönetilmesi, desktop'ta **abonelik yaşam döngüsünün
değişmemesi** gerektiği anlamına gelir: adaptör kanal katmanının **altına** değil, Echo
instance'ının **yerine** girer.

### 6.3 Desktop akışı

```
Reverb (WS)
   │  Echo(broadcaster:'reverb', authorizer: bearer → POST /api/broadcasting/auth)
   ▼
desktop/src/bridge/realtime.ts
   │  event → RealtimeEvent
   ▼
invoke('handle_realtime', ev)      →  SyncEngine::handle_realtime  (protokol §5)
   │                                     └─ ilgili tablolar için mini-pull
   ▼
EngineEvent::TablesChanged([...])
   │  bridge/events.ts  (§3.6 eşleme tablosu)
   ▼
queryClient.invalidateQueries({ queryKey: [...] })
```

**KARAR A11.** Desktop'ta realtime event'i **doğrudan** UI cache'ini tazelemez; motoru
tetikler. Sebep: UI'nin okuduğu tek doğruluk kaynağı lokal SQLite'tır. Doğrudan invalidate
edilirse UI, motora henüz inmemiş bir veriyi göstermek için sunucuya gitmek zorunda kalır ve
offline-first sözleşmesi kırılır. **İstisna:** `presence-online` — senkron edilmeyen, kalıcı
olmayan anlık durum; doğrudan UI'ya bağlanır.

### 6.4 ⚠️ İki yapılandırma tuzağı

**TUZAK 1 — `withBroadcasting` ikinci kez ÇAĞRILMAMALI.** `/broadcasting/auth`
`backend/bootstrap/app.php:91-94`'te `['web','auth:sanctum','active']` middleware'leriyle
zaten kayıtlıdır — cookie yığını. İkinci bir `withBroadcasting` çağrısı **aynı URI'yi**
üretir; `RouteCollection` ilk eşleşmeyi döndürür ve ikinci kayıt **sessizce ölür**.
Çözüm ayrı bir URI üretmektir: `routes/api.php` içinden
`Broadcast::routes(['middleware' => ['auth:sanctum','active']])` →
`GET|POST /api/broadcasting/auth`. Desktop Echo `authEndpoint`'i **buraya** bakar ve
`Authorization: Bearer <token>` başlığı taşır. (Bu, `SYNCDESKTOP.md` §12 açık soru 4'ün
cevabıdır.) Backend tarafının detayı `docs/DESKTOP-SYNC-PROTOCOL.md` §4.3'ün konusudur;
burada yalnız istemci sözleşmesi bağlayıcıdır.

**TUZAK 2 — `SANCTUM_STATEFUL_DOMAINS` ve `tauri.localhost`.** Sanctum'un
`EnsureFrontendRequestsAreStateful` middleware'i **yalnızca** isteğin `Origin`/`Referer`
başlığına bakar. Masaüstü webview'ının origin'i Windows'ta `http://tauri.localhost`'tur
(§4.3-3). Bu origin `SANCTUM_STATEFUL_DOMAINS` listesiyle **eşleşirse** istek stateful
sayılır, CSRF doğrulaması devreye girer ve bearer taşıyan POST **419 `CSRF_TOKEN_MISMATCH`**
alır. Üstelik `lib/axios.ts:83-87`'deki 419 retry'ı CSRF cookie'sini tazelemeye çalışıp
başarısız olacağı için arıza "açıklanamayan tek retry" gibi görünür — teşhisi zor.

**KARAR A12.** `tauri.localhost` ve `localhost:1420` `SANCTUM_STATEFUL_DOMAINS` listesine
**GİRMEZ**; desktop istekleri stateless (bearer) yoldan gider. Bu, `backend/.env.example` ve
kurulum belgelerinde açıkça not edilir. İkinci savunma katmanı: desktop axios instance'ı
`withCredentials`/`withXSRFToken` **kullanmaz** (§3.5) — cookie hiç gönderilmezse ilgili yol
hiç tetiklenmez.

Hatırlatma: mevcut web auth **değişmez**. `User` bugün `HasApiTokens` kullanmıyor; auth
tamamen SPA cookie'dir (`backend/bootstrap/app.php:116` `statefulApi()`). `HasApiTokens`
eklenmesi ve mevcut testlere etkisi `docs/DESKTOP-SYNC-PROTOCOL.md` §4.3'ün konusudur.

---

## 7. i18n

### 7.1 Mevcut çekirdek — doğrulandı

| Olgu | Kanıt |
|---|---|
| 4 dil: `tr, en, de, fr`; fallback `tr` | `src/i18n/index.ts:12`, `:16` |
| **27 namespace**, `src/i18n/locales/<lang>/<ns>.json` | `src/i18n/locales/tr` → 27 dosya |
| `tr` eager, `en/de/fr` lazy chunk | `src/i18n/index.ts:55-62` |
| Açılış kapısı render'dan önce | `src/main.tsx:19-25` |
| Parite + bootstrap kontrolü regresyon kapısında | `package.json` → `i18n:check`, `i18n:check-bootstrap` |

### 7.2 `desktop` namespace — 28. namespace

**KARAR A13.** Yeni namespace **`desktop`**, dört dilin dördünde de açılır:

```
frontend/src/i18n/locales/tr/desktop.json
frontend/src/i18n/locales/en/desktop.json
frontend/src/i18n/locales/de/desktop.json
frontend/src/i18n/locales/fr/desktop.json
```

- Namespace sayısı 27 → **28** olur. `import.meta.glob` deseni dosya adına bakmaz
  (`src/i18n/index.ts:55,60`), bu yüzden **namespace eklemek için `i18n/index.ts`'e
  dokunulmaz** — yalnız dört JSON dosyası yaratılır.
- `tr` eager glob'a düştüğü için `desktop` namespace'inin Türkçesi **başlangıç bundle'ına**
  girer; `en/de/fr` ayrı chunk olarak kalır. **Lazy chunk davranışı desktop'ta değişmez**
  (Tauri webview'ında chunk'lar `frontendDist` içinden yerel protokolle yüklenir; ağ
  beklemesi yoktur, kapı yine de A7 gereği korunur).
- `npm run i18n:check` (parite) dört dilin anahtar kümesinin **birebir aynı** olmasını
  ister — üç dili boş bırakmak YASAK (`SYNCDESKTOP.md` §0.6).

### 7.3 Anahtar sözleşmesi

Şartnamenin **bağlayıcı** kıldığı iki alt ağaç:

| Prefix | Kaynak | İçerik |
|---|---|---|
| `desktop.errors.<CODE>` | `SYNCDESKTOP.md` §6.2 | Her komut hatasının `code` alanının karşılığı |
| `desktop.onlineOnly.<action>` | `SYNCDESKTOP.md` §7.1, §8 | Devre dışı butonun tooltip'i (§8.2 aksiyon adları) |

`desktop.errors.*` altında **en az** şu kodlar bulunmalıdır (protokol §4.5'ten):
`ONLINE_ONLY`, `UNRESOLVED_REFERENCE`, `FIELD_CONFLICT`, `RECORD_DELETED`,
`PROTOCOL_VERSION_MISMATCH`, `PUSH_BATCH_TOO_LARGE`, `INVALID_MUTATION`, `ABILITY_REQUIRED`
ve mevcutlar `DEAL_VERSION_CONFLICT`, `QUOTE_LOCKED`, `INVALID_STATUS_TRANSITION` —
artı KARAR A10 gereği `unknown`.

`SYNCDESKTOP.md` §7.2'deki yeni ekranların (Conflict Inbox, Storage ayarları, Devices,
Quick-capture, connectivity bar, "last synced" damgası, komut paleti kaynak etiketi) alt
anahtar ağaçları **F4'te** sabitlenir — F3 yalnız yukarıdaki iki zorunlu ağacı doldurur.
Bu, F3'ün kabul kapsamının (`SYNCDESKTOP.md` §10 F3) ötesine taşmasını önler (§11 D-7).

---

## 8. Online-Only Sınırı (`SYNCDESKTOP.md` §8)

### 8.1 Mekanizma

```ts
// Çağrı yeri (bileşen) — şekil sözleşmesi, üretim kodu değil.
const platform = usePlatform()
const offline = !platform.connectivity.isOnline()

<Button
  disabled={offline}
  title={offline ? t('desktop:onlineOnly.quotes.send') : undefined}
  onClick={() => platform.onlineOnly('quotes.send', () => sendQuote(id))}
/>
```

**KARAR A14 — üç katmanlı savunma.** (1) Buton `disabled` + tooltip; (2)
`platform.onlineOnly` offline'da `fn`'i **hiç çağırmaz**, `OnlineOnlyError` döner;
(3) backend push tarafı beyaz liste dışı aksiyonu `rejected` `code=ONLINE_ONLY` ile
reddeder (protokol §4.4). UI katmanı atlansa bile veri bütünlüğü korunur.

**KARAR A15.** Online-only aksiyonlar `DataSource`'a **girmez**; `platform.http` üzerinden
gider. Sebep: `DataSource` lokal-önce okumaların yüzeyidir; online-only bir aksiyonun lokal
karşılığı yoktur ve `mutate()` outbox'ına **düşmemelidir** — düşerse offline'da kuyruğa girer
ve kullanıcı işlemin yapıldığını sanır.

### 8.2 Kalem → sorumlu modül eşlemesi

Feature dizinleri doğrulandı (`frontend/src/features` altında 24 modül). Kesin bileşen
dosyası F4'te sabitlenir; burada **sorumlu modül** ve **aksiyon adı** bağlanır.

| §8 kalemi | `action` adı (tooltip anahtarı) | Sorumlu modül | Not |
|---|---|---|---|
| `leads.convert` | `leads.convert` | `features/leads` | Push beyaz listesinde DEĞİL (protokol §4.4) |
| `leads.import` | `leads.import` | `features/leads` (`api/importApi.ts`) | Queued CSV yolu |
| `quotes.send` | `quotes.send` | `features/quotes` | Push beyaz listesinde DEĞİL |
| `quotes.revise` | `quotes.revise` | `features/quotes` | Push beyaz listesinde DEĞİL |
| `quotes.pdf` | `quotes.pdf` | `features/quotes` | **Cache varsa çalışır** — `files::open_cached` (§5.2) |
| `quotes.calculate` | `quotes.calculate` | `features/quotes` | Lokal hesap YASAK — `docs/QUOTE-FINANCIALS.md` tek kaynak, kopyalanmaz |
| `settings.*` | `settings` | `features/settings` | Tümü |
| `users.*`, `roles` | `users`, `roles` | `features/users` | Tümü |
| `reports.*` | `reports` | `features/reports` | Tümü |
| `dashboard.*` | `dashboard` | `features/dashboard` | **Son cache gösterilir** + "last synced X min ago" damgası |
| `logs.*` | `logs` | `features/logs` | `activity_log` sync kapsamı dışı (protokol §4.1) |
| exchange-rates manuel | `exchange.refresh` | `features/exchange` | Okuma RO ayna (son 7 gün) |
| attachments upload | `attachments.upload` | `features/chat` + ilgili detay ekranları | **Kuyruğa alınır** — `files::attach_from_paths` (F5-5) |
| saved-views create/update | `savedViews.create`, `savedViews.update` | `features/saved-views` | Okuma RO ayna |
| password change | `password.change` | `features/auth` (`ChangePasswordPage.tsx`) | Oturum güvenliği; offline'da anlamsız |

**Üç davranış sınıfı** (karışmaması için ayrıca):

| Sınıf | Davranış | Kalemler |
|---|---|---|
| **Sert red** | Buton `disabled` + tooltip | convert, import, send, revise, calculate, settings, users, roles, reports, logs, exchange refresh, saved-views yazma, password change |
| **Cache'ten servis** | Veri gösterilir, tazelik damgası basılır | `dashboard.*`, `quotes.pdf` (cache varsa) |
| **Kuyruğa alma** | İşlem kabul edilir, `pending` rozetiyle işaretlenir | attachments upload |

---

## 9. Yerel Durum (cihaz-lokal)

### 9.1 Envanter — doğrulandı

| Anahtar | Nerede | Depo | Kanıt |
|---|---|---|---|
| `syncra-theme` | `stores/themeStore.ts` — zustand `persist` | localStorage | `themeStore.ts:11-20` (`name: 'syncra-theme'` `:18`) |
| `syncra-locale` | `i18n/index.ts` | localStorage | `:19` (tanım), `:105` (okuma), `:261` (yazma) |
| `syncra-sidebar` | `components/layout/AppLayout.tsx` | localStorage | `:13` (tanım), `:19` (okuma), `:46` (yazma) |

Diğer zustand store'lar: `features/auth/store.ts` (**persist YOK — bilinçli**),
`features/chat/store.ts`, `features/notifications/store.ts`. `sessionStorage` kullanımı
**yok**. Yani cihaz-lokal kalıcı durumun tamamı bu üç anahtardan ibarettir.

### 9.2 Karar

**KARAR A16 — Zustand store'ların iç yapısı DEĞİŞMEZ.** `themeStore`, `auth/store`,
`chat/store`, `notifications/store` şekil olarak aynı kalır. `auth/store`'un persist
etmemesi desktop'ta da **korunur**: oturum kalıcılığı OS keychain'deki device token ile
sağlanır (K9), tarayıcı depolamasıyla değil.

**KARAR A17 — depolama yeri F3'te ölçümle belirlenir.** Tauri webview'ında `localStorage`
kalıcılığı bu keşifte **ölçülmedi**; varsayımla karar verilmez.

| Ölçüm sonucu | Karar | §3.7 listesine etkisi |
|---|---|---|
| `localStorage` **kalıcı** (uygulama yeniden başlatılınca değerler duruyor) | Üç anahtar da **olduğu yerde kalır**; hiçbir dosyaya dokunulmaz | 6, 7, 8. satırlar **düşer** → liste 5 dosyaya iner |
| `localStorage` **kalıcı değil** | Üç anahtar `desktop_settings` tablosuna (protokol §5.3) taşınır ve `platform` üzerinden okunur | Üç satır kalır; **`Platform` arayüzüne depolama üyesi eklemek şartname sapmasıdır → önce sor** (`SYNCDESKTOP.md` §0.5) |

**Ölçüm (F3'ün ilk işlerinden):** `tauri dev` ile açılan pencerede üç anahtar yazılır,
uygulama tamamen kapatılıp yeniden açılır, değerler okunur. Windows (WebView2) ve WSL2
Ubuntu (WebKitGTK) için **ayrı ayrı** — K11 gereği iki platform da birinci sınıf hedeftir.
Sonuç F3 raporuna gerçek çıktı olarak yazılır (§0.3).

**Bilinen kısıt (ölçüm sonucundan bağımsız).** `storage::clear_local` komutunun sözleşmesi
**yalnız** SQLite dosyasını ve dosya cache'ini kapsar; `localStorage`'da kalan tema/dil/sidebar
tercihleri "Clear local" sonrası **hayatta kalır**. Bu bilinçlidir (kullanıcı verisi değil,
arayüz tercihi) ve Storage ayarları ekranında böyle yazılır.

---

## 10. Ortam ve Araç Zinciri

### 10.1 Kurulu — doğrulandı (2026-08-30)

| Araç | Sürüm | Kullanan |
|---|---|---|
| Node | 26.7.0 | Vite 8, i18n betikleri (`@tauri-apps/cli@2.11.4` `engines: node >= 10` — fazlasıyla karşılanıyor) |
| npm | 11.19.0 | paket yönetimi |
| PHP | 8.2.12 (XAMPP) | backend |
| Composer | 2.10.2 | backend |
| WebView2 Runtime | 151.0.4129.107 | Tauri (Windows) — **kurulu** |
| WSL2 Ubuntu | mevcut | Linux hedefi (K11) |

### 10.2 Eksik — F2/F3 ön koşulu

| Araç | Neden gerekli | Kim kurar |
|---|---|---|
| **Rust / cargo (≥ 1.80)** | `syncra-sync` (K1; protokol §5.1 `rust-version = "1.80"`) ve `src-tauri` | **Kullanıcı** |
| **MSVC Build Tools** (Windows) | Rust `msvc` toolchain'inin linker'ı | **Kullanıcı** |
| **Tauri CLI** (`@tauri-apps/cli` 2.x) | `tauri dev` / `tauri build` | **Kullanıcı** |
| WebKitGTK 2.42+ ve derleme bağımlılıkları (WSL2) | Linux hedefi (K11) | **Kullanıcı** |

**Bunlar kurulmadan F2 ve F3 BAŞLAYAMAZ.** F0 ve F1 (backend) etkilenmez.

### 10.3 CI

Repoda `.github/` **yok**; mevcut hiçbir CI iş akışı **yok**. `desktop-ci.yml` ve
`desktop-release.yml` F7'de sıfırdan yazılır (`SYNCDESKTOP.md` §10 F7). "Mevcut CI bozulmaz"
maddesi bu yüzden **boş kümedir** — bozulacak bir şey yoktur; kapı görevini §0.4'teki yerel
regresyon komutları görür.

### 10.4 Script sözleşmesi

`SYNCDESKTOP.md` §0.4 `cd desktop && npm run build:desktop` komutunu regresyon kapısına
koyar ama script'i tanımlamaz. **KARAR A18** — `desktop/package.json`:

| Script | Komut | Amaç |
|---|---|---|
| `dev:desktop` | `vite --config vite.desktop.config.ts` | Yalnız web katmanı (Tauri'siz hızlı döngü) |
| `build:desktop` | `tsc -b && vite build --config vite.desktop.config.ts` | §0.4 regresyon kapısı |
| `tauri` | `tauri` | `npm run tauri dev` / `npm run tauri build` |

---

## 11. Açık Kararlar

| # | Konu | Seçenekler | Neden şimdi karar gerekiyor | Kim |
|---|---|---|---|---|
| **D-1** | `__PLATFORM__` define'ı | (a) Hiç kullanma — seçim entry'de (KARAR A3, **önerilen**) · (b) Her iki Vite config'ine de `define` ekle | `SYNCDESKTOP.md` §7.1 `define: { __PLATFORM__: 'desktop' }` diyor; `frontend/vite.config.ts`'te karşılığı **yok** → paylaşılan kodda kullanılırsa **web build'i kırılır**. (b) seçilirse `frontend/vite.config.ts` dokunuş listesine 9. dosya olarak girer ve KARAR A1 gözden geçirilir | Kullanıcı |
| **D-2** | `envDir` | (a) `envDir: '../frontend'` — tek `.env` · (b) ayrı `desktop/.env*` | §4.5; sessiz ayrışma riski | Kullanıcı |
| **D-3** | API host'un build-time sabitliği | (a) Build-time sabit (CSP ile tutarlı; kurulum başına yeniden derleme) · (b) Runtime yapılandırılabilir (CSP'de host joker olur → politika gevşer) | §4.5 + §5.5; kapalı devre tek makine dağıtımında API host kurulum başına değişebilir. Güvenlik etkisi var → F6 ile kesişir | Kullanıcı |
| **D-4** | `localStorage` kalıcılığı | Ölçüm sonucuna bağlı (§9.2) | Dokunuş listesini 8 → 5 dosyaya indirebilir; aksi hâlde `Platform`'a depolama üyesi eklemek **şartname sapmasıdır** | F3 ölçümü → kullanıcı |
| **D-5** | `Entity` → query key eşlemesinin tamamlanması | — | §3.6; 10 satır doğrulandı, RO tablolar ve `presence`/`search` istisnaları F3'te tamamlanacak. Otomatik eşleme YASAK (doğrulanmış karşı örnekler var) | F3 |
| **D-6** | `check-i18n-bootstrap.mjs`'in `main.desktop.tsx`'i de kapsaması | (a) Betiği ikinci giriş dosyasına genişlet (**önerilen**) · (b) Kapsam dışı bırak, elle kontrol | KARAR A7; kapsanmazsa desktop'ta "dil seçici İngilizce diyor, arayüz Türkçe" hatası **hiçbir kapıya takılmaz** | Kullanıcı |
| **D-7** | `desktop` namespace alt ağaçları | F4'e ertelendi (§7.3) | F3'ün UI kapsamını genişletmemek için; erken sabitlenirse F4'te yeniden yazılır | F4 |
| **D-8** | Ana pencere kapatma davranışının varsayılanı | (a) Tray'e küçült · (b) Uygulamadan çık | `SYNCDESKTOP.md` §6.4 "Pencere kapatma → tray'e (ayar)" diyor ama **varsayılanı** belirtmiyor; arka plan sync (a)'yı gerektirir, kullanıcı beklentisi (b) olabilir | Kullanıcı |

---

## 12. Şartnamede Düzeltilmesi Gereken Noktalar

`SYNCDESKTOP.md`'de **hatalı veya eksik** bulunan maddeler. Şartname bağlayıcı olduğu için
burada yalnız **rapor edilir**; düzeltme kullanıcı onayıyla yapılır.

| # | Madde | Sorun | Bu dokümandaki karşılığı |
|---|---|---|---|
| S1 | §6.3 CSP | `connect-src`'ta `ipc: http://ipc.localhost` **yok** → `invoke()` tamamen bloke olur, uygulama açılmaz | §5.5 Düzeltme 1 |
| S2 | §6.3 CSP | `style-src 'self' 'unsafe-inline'` — Tauri nonce eklediğinde `'unsafe-inline'` **yok sayılır**; inline `style=""` üreten kütüphaneler bozulur | §5.5 Düzeltme 2 (`style-src-attr`) |
| S3 | §6.3 CSP | Dev'de CSP'nin **hiç uygulanmadığı** yazılı değil → "dev'de çalıştı, prod'da beyaz ekran" | §5.5 Düzeltme 3 (`devCsp`) |
| S4 | §7.1 `define` | `__PLATFORM__` yalnız desktop config'inde tanımlı; web build'inde tanımsız | §11 D-1 |
| S5 | §3 yerleşimi | `frontend/src/platform/index.ts` ile `desktop/src/platform/desktop.ts`'in **nasıl birleşeceği** tarif edilmemiş; naif bir `index.ts` içi seçim Tauri kodunu web bundle'ına sızdırır | KARAR A3 (entry-bazlı seçim) |
| S6 | §0.4 | `npm run build:desktop` regresyon kapısında ama script tanımı yok | KARAR A18 |
| S7 | §12 açık soru 4 | "route'u ikinci kez `api` grubuna kaydetmek mi?" — ikinci `withBroadcasting` **sessizce ölür** (aynı URI; `RouteCollection` ilkini döndürür) | §6.4 TUZAK 1: `Broadcast::routes` ile `/api/broadcasting/auth` |
| S8 | §4.3 | `SANCTUM_STATEFUL_DOMAINS` ile webview origin'i (`http://tauri.localhost`) ilişkisi yazılı değil → bearer'lı POST **419** alır | §6.4 TUZAK 2, KARAR A12 |
| S9 | §7.1 `onlineOnly<T>(fn)` | Tooltip anahtarı `desktop.onlineOnly.<action>` aksiyon adını gerektirir; imzada aksiyon parametresi yok | §3.3 (`onlineOnly(action, fn)`) |
| S10 | §6.4 | Pencere kapatma varsayılanı belirtilmemiş | §11 D-8 |

Ayrıca §12 açık soru 3'ün cevabı **olumludur ve iyi haberdir**: hiçbir bileşen API'yi
doğrudan çağırmıyor; dokunuş listesi 15 hedefine karşı **8** (muhtemelen 5) dosyada kalıyor
(§3.7).

---

## 13. Öneriler (uygulanmadı)

Şartnamede olmayan, bu dokümanda **uygulanmamış** fikirler (`SYNCDESKTOP.md` §0.5).

1. **`@` alias'ını `frontend/vite.config.ts` + `tsconfig.app.json`'a da eklemek.** Bugün
   `src` altında `@` kullanan tek import yok; iki tarafta aynı alias'ı tanımlamak ileride
   paylaşılan kodun yerini değiştirmeyi ucuzlatır. Uygulanmadı: KARAR A1 (web config'i
   değişmez).
2. **`platform` katmanı için sözleşme testi.** `webPlatform` ile `desktopPlatform`'un aynı
   `DataSource` metot kümesini implemente ettiğini çalıştırma zamanında da doğrulayan saf
   Node betiği (`scripts/check-platform-parity.mjs`, `check-i18n-parity.mjs` deseniyle).
   Uygulanmadı: şartnamede yok; projede Vitest/Jest kurulu değil.
3. **`EngineEvent` → query key eşlemesini api modüllerinin yanına koymak.** Her
   `<domain>Keys` factory'sinin yanına bir `entity` etiketi eklenirse §3.6 tablosu elle
   tutulmak yerine türetilir. Uygulanmadı: 15+ api dosyasına dokunmak gerekir, dokunuş
   listesi hedefini bozar.
4. **Web'de de `platform.connectivity`'yi kullanmak** (mevcut `navigator.onLine` tabanlı
   davranışları tek yere toplamak). Uygulanmadı: web davranışı değişmez (KARAR A1).
5. **Tray'in `conflict` durumunu bildirimle eşlemek** (çakışma oluştuğunda sessiz native
   bildirim). Uygulanmadı: §6.4 bildirim listesi kapalı bir kümedir (SLA, görev, mention);
   yeni bildirim tipi eklemek özellik eklemektir.
6. **`desktop/index.html`'i `frontend/index.html`'den üretmek** (kopya yerine build-time
   şablon). Uygulanmadı: iki dosya arasındaki fark yalnız `<script src>` satırıdır; şablon
   makinesi eklemek karmaşıklığı gereksiz artırır.

---

# EK — F0 KARAR TUTANAĞI

> Bu ek, §11'deki açık kararları (D-1…D-8) ve §12'deki şartname düzeltmelerini (S1–S10) sonuçlandırır. **§11 ve §12 artık açık madde içermez.** Sync protokolüne ait kararlar için bkz. `docs/DESKTOP-SYNC-PROTOCOL.md` §8.

## E.1 Açık kararlar — sonuçlandı

| # | Karar | Gerekçe |
|---|---|---|
| **D-1** | **`__PLATFORM__` define'ı HİÇ kullanılmaz**; seçim entry'de yapılır (KARAR A3) | `src` altında `@` import'u ve `__PLATFORM__` tüketicisi sıfır. Yalnız bir build'de tanımlı bir global, paylaşılan koda ilk sızdığı gün web build'ini kırar. |
| **D-2** | **`envDir: '../frontend'`, tek `.env`** | Sessiz ayrışma en pahalı arıza sınıfıdır; web build'i tanımadığı `VITE_` değişkenini görmezden geldiği için desktop'a özgü değişkenin aynı dosyada durması zararsızdır. |
| **D-3** | **API host build-time sabit** (v1) | CSP ve updater manifest'i zaten build-time. Runtime host CSP'yi joker'e gevşetir ve F6 kabulüyle çelişir. Kurulum başına host `VITE_API_URL` build parametresiyle çözülür; F7 CI bunu parametrize edebilir. |
| **D-4** | **Ölçüm F3'ün 1 NUMARALI maddesi** — Windows WebView2 ve WSL2 WebKitGTK ayrı ayrı, gerçek çıktıyla. Varsayım YASAK. **Fallback şimdiden onaylı:** kalıcı değilse `syncra-theme`/`syncra-locale`/`syncra-sidebar` `desktop_settings`'e taşınır ve `Platform`'a minimal `storage {get,set}` üyesi eklenir. | Ölçülmemiş şeye karar yazılmaz; ama iki dalın kararı da önceden verilirse ölçüm F3'ü bloklamaz ve F3 ortasında durulmaz. |
| **D-5** | **ONAYLANDI** — `Entity`→queryKey tablosu F3'te tamamlanır, otomatik türetme kalıcı olarak yasak | Doğrulanmış karşı örnekler (`searchKeys.all = ['global-search']`, `boardKeys.all = ['deals','board']`, `exchangeRatesKeys`'te `all` alanı yok) tahmini eşlemeyi diskalifiye ediyor. Sözleşme dondurmayı bloklamaz. |
| **D-6** | **`check-i18n-bootstrap.mjs` genişletilir** — `main.desktop.tsx`'i de kapsar, desktop girişiyle **aynı commit'te** | Aksi hâlde "arayüz sessizce Türkçe" hatasını hiçbir kapı yakalamaz. Betik ve desen zaten var; genişletme ucuz. |
| **D-7** | **ONAYLANDI** — `desktop` namespace alt ağaçları F4'te sabitlenir | F3 kabul kapsamı taşmasın; erken sabitlenen anahtarlar F4'te yeniden yazılırdı. |
| **D-8** | **Pencere kapatma varsayılanı: tray'e küçült** | Arka plan sync + native bildirim + badge, değer önerisinin çekirdeği; "çık" varsayılanı bu üçünü fiilen kapatır. Ayarla değiştirilebilirlik şartnamede zaten var. |

## E.2 Şartname düzeltmeleri S1–S10 — 10'u da KABUL

Hepsi ya "uygulama çalışmaz" sınıfı teknik zorunluluk ya da şartnamedeki bir boşluğun tek tutarlı doldurulmasıdır.

| # | Özet |
|---|---|
| S1 | CSP `connect-src`'a `ipc: http://ipc.localhost` — yoksa `invoke()` bloke, uygulama hiç açılmaz. |
| S2 | `style-src-attr 'unsafe-inline'` — Tauri nonce eklediğinde tarayıcı `'unsafe-inline'`ı yok sayar. |
| S3 | Dev'de CSP hiç uygulanmaz (webview doğrudan `devUrl`'i yükler); ayrı dev CSP için `app.security.devCsp`. |
| S4 | = D-1 (`__PLATFORM__` define'ı kullanılmaz). |
| S5 | Entry-bazlı platform seçimi şartnameye yazılır; naif `index.ts` çözümü web bundle'ına Tauri kodu sızdırır. |
| S6 | A18 script tanımları. |
| S7 | = protokol D9 (`Broadcast::routes()` ile `/api/broadcasting/auth`). |
| S8 | `SANCTUM_STATEFUL_DOMAINS` kuralı (KARAR A12) — `tauri.localhost` liste dışında tutulur, yoksa bearer POST 419 alır. |
| S9 | `onlineOnly(action, fn)` imzası — tooltip anahtarı aksiyon adını çağrı yerinde gerektirir. |
| S10 | = D-8. |

## E.3 W1 kısıtları — F3a şeridi için BAĞLAYICI

1. **F3a, §3.7 dokunuş listesinin yalnızca 1–5 numaralı dosyalarına** (`platform/{types,web,index}.ts`, `lib/axios.ts`, `lib/echo.ts`) **artı `frontend/src/index.css`'in `@source "./";` satırına dokunur.**
2. **6, 7 ve 8 numaralı dosyalar (`stores/themeStore.ts`, `i18n/index.ts`, `components/layout/AppLayout.tsx`) W1'de DOKUNULMAZ.** Gerekçe: bu üçünün kaderi D-4 ölçümüne bağlı, ölçüm ise Tauri kabuğu gerektiriyor ve kabuk W1'de henüz yok. Yan fayda: `check-i18n-bootstrap.mjs` kırılma riski W1'den tamamen çıkar.
3. **`backend/.env.example`'a `SANCTUM_STATEFUL_DOMAINS` notunu (KARAR A12) F1 şeridi ekler.** Dosya `backend/**` sahipliğindedir; bu not F1'in iş listesine yazılmazsa iki şerit de "benim değil" der ve not düşer.

## E.4 Öneriler — karara bağlandı

| Öneri | Karar | Gerekçe |
|---|---|---|
| §13/1 `@` alias'ını web config'e eklemek | **HİÇ** | `@` kullanan sıfır import var; KARAR A1 (web config değişmez) daha değerli. |
| §13/2 platform parity betiği | **HİÇ** | TypeScript her iki implementasyonu aynı `DataSource` arayüzüne derleme zamanında zaten kilitler. |
| §13/3 `Keys` factory'lerine entity etiketi | **HİÇ** | 15+ dosya dokunuşu, dokunuş-listesi disiplinini bozar; A5 tablosu + D-5 yeterli. |
| §13/4 web'de `platform.connectivity` | **HİÇ** | KARAR A1'i deler, web'e kazancı sıfır. |
| §13/6 `index.html` şablonlama | **HİÇ** | Tek satırlık fark için build makinesi eklemek net karmaşıklık artışı. |
| §13/5 tray conflict bildirimi | **SONRA — kapsam genişletmesi, kullanıcı onayı gerekir** | Şartnamenin bildirim listesi kapalı küme; F5 sonrası Conflict Inbox gerçek kullanımda görülünce yeniden sunulur. |
| İlk tray'e küçültmede tek seferlik ipucu (D-8'den) | **SONRA — kapsam genişletmesi, kullanıcı onayı gerekir** | UX iyileştirmesi ama şartnamede yok; F5-1 raporunda tekrar sunulur. |

---

## E.5 F3a BULGUSU — `DataSource` dikişinin yeri (A4 ile dokunuş listesi çelişiyor)

**Durum:** F3a'da (commit öncesi, `feat/desktop-platform`) `DataSource` alanları `typeof import('../features/<domain>/api/<x>Api')` ile tiplendi. Bu **geçici bir iskelettir ve F3b'de değiştirilecektir.**

**Sorun:** O modüller React hook'ları export ediyor (`useDeals`, `useCreateDeal`, …). Bu tiple `DataSource`'un desktop implementasyonu hook'ları yeniden yazmak zorunda kalır — K1'in ("UI yeniden yazımı yok") ve KARAR A4'ün ("hook paylaşımlı kalır, yalnız `queryFn` delege eder") tam olarak engellemek istediği şey. Web'de fark edilmez çünkü `data.deals` modülün kendisidir (özdeşlik); desktop'ta sonsuz regres olur: paylaşılan hook `platform.data.deals.useDeals`'i çağırır, o da kendisidir.

**Kök neden ve asıl bulgu:** ~15 feature api modülünde düz `fetchX`/`xRequest` fonksiyonları **modül-private**. Doğru bir `DataSource` yazmak o modülleri açmayı gerektiriyor. Yani:

> **KARAR A4, §3.7'deki "6-7 dosyalık dokunuş listesi" tahminiyle çelişir.** F0 keşfi "hiçbir bileşen API katmanını bypass etmiyor" derken haklıydı, ama dikişin *nerede* atılacağı hafife alınmıştı: **axios/echo seviyesinde** 6 dosya yeter, **per-domain veri arayüzü seviyesinde** yetmez — A4'ün gerektirdiği delegasyon ~15 dosya daha demektir.

**KARAR A19 — F3b'nin 1. maddesi.** `DataSource` fiil-bazlı yeniden tanımlanır (`list/get/create/update/delete` + alana özgü `assign`, `move`, `convert`, `timeline`, `status`…) ve ~15 feature modülünde düz fonksiyonlar export edilir. **Bu bir kapsam genişletmesi DEĞİLDİR** — A4'ün zaten gerektirdiği iştir, yalnızca tahmin yanlıştı. §3.7'nin dokunuş listesi bu sayıyla güncellenir.

**Bugün kırılan bir şey yok:** `platform/*` hiçbir entry noktasından import edilmediği için çıktı inert; JS bundle'a girmediği doğrulandı (hash karşılaştırmasıyla). Risk, `desktop.ts` bu temelin üstüne kurulursa yeniden yazılmasıdır.

### E.5.1 A19 SONUCU — dokunuş listesi ikiye ayrılıyor

A19 uygulandı ve doğrulandı (W2-a). Sonuçlar:

- **`DataSource` artık fiil-bazlı:** 16 domain, **124 metot**, hepsi mevcut bir düz fonksiyonun 1:1 karşılığı. `typeof import(...)` tiplemesi tamamen kalktı; hook / `Keys` factory'si / `queryClient` sözleşmeye girmiyor. Uydurma metot yok — uç nokta casusu ile 124/124 metodun beklenen fiil+URL'e istek attığı kanıtlandı.
- **KARAR A4 fiilen uygulandı:** hook'ların `queryFn`/`mutationFn`'i `getPlatform().data.<domain>.<metot>()` çağırıyor. Query key, hook adı, imza, dönüş tipi ve `enabled`/`staleTime`/`retry` opsiyonları değişmedi.

**§3.7'nin "8 dosyalık dokunuş listesi" güncelleniyor.** Gerçek yüzey ikiye ayrılır:

| Katman | Dosya | İçerik |
|---|---|---|
| **Adaptör çekirdeği** | **6** | `platform/{types,web,index}.ts` + `lib/axios.ts` + `lib/echo.ts` + `index.css` |
| **A19 delegasyon geçişi** | **26** | 14 feature `api/*.ts` + 12 feature `hooks/*.ts` |
| **W1'de ertelenen (D-4'e bağlı)** | **3** | `stores/themeStore.ts`, `i18n/index.ts`, `components/layout/AppLayout.tsx` |

F0'ın "≤15 dosya" hedefi **adaptör çekirdeği için** tutmuştur (6). A4'ün gerektirdiği delegasyon geçişi ayrı bir kalemdir ve F0 tahmininde yoktu (bkz. §E.5 kök neden). Bileşen ve sayfa dosyaları **hiç değişmedi** — K1 korundu.

**Yeni bulgu — ESM döngüsü (ölçüldü, zararsız).** Hook'lar `platform`'u, `platform/web.ts` de api modüllerini import ettiği için gerçek bir modül döngüsü doğdu. Üç farklı değerlendirme sırasıyla (platform önce / api modülü önce / hook önce) gerçek kaynak modüller çalıştırıldı, üçünde de 16 domain / 124 metot çağrılabilir geldi. Döngüyü zararsız kılan iki şey **korunmalıdır**:
1. `web.ts`'in üyeleri çıplak fonksiyon referansı değil **ok sarmalayıcıdır** (çözüm çağrı anında olur).
2. `index.ts`'teki `getPlatform` **hoist edilen bir `function` bildirimidir** (arrow const değil).

**AÇIK — F3'te kapatılacak:** `platform/web.ts` artık web bundle'ında çalışıyor (bundle 1741.74 → 1749.45 kB). `configureHttp()` ve `configureRealtimeAuth()` ilk kez gerçekten yürütülüyor. Kod incelemesi davranış-nötr diyor (aynı `baseURL`, aynı `withCredentials/withXSRFToken`, `defaultReverbAuthorizer` eski gövdenin kopyası) ama **tarayıcıda duman testi yapılmadı** — `frontend`'de test koşucusu yok. F3'ün ilk maddelerinden biri olmalı.

**AÇIK — kapsam dışı kalan api modülleri:** `boardApi`, `importApi`, `catalogApi`, `productsShared`, `logsApi`, `presenceApi`, `authApi`, `settings/api`, `dashboard/api`, `reports/api` `DataSource`'a girmedi. Bunların desktop'ta online-only HTTP olarak mı kalacağı F3'te açıkça karara bağlanmalı (§8 online-only listesi çoğunu zaten kapsıyor, ama `boardApi` — Kanban — kapsamıyor ve F4'te offline move isteniyor).

### E.5.2 W2-b SONUCU — açık kalan üç madde

`desktop/src-tauri` iskeleti kuruldu ve doğrulandı (workspace clippy `-D warnings` temiz, crate 83/83). CSP'nin S1/S2 düzeltmeleri **uygulandı** ve fazlası yapıldı:

```
default-src 'self'; connect-src 'self' ipc: http://ipc.localhost http://localhost:8000 ws://localhost:8080;
img-src 'self' data: http://localhost:8000; style-src 'self' 'unsafe-inline'; style-src-attr 'unsafe-inline';
font-src 'self' data:; object-src 'none'; frame-ancestors 'none'
```

**AÇIK 1 — CSP host'ları sabit kodlanmış (F3/F7 engeli).** `http://localhost:8000` ve `ws://localhost:8080` geliştirme host'larıdır ve şu an **üretim CSP'sinde** duruyor. KARAR D-3 API host'unu build-time sabit yapıyor; dolayısıyla CSP de build parametresinden (`VITE_API_URL` ile aynı kaynaktan) üretilmelidir. Bugünkü hâliyle üretim paketi yalnızca `localhost`'a bağlanabilir. F7'nin release job'ı bunu parametrize etmeden imzalı paket üretmemeli.

**AÇIK 2 — `desktop/.cargo/config.toml` yerel önbelleğe bağlı.** Dosya `build.target-dir`'ı `crates/syncra-sync/target`'a yönlendiriyor. Gerekçesi, o dizindeki sıcak OpenSSL derlemesini yeniden kullanmaktı — ama **çift derleme sorununu asıl çözen workspace'in kendisidir**; temiz bir checkout'ta o dizin zaten boştur ve yönlendirme hiçbir şey kazandırmaz. Yan etkisi: workspace çıktısı bir üye crate'in dizinine yazılıyor, bu da CI'da ve yeni katkıcılar için kafa karıştırıcı. **Öneri: dosya kaldırılsın, varsayılan `desktop/target` kullanılsın.** MAX_PATH ihtiyacı varsa `CARGO_TARGET_DIR` ortam değişkeniyle (CI'da olduğu gibi) çözülmeli, commit'li config ile değil.

**AÇIK 3 — `desktop/package.json` henüz yok.** W2-c'nin `desktop-ci.yml`'i `npm run tauri -- build --debug` varsayıyor. Vite/desktop entry işi (F3) bu dosyayı oluşturunca script adı CI ile hizalanmalı.

Ayrıca: `desktop/src-tauri/target` (996 MB) workspace öncesinden kalma ölü dizin — gitignore'lu, zararsız, ama diskte duruyor.

### E.5.3 W2-b — DÜZELTMELER ve BİR MAYIN

**⚠️ MAYIN — `tauri dev` / `tauri build` şu an PANİKLER.** `desktop/src-tauri/tauri.conf.json`'da `plugins` bloğu **hiç yok** (`plugins: null`, doğrulandı), dolayısıyla `plugins.updater.{endpoints, pubkey}` tanımsız. Tauri'nin updater Config'inde `pubkey: String` **zorunlu ve default'suz** → plugin `.setup()`'ta panikler. F7 gerçek pubkey/manifest'i getirene kadar **kimse `tauri dev` çalıştırmamalı**; F7'nin ilk maddesi bu olmalı. (Şerit sahte değer yazmamayı bilinçli tercih etti — doğru karar, ama mayın kayıt altına alınmalı.)

**Düzeltme 1 — clipboard capability'si zaten doğru.** `capabilities/default.json` `clipboard-manager:allow-read-text` iznini **vermiyor**; kelime yalnız `description` alanında "deliberately ABSENT… K10, default off" gerekçesiyle geçiyor. Verilen izinler dar: blanket `fs:allow-*` yok (yalnız `fs:scope`), `shell` yalnız `allow-open`.

**Düzeltme 2 — makinede çalışan bir Perl VAR.** Strawberry Perl kurulu değil, ama `C:\xampp\perl\bin\perl.exe` (5.32) hem `ExtUtils::MakeMaker` hem `Locale::Maketext::Simple` taşıyor. Sorun kurulum eksikliği değil, **PATH sırası**: Git-Bash'in `/usr/bin/perl`'i önce geliyor. `desktop-ci.yml`'deki "Strawberry Perl" adımı yerel geliştirme için de `PATH`'e XAMPP perl'ini önceleyerek çözülebilir.

**Düzeltme 3 — `custom-protocol` feature'ı bilinçli olarak default değil.** Bu sayede `desktop/dist` yokken bile `cargo check` panic'lemeden geçiyor (Tauri kod üretimi o durumda `frontendDist`'i gömmeyi atlıyor). F3 `vite.desktop.config.ts`'i yazıp `dist` üretmeye başlayınca `tauri build --features custom-protocol` yolu devreye girecek.

**Kayda geçen üç implementasyon kararı (şartnamede adı geçmiyordu):** bundle identifier `com.syncra.desktop`, productName `Syncra`, veri yerleşimi `$APPDATA/syncra/{syncra.db,cache}` (capability scope'uyla tutarlı). Ayrıca Rust tarafı API host'u için `SYNCRA_API_URL` env adı seçildi — D-3 yalnız frontend'in `VITE_API_URL`'ini adlandırıyordu, bu isim F7'de build parametrizasyonuyla birlikte gözden geçirilmeli.

**Küçük açık:** `storage::clear_local` motorun önbelleğe alınmış `SyncStatus`'unu yenilemiyor (`refresh_status()` dondurulmuş API'de private). Sonraki `mutate`/`sync_now` kendiliğinden düzeltiyor; `storage.rs`'te belgeli.

---

# EK 2 — F3-A SONUÇLARI

## D-4 ÇÖZÜLDÜ — `localStorage` Windows/WebView2'de KALICI

Ölçüm yapıldı (varsayım değil). İki oturum, arada süreç `Stop-Process -Force` ile tamamen öldürüldü:

```
Oturum A:  prev=NULL                       now=2026-08-31T07:44:20.634Z  keys=["syncra-theme","__d4_probe"]
Oturum B:  prev=2026-08-31T07:44:20.634Z   now=2026-08-31T07:44:50.633Z  keys=["syncra-theme","__d4_probe"]
```

**Sonuçları (bağlayıcı):**
- `syncra-theme`, `syncra-locale`, `syncra-sidebar` **olduğu yerde kalır**.
- §3.7 dokunuş listesinin **6-7-8. satırları düşer** — `stores/themeStore.ts`, `i18n/index.ts`, `components/layout/AppLayout.tsx` değiştirilmeyecek. W1'den beri süren yasak artık kalıcı bir karardır, geçici kısıt değil.
- `Platform`'a `storage {get,set}` üyesi **eklenmez** (E.1 D-4'ün fallback dalı iptal).
- Yan fayda: `check-i18n-bootstrap.mjs`'in kaynak-metin assert'leri hiç riske girmedi.

**Linux/WebKitGTK ÖLÇÜLMEDİ.** Gerekçe gerçek çıktı: WSL2 Ubuntu'da `cargo`/`rustc` yok, `pkg-config --modversion webkit2gtk-4.1` → NOT INSTALLED. K11 Linux'u birinci sınıf hedef sayıyor; **bu ölçüm F7 Linux paketlemesinden önce yapılmalıdır.** Sonuç farklı çıkarsa yukarıdaki üç karar Linux için yeniden değerlendirilir.

## §4.2 DÜZELTMESİ — `watch.ignored` yetersizdi (ölçülmüş çökme)

`.cargo/config.toml` kaldırılıp varsayılan `desktop/target` kullanılmaya başlanınca `target/` Vite root'unun **içine** girdi. Chokidar, çalışan uygulamanın açık tuttuğu `target/debug/deps/syncra_desktop_lib.dll`'i izlemeye kalkıp `EBUSY` fırlattı; dev server öldü, `tauri dev` "beforeDevCommand terminated with a non-zero status code" ile durdu.

**Doğru değer:**
```ts
watch: { ignored: ['**/src-tauri/**', '**/target/**', '**/.tauri/**'] }
```
§4.2'deki tek elemanlı liste artık **yanlıştır** ve dev server'ı çökertir.

## §4.2 EKLEMESİ — `resolve.dedupe` ZORUNLU

```ts
resolve: { dedupe: ['react', 'react-dom'] }
```
Kabuğun kendi `node_modules`'ı olduğu için React diskte iki kopya. Dedupe olmadan `desktop/src` ile `frontend/src` **ayrı React örneklerine** bağlanır ve tüm hook'lar "invalid hook call" ile ölür. Belirti sebepten uzak olduğu için bu satır kaldırılmamalıdır.

## E.5.2 / E.5.3 AÇIKLARI — durum

| Açık | Durum |
|---|---|
| E.5.2/1 CSP host'ları sabit | **KAPANDI** — `desktop/scripts/tauri.mjs` `frontend/.env`'den okuyup CSP'yi build-time üretiyor, `--config` ile birleştiriyor. `https://crm.example.com` + `wss://ws.example.com:443` ile de doğrulandı. |
| E.5.2/2 `.cargo/config.toml` | **KAPANDI** — kaldırıldı, soğuk derleme yeniden doğrulandı (7dk53sn). Yan etkisi yukarıdaki `watch.ignored` düzeltmesiydi. |
| E.5.2/3 `desktop/package.json` yok | **KAPANDI** — `dev:desktop`, `build:desktop`, `tauri` script'leri var; CI'ın `npm run tauri` sözleşmesi korundu. |
| E.5.3 updater mayını | **DEVREDİLDİ** — `#[cfg(not(debug_assertions))]` ile dev ve `--debug`'tan kaldırıldı. Sahte pubkey commit edilmedi (doğru karar). **Release binary hâlâ `plugins.updater` bloğu olmadan çalışmaz → F7'nin 1. maddesi.** |

## F3'ün DEVAMI İÇİN AÇIK KALANLAR

1. **`data`: 124 metottan 0'ı bağlı.** `IMPLEMENTED` map'i bilerek boş; her çağrı `NOT_IMPLEMENTED` fırlatıyor (sessiz `undefined` yok). Login ekranı auth'u `authApi`/axios üzerinden yaptığı için açılıyor, ama **login sonrası her ekran patlar**. Sıradaki turun 1. maddesi: `NamedQuery` beyaz listesi + row→DTO eşlemesi.
2. **Event bridge yok.** `desktop/src/bridge/{events,realtime}.ts` yazılmadı; `handle_realtime` komutu Rust tarafında da kayıtlı değil. Desktop şu an web gibi doğrudan Echo'ya abone — KARAR A11 (realtime → motor → mini-pull) henüz uygulanmadı.
3. **D-6 yapılmadı.** `check-i18n-bootstrap.mjs`'in `main.desktop.tsx`'i kapsaması `frontend/scripts/**` yazmayı gerektiriyordu. Desktop girişindeki açılış kapısı bugün elle doğru ama **hiçbir otomatik kapı korumuyor**.
4. **Gerçek login akışı denenmedi** — `:8000`'de başka bir servis var (`/api/me` → 404). Backend ayağa kalkınca device token akışı uçtan uca denenmeli.
5. `desktop/src-tauri/target` (996 MB, workspace öncesinden ölü) diskte duruyor — silme onayı bekliyor.

---

# EK 3 — F3-C KARARLARI (veri katmanı bağlandı)

124/124 metot bağlı, `NOT_IMPLEMENTED` sıfır. Sınıflandırma: **50 yerel okuma** (`query`), **44 yerel yazma** (`mutate`), **30 online-only** (`http`). `NamedQuery` 16 → 30 varyant; 27 kullanılıyor, 3'ü gerekçeli rezerve. Crate 83 → **103 test**.

Bütünlük `desktop/scripts/check-data-wiring.mjs` ile kilitli. Kontrolün gerçek olduğu bağımsız negatif testle doğrulandı: `users.list` `http` → `query` yapıldığında üç ayrı hata verdi (gövde yerel okuma yapmıyor · gövde `platform.http` çağırıyor · §8 ihlali) ve geri yüklenince OK döndü. Manifest beyanını **fonksiyon gövdesiyle** karşılaştırıyor, yalnız etiket okumuyor.

## Onaylanan kararlar

| # | Karar | Gerekçe |
|---|---|---|
| **A20** | `is_overdue` (deal/task) **yerelde hesaplanır** | Tip dosyası "sunucu hesaplar" diyor ama offline'da sunucu değeri yok; `false` dönmek **her gecikmiş kaydı gizlerdi**. Kural belirsiz değil: geçmiş tarih + bitmemiş. Sessizce yanlış veri, açıkça türetilmiş veriden kötüdür. |
| **A21** | §8 dışı `http` yönlendirmeleri onaylandı | Üçü de sözleşmeden türetilmiş, icat değil: `leads.checkDuplicates` (sunucu algoritması, yerelde çalıştırılamaz) · `chat.{recordConversation,addMembers,removeMember,leaveConversation}` (crate'in `ACTION_WHITELIST`'i dışında ⇒ sözleşme gereği zaten `ONLINE_ONLY`) · `products`/`priceLists`/`savedViews` yazmaları (RO entity, `mutate()` zaten reddediyor). |
| **A22** | `can.*` izinleri permissive (`true`) | Satır bazlı izinler senkron kapsamında değil; `false` masaüstünü offline salt-okunur yapardı. KARAR A14'ün üçüncü katmanı (push reddi) veri bütünlüğünü koruyor. **Bedeli F4'e taşınıyor:** kullanıcı yetkisi olmayan bir aksiyonu deneyebilir ve hata **push anında** görünür — Conflict Inbox bunu "reddedildi" olarak anlaşılır biçimde göstermek zorunda. |
| **A23** | SLA türetilmiş alanları `null`/`0` döner | `sla_remaining_seconds`/`sla_total_seconds`/`sla_target_hours` aynada yok ve `docs/SLA-DESIGN.md` sunucuyu tek otorite yapıyor. **Yanlış sayan bir sayaç yerine hiç sayaç** doğru tercih. |
| **A24** | `uploadAttachment` şimdilik doğrudan ağa gider | §8 onu "kuyruğa alma" sınıfında sayıyor ama kuyruk `files::attach_from_paths` (F5-5) henüz yok ve webview'daki `File` handle'ının Rust'a verilecek yolu yok. Offline'da **gürültülü hata veriyor**, sessizce kuyruğa girmiş gibi görünmüyor — sahte başarıdan iyidir. F5-5'te kuyruğa bağlanacak. |

## BACKEND'E DEVREDİLEN İKİ GERÇEK BOŞLUK (F1 takibi)

1. **Bildirim metni.** `NotificationResource` `title`/`body`'yi `data.title_key` + **Laravel PHP çeviri kataloğundan** üretiyor; masaüstünde o katalog yok (frontend i18n namespace'leri ayrı). Eski satırlar düz `title` taşıdığı için doğru basılıyor, **yeni satırlarda ham anahtar görünecek**. Çözüm sunucu tarafında: pull payload'ına render edilmiş `title`/`body` eklenmeli. İstemcide uydurulmadı.
2. **SLA türetilmiş alanları** (A23) pull satırına eklensin ya da formül `docs/SLA-DESIGN.md`'de istemciye açılsın.

## DOĞRULANMASI GEREKEN VARSAYIM

`tag_ids` + `tags` **çift anahtar**: payload hem REST alanını hem ayna kolonunu taşıyor. Laravel'in FormRequest'i fazladan `tags` anahtarını varsayılan olarak yok sayar — **ama bu test edilmemiş bir varsayım**; yanlışsa push 422 döner. F1 takibinde bir test ile kilitlenmeli.

## YOL BOYUNDA YAKALANAN LATENT HATA

`EngineEvent::TablesChanged(Vec<Entity>)` ve `ConflictAdded(Uuid)` internally-tagged **newtype** varyantlardı; serde bunları **çalışma anında** serileştiremez. Olay emisyonu bu turda eklendiği için hata ilk `TablesChanged`'de patlayacaktı. Tüm varyantlar struct varyanta çevrildi + JSON round-trip testi yazıldı. F2'nin 83 testi bunu yakalayamamıştı çünkü hiçbir test olayı serileştirmiyordu.

## KÜÇÜK BOŞLUKLAR (kayda geçti, F4'te kapanacak)

`Conversation.last_message_preview` = `null` · `Message.tick` daima `'sent'` (ayna `delivered` imleci taşımıyor; `TickState` monoton olduğu için en düşük değer güvenli) · `Company.primary_contact` liste sayfasında `null`, detayda gerçek · `tickets.stats.at_risk_count` ve `notes_count` = `0` · `mapUser`'da `role`/`last_login_at`/`must_change_password` §4.1 projeksiyonu dışında olduğu için yok.

## HÂLÂ AÇIK

- **KARAR A11 uygulanmadı:** `bridge/realtime.ts` + Rust `handle_realtime` yok. Desktop şu an web gibi doğrudan Echo'ya abone; realtime olayı motoru tetiklemiyor, mini-pull yapılmıyor.
- **Gerçek login/uçtan uca akış denenmedi** — backend `:8000`'de ayakta değil.
- `boardApi` `DataSource` dışında; `deals_board`/`pipeline_stages` varyantları rezerve bekliyor. Board'un adaptöre alınması `frontend/**` dokunuşu gerektiriyor — F4 kararı.

---

# EK 4 — A11, BACKEND TAKİBİ VE THREAT MODEL

## KARAR A11 UYGULANDI — realtime artık motoru tetikliyor

Masaüstünde Echo olayı **doğrudan `invalidateQueries` çağırmıyor**; `invoke('handle_realtime')` ile motora gidiyor, motor mini-pull yapıyor, `TablesChanged` de EK 3'ün `bridge/events.ts` köprüsünden cache'e dönüyor. `desktop/src` genelinde `invalidateQueries` **tek çağrı yerinde** (`bridge/events.ts`) — yapısal kontrol bunu kilitliyor.

`handle_realtime` üç yerde birden kayıtlı ve isim uyuşması statik olarak doğrulanıyor: TS `invoke` adı · Rust `#[tauri::command] fn` · `lib.rs` `generate_handler!`. Bu üçlünün sessizce ayrışması en olası kırılma noktasıydı.

**Kapsanan:** 7 web kanalı → 4'ü motora yönlendirildi, 3'ü gerekçeli UNMAPPED. 15 olay → 12 binding + 3 UNROUTED.
- `presence-online` — **A11'in tek istisnası**: kalıcı veri değil, ayna tablosu yok.
- `private-dashboard`, `private-logs` — §8 online-only yüzeyler, çekilecek ayna satırı yok. (`logs` = Spatie audit log; aynalanan `activities` entity'siyle **aynı şey değil** — bu ayrım kodda yorumlu.)
- `.user.deactivated` UNROUTED — oturum yıkımı, veri değişimi değil; motor 401 → `AuthLost` yolundan öğreniyor.

### AÇIK RİSK — `Echo.leave()` savaşı (mimari, çözümü `frontend/**`'de)

Web hook'ları unmount'ta `echo.leave()` çağırıyor (`useDealRealtime.ts:153`, `useTicketRealtime.ts:131`, `useTaskReminders.ts:72`, `useRealtimeSession.ts:51`, `useNotificationSocket.ts:96`) ve **`leave` referans saymaz** — kanalı ve üzerindeki *tüm* dinleyicileri, köprününki dahil kapatır.

Bugünkü savunma bir **workaround**: köprü 5 sn'de bir kanal nesnesi kimliğini karşılaştırıp yeniden abone oluyor. Boşluk sınırlı — motorun 60 sn'lik döngüsü satırı zaten çekiyor, yani kaçan bir ipucu **geciktirir, kaybettirmez**.

**Kalıcı çözüm bir `frontend/**` kararıdır:** `useDealRealtime`/`useTicketRealtime`/`useTaskReminders`/`useRealtimeSession` `frontend/src/features/chat/hooks/conversationChannel.ts`'in **zaten uyguladığı** referans sayan registry desenine geçirilirse watchdog tamamen gereksizleşir. Ayrı bir tur olarak açık.

### Bilinçli sınır
`private-conversation.{id}` yalnız **açık odalarda** motora akıyor (attach modu — kanal id'leri chat registry'sinin malı, köprü kapatılmış bir odayı diriltmemeli). Kapalı odaların mesajları `.chat.unread`'den geliyor, ama `.message.read/.delivered` imleç olayları ulaşmıyor → `conversation_user` imleçleri bir sonraki tam pull'a kadar bayat kalabilir.

### ŞARTNAME DÜZELTMESİ
`SYNCDESKTOP.md` §6.2 komut listesinde **`handle_realtime` yok** — oysa aynı belgenin §5.2'si ve mimari §6.3 bu akışı zorunlu kılıyor. §6.2'ye eklenmeli.

---

## KARAR A25 — 401 ile deaktivasyon aynı olay DEĞİLDİR

`SYNCDESKTOP.md` kendi içinde çelişiyordu (F6-A buldu, teknik lider doğruladı):
- §5.5 ve §5.7 → *"401 → AuthLost (**outbox korunur**, aynı user login → devam; farklı user → wipe)"*
- §9 madde 2 → *"Deaktive/silinen kullanıcı → 401 → lokal DB + keychain **tamamen wipe**"*

> **Atıf notu (2026-08-31):** bu üç alıntı özgün olarak `SYNCDESKTOP.md:342/:350/:414` satır numaralarıyla verilmişti. Şartname SPEC-1 turunda revize edildiği için satır numaraları kaydı; atıflar bölüm çapalarına taşındı ve **karar bu revizyonla şartnameye işlendi** (`SYNCDESKTOP.md` §13.1, A25 satırı). Karar belgelerinde satır numarasıyla atıf verilmez.

Crate `sync/mod.rs:1001` §5.5'i uygulamış: token silinir, şifreli DB kalır.

**Karar — ikisi ayrı sinyale bağlanır:**

| Sinyal | Davranış | Gerekçe |
|---|---|---|
| **403 `USER_DEACTIVATED`** | **Wipe** — lokal DB + keychain | `EnsureUserIsActive` bunu açıkça döndürüyor: sunucu-bilgili, kesin sinyal. §9/2'nin kastı budur. |
| **Genel 401** | **Outbox korunur**, `AuthLost`. Aynı kullanıcı geri girerse devam; **farklı kullanıcı → wipe** | Sebebi belirsiz (süresi dolmuş token, sunucu hıçkırığı). Naif "her 401'de wipe" masum kullanıcının bekleyen işini yok eder. |

Şartname bu iki olayı karıştırmıştı; ayrım sinyale bağlanınca §9/2 de §5.5 de sağlanıyor.

**Artık risk (kabul edildi):** silinmiş bir kullanıcının şifreli DB'si diskte kalır — o kullanıcı bir daha giriş yapamaz, veri retention penceresiyle veya farklı kullanıcı girişindeki wipe ile temizlenir. Anahtar o OS hesabının keychain'inde olduğu için erişim aynı OS hesabıyla sınırlıdır (SINIR 3'ün zaten iddia etmediği alan).

---

## KARAR A26 — SLA alanları sunucuda hesaplanıp pull satırına konur

A23 (`null`/`0` dön) geçici bir çözümdü; kalıcı çözüm netleşti.

**Bulgular (F1-B araştırması):** `sla_remaining_seconds`/`sla_total_seconds`/`sla_target_hours` web'de de **fiziksel kolon değil** — `TicketResource` bunları yanıt üretirken `SlaService::totalSeconds/remainingSeconds/targetHoursForTicket` (`app/Services/Tickets/SlaService.php:311-371`) ile hesaplıyor. Gerekli ham kolonlar (`sla_due_at`, `sla_paused_at`, `sla_paused_seconds`, `resolved_at`, `priority`, `status`) `tickets` tablosunda ve pull `SELECT *` çektiği için **zaten satırda**.

**Karar:** sunucu hesaplayıp pull satırına koyar. **Formül istemciye AÇILMAZ.**

`docs/SLA-DESIGN.md` §1 *"geri sayımı her zaman sunucu hesaplar, istemci yalnızca sunucudan aldığı kalan saniyeyi monoton saatle eritir"* diyor. Ham alanlardan istemcide yeniden hesaplamak bunu **ihlal eder**; sunucunun hesapladığı sayıyı pull satırına koymak **etmez** — pull da bir "sunucudan alma" anıdır, istemci sonra §6'daki mevcut "dondur + monoton saatle erit" davranışını uygular.

Yeni migration gerekmiyor. Uygulama sonraki backend turunda; `Ticket::newFromBuilder()` ile hydrate edilen modelin `SlaService`'in beklediği Carbon cast'lerini doğru taşıdığı **uygulama anında doğrulanmalı** (araştırmada kontrol edilmedi).

---

## BACKEND TAKİBİ KAPANDI (F1-B)

- **Bildirim metni:** `SyncPullService::renderNotificationText()` — `NotificationResource`'un kullandığı **aynı** render yolu (`NotificationText::resolve`) yeniden kullanıldı, kopyalanmadı (K7). Locale kaynağı: `SyncScope::applyRowScope()` `notifications`'ı zaten `notifiable_id = $user` ile kısıtlıyor, yani pull eden her zaman satırın sahibi — ikinci sorgu gerekmedi. `title_key`/`params` yerinde kaldı.
- **`tag_ids` + `tags` varsayımı DOĞRULANDI:** `StoreCompanyRequest`/`UpdateCompanyRequest` `rules()`'ında `tags` yok, Laravel kuralsız fazladan anahtarı `validated()`'dan sessizce düşürüyor — 422 yok, ek tolerans kodu gerekmedi. İki test kilitledi (update testi `tags`'ı `changed_fields`'a da koyup intersect adımını sınadı).
- Backend testleri: **1402 → 1407**.

---

## THREAT MODEL — `docs/DESKTOP-THREAT-MODEL.md` (F6-A)

19 satırlık STRIDE tablosu, §9'un 10 maddesi tek tek, 8 bulgu (1 ORTA, 3 DÜŞÜK, 4 BİLGİ). Doküman okumakla yetinilmemiş, **canlı kanıt** toplanmış: `$APPDATA` listelenip token/anahtar dosyası olmadığı, `head -c 16 syncra.db | od -c` ile başlığın `SQLite format 3` **olmadığı** gösterilmiş.

**§9 durumu:** madde 1, 3, 4, 7 KAPALI · **madde 2 AÇIK** · madde 5, 6 DEĞERLENDİRİLEMEZ-F5 · madde 8 DEĞERLENDİRİLEMEZ-F7 (bugün fail-closed) · madde 9 KAPALI (§9/9 log filtresi, EK 5) · madde 10 bu teslimat.

> ⚠️ **DÜZELTME (RISK-1 denetimi, 2026-08-31).** Bu satır önce "madde 2 A25 ile kapandı" diyordu. **Yanlıştı.** A25 bir *karardır*, uygulama değil — ve kodda hiçbir karşılığı yok: `transport.rs:137` 403'ü ayrıştırmadan `SyncError::Protocol`'e katlıyor, `USER_DEACTIVATED` masaüstü kodunda hiç geçmiyor (grep 0), `handle_auth_lost` yalnız 401'de çalışıyor. **Bugünkü davranış karardan da kötü:** deaktive edilmiş kullanıcı oturumu düşmeden "protocol error" görüyor ve wipe hiç olmuyor.
>
> Bu hata, kararı tutanağa geçirmenin işi bitirmekle karıştırılmasından doğdu. Yapısal önlemi `docs/DESKTOP-OPEN-ITEMS.md`: her madde **Karar / Kod / Test** sütunlarıyla izleniyor ve ancak üçü de ✅ olduğunda kapanıyor. Bu madde orada **O1**.

**§9/9 (tracing PII filtresi) F5'i bekleyemez.** Log plugin'i F3'ten beri **filtresiz DEBUG seviyesinde** diske yazıyor (`lib.rs:78`; canlı `Syncra.log`'da keyring DEBUG satırları var — girdi *adları* görünüyor, sır *değerleri* görünmüyor). Bugün sır sızmıyor ama bunu garanti eden bir katman yok. Bir sonraki turun adayı.

---

# EK 5 — F4 EKRANLARI, REFCOUNT REFACTORU, A26, LOG FİLTRESİ

## KARAR A27 — Masaüstü yüzeyi ROUTE değil, KABUK KROMASIDIR

F4-A routing sorusunu `frontend/**` içine hiç dokunmadan çözdü; DUR gerekmedi.

**Neden route eklenemiyor (araştırıldı):** `frontend/src/router.tsx` modül seviyesinde `createBrowserRouter([...])` kurup bitmiş router'ı export ediyor. React Router **7.18.2**'de kurulmuş bir data router'a route eklemenin desteklenen yolu yok — `patchRoutesOnNavigation` yalnızca *oluşturma* seçeneği (router nesnesinin yüzeyinde değil, typings ile doğrulandı), tek runtime kancası `router._internalSetRoutes` alt çizgili ve yayınlanan typings'te yok. Route eklense bile **gezinme** `Sidebar.tsx` içinde, o da yasak.

**Karar:** `main.desktop.tsx` artık `<PlatformProvider><DesktopShell><App/></DesktopShell></PlatformProvider>` render ediyor. Masaüstü ekranları bir panel olarak `App`'i sarıyor, route ağacına girmiyor.

Kazanç: sıfır `frontend/**` düzenlemesi · `/login` dahil **her route'ta** çalışıyor · `router.tsx` web'in byte-byte aynısı (K1 korundu).

## KARAR A28 — `desktop/src` üçüncü parti React kütüphanelerini çözemez

KARAR A1/A2 iki bağımlılık ağacını ayırdığı için `desktop/src` `@tanstack/react-query`, `react-i18next` ve `lucide-react`'i çözemiyor.

**Kalıcı sonuçları (F5 ve sonrası için de geçerli):**
- Masaüstü ekranları react-query değil düz `useState` + `invoke` kullanır.
- Çeviri, `@/i18n` **singleton'ına** bağlanan yerel bir `useT()` hook'undan gelir. İkinci bir i18next kurmak sözlüğü boş bırakırdı.
- İkonlar inline SVG.
- `desktop/package.json` içine bu iş için **hiç bağımlılık eklenmedi**.

## F4 durumu — §7.2'nin 5 maddesinden 4'ü tam

| # | Madde | Durum |
|---|---|---|
| 1 | Connectivity bar | TAM. Sabit sol-alt (akışa giremez: `AppLayout` `h-screen overflow-hidden` ve ona yazma izni yok). Durum önceliği `offline > syncing > conflict > online`. **İkinci poll açılmadı** — `subscribeToEngineStatus` store'u tek otorite olarak `EngineEvent::StatusChanged` dinliyor. |
| 2 | Kayıt rozetleri | **KISMİ.** `SyncStateBadge` bileşeni ve `PendingRecords` paneli (11 yazılabilir entity, outbox topolojik sırasında) hazır. Rozetin **kayıt satırlarına inmesi** `frontend/src/features/*/pages/*` düzenlemesi gerektiriyor — ayrı bir şerit. |
| 3 | Conflict Inbox | TAM. Diff tablosu, KeepMine/TakeServer/alan bazlı Merge, toplu çözüm. |
| 4 | Storage | TAM. Kullanım çubuğu (%80 warning / %100 danger), retention + MB tavanı (K8 alt sınırları UI'da da), Arşivi indir (offline disabled), Yerel veriyi temizle (geri alınamaz uyarısı + onay). |
| 5 | Devices | TAM. `list_devices`/`revoke_device`, `is_current` → "Bu cihaz". |

**A22'nin bedeli ele alındı:** `sync::conflicts` iki farklı şeyi tek listede döndürüyor — gerçek `FIELD_CONFLICT` (merge anlamlı) ve **reddedilenler** (`ONLINE_ONLY`, `UNRESOLVED_REFERENCE`, `ABILITY_REQUIRED`, `HTTP_403`, `RECORD_DELETED` — tek taraflı, merge edilecek şey yok). Liste **`code` alanına göre gruplanıyor**, her grubun başlığı o kodun `desktop.errors.*` cümlesi: kullanıcı "neden olmadı" sorusunun cevabını kendi dilinde başlıkta okuyor. Ayrım hem görsel (warning/danger) hem fonksiyonel (merge yalnız `conflicting_fields` doluyken). Yeni anahtar gerekmedi.

### F4 devamı için açık kalanlar

1. **Motorun settings getter'ı yok.** `update_settings` var, okuyanı yok; `StorageStats` iki tavanı taşıyor ama **`retention_days` taşımıyor**. UI şimdilik cihaz-lokal `localStorage` aynası + K8 varsayılanı (30) kullanıyor — yeniden kurulum sonrası bayat olabilir. **Kalıcı çözüm crate içinde bir `storage::settings` getter komutu.**
2. **Komut adı sözleşmeden farklı:** `generate_handler!` komutları **fonksiyon adıyla** kaydediyor, yani `storage_stats` değil `stats` (`src-tauri/src/lib.rs:95`). `src-tauri` şeridi adı değiştirirse `ui/commands.ts` sessizce kırılır.
3. **Dolu Conflict Inbox görsel olarak doğrulanmadı** — backend yok, çakışma üretilemedi, **sahte veri uydurulmadı**. Boş durum gerçek `conflicts` çağrısıyla doğrulandı; gruplama/diff/merge yolları yalnız `tsc` + kod incelemesi seviyesinde.
4. **`confirmSuffix` çevirileri kendi içinde tutarsız** (F3-B çıktısı): `tr` "kalın-isim + suffix" desenine göre yazılmış (repo emsali `AutomationRulesTab.tsx:179`), `en/de/fr` ise "Are you sure you want to..." gibi bir **giriş cümlesi** varsayıyor — o cümlenin anahtarı yok. Ya `desktop.confirm.lead` eklenmeli ya da üç dilin suffix metni yeniden yazılmalı.
5. **Eksik i18n anahtarları** (yazılmadı, `frontend/**` sahipliğinde): `desktop.entities.<entity>` (22 — şu an ham tablo adı basılıyor: `deal`, `company`...), `desktop.fields.<column>` (merge diff'inde ham kolon adı), `desktop.recordBadge.{pending,conflict}`, `desktop.conflicts.{rejected.title,rejected.description,retry,discard}`, `desktop.storage.{outbox.label,downloadArchive.description}`, `desktop.confirm.lead`.
6. **Arşivi indir ne kadar geçmiş indiriyor belirsiz** — `extra_days` olarak mevcut retention penceresi gönderiliyor. Sözleşmede adım tanımı yok.

## Echo.leave() REFCOUNT REFACTORU (FE-A)

`frontend/src/lib/channelRegistry.ts` eklendi (`acquireChannel`/`releaseChannel`, sayaç sıfırlanınca `leave`); `conversationChannel.ts` onun üstüne taşındı (dışa açık imzalar değişmedi, `useChatSocket`/`useTyping` dokunulmadı); **altı hook** geçirildi. Hiçbir `.tsx` değişmedi.

**Kanıt yöntemi örnek alınmalı:** prod mantığının `diff` ile doğrulanmış birebir kopyası sahte bir Echo'ya karşı koşuldu — `hookA` unmount olurken `hookB` dinleyicisi **yaşadı** (`hookBEvents=2`), `leave` yalnız **son** bırakışta tetiklendi. Üstüne **negatif kontrol**: eski referans-saymayan desen aynı mock'a karşı `hookBEvents=0` ile orijinal hatayı yeniden üretti.

Şerit kendi işinde bir hata yakalayıp düzeltti: `useNotificationSocket`/`useChatUnread` içinde `releaseChannel` yalnız kendi dedup sayacı sıfırlanınca çağrılıyordu — bu, registry sayacının **hiç sıfıra inmemesine** yol açıp bir sızıntıyı başkasıyla değiştirecekti.

**LATENT TUZAK (kayda geçti):** üç hook içinde ham `echo.leave()` duruyor — `useDashboardSocket.ts:61`, `useActivityStream.ts:61`, `usePresence.ts:59`. **Bugün zararsız**: köprü bu üç kanala abone değil, `bridge/realtime.ts` UNMAPPED tablosunda kayıtlı (doğrulandı). Ama köprü ileride bunlardan birine abone olursa hata geri döner.

## PLANLAMA HATASI — dosya sahipliği kesişimi yetmedi

Dört şeridi ayrık dosya kümelerine böldüm, ama **bir şeritteki doğrulama script'inin başka şeridin kaynağını taradığını** hesaba katmadım. FE-A hook'ları `acquireChannel`'a taşıyınca `desktop/scripts/check-realtime-wiring.mjs` (F3-F'in yazdığı, literal `echo.private('deals')` arayan) haksız yere 5 hata verdi — eşleme doğruydu, tarayıcının sezgisi kırıktı.

**Ders:** paralel şerit sınırı çizerken yalnız "kim hangi dosyaya yazıyor" değil, **"kimin kontrolü hangi dosyayı okuyor"** da sorulmalı.

## A26 UYGULANDI (F1-C)

`SyncPullService::attachTicketSla()` — dört alan (`sla_remaining_seconds`, `sla_total_seconds`, `sla_target_hours`, `sla_breached`) pull satırında; `SlaService` metotları çağrıldı, aritmetik kopyalanmadı. Migration gerekmedi.

**F1-B'nin işaretlediği doğrulanmamış nokta gerçek bir hata çıktı:** `Ticket::newFromBuilder()` **static değil**, instance metodu; ilk deneme `Non-static method cannot be called statically` ile patladı, `(new Ticket)->newFromBuilder($row)` ile düzeltildi. Varsayılıp geçilseydi sessizce yanlış tip geçirilecekti.

Testler dört senaryoyu (açık / duraklamış / çözülmüş / SLA'sız) **gerçek `GET /api/tickets/{id}` çağrısının `TicketResource` çıktısıyla** karşılaştırıyor, saat `travelTo()` ile dondurulmuş — "iki yol ayrışmıyor" iddiası kanıta bağlı. Backend testleri **1407 → 1411**.

## §9/9 LOG PII FİLTRESİ (F6-B) — kök neden tahminden farklı çıktı

Görev tarifim "`tracing` filtresiz DEBUG yazıyor" diyordu. **Gerçek mekanizma başkaydı:**

1. Uygulamada hiçbir yerde **`tracing::Subscriber` kurulmuyor** (`Cargo.lock` içinde `tracing-subscriber` yok). Subscriber olmadan `tracing::warn!` çağrıları **tamamen no-op** — `events.rs:36,40` fiilen sessizdi.
2. Diskteki gerçek DEBUG satırları `tracing`'den değil, **`log` fasadından** geliyordu: üçüncü parti crate'ler (`keyring`, `reqwest`, `hyper`, tauri dahili) → `tauri_plugin_log::Builder::new().build()` filtresiz varsayılanı (`LevelFilter::Trace`).
3. `syncra-sync/Cargo.toml` `tracing` bağımlılığını bildiriyor ama **hiç kullanmıyordu** — ölü bağımlılık, üstelik ileride eklenecek her `tracing::*!` çağrısı sessizce yutulacaktı.

**Çözüm üç katmanlı:**
- `tracing = { features = ["log"] }` — her `tracing` olayı `log::Record` olarak da yayılıyor; artık mevcut ve gelecekteki `tracing` çağrıları tek maskeleme hattından geçiyor. **Sessiz no-op riski kapandı.**
- `logging::level_for_build()` — debug build `Debug`, release `Info`. Plugin varsayılanı `Trace` idi.
- `logging::masking_format` — `Builder::format(...)` ile **tüm** fern hedeflerinden (stdout, dosya, webview relay) önce tek noktada e-posta ve E.164 telefon maskeleme. **Çağrı yerinde değil, yazım katmanında** — biri unutsa da tutuyor.

E.164 deseninde `+` zorunlu tutulmuş; gerekçesi sahte pozitif: `+`'sız rakam dizileri bu uygulamanın kendi loglarında zararsız ve sık (outbox sayaçları, byte toplamları, cursor'lar).

**Negatif test gerçek:** maskeleme bypass edilince test `email reached disk: ... jane@example.com, +14155552671` ile patladı, geri alınınca 4/4 yeşil. Release cfg doğrulaması `cargo test --release` ile yapıldı (`level_for_build() == Info`); **gerçek `.exe`'nin ürettiği log dosyası incelenemedi** — updater pubkey engeli `tauri build --release`'i bloklamaya devam ediyor (F7).

**Kalan kapsam sınırı:** regex yalnız e-posta ve E.164 telefon yakalıyor. Serbest metin mesaj gövdesi veya tam ad taşıyan bir `Debug` değeri maskelenmez — görev kapsamı bu ikisiydi, genişletme F6 birleştirmesinde değerlendirilmeli.
