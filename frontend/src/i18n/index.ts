// i18n ÇEKİRDEĞİ (Faz 14 / İz D — docs/PHASE-INTL.md §1.1–§1.3).
//
// Bu dosya, uygulamanın TEK i18n giriş noktasıdır: sözlük yükleme, aktif dilin
// çözümü/kalıcılığı ve Intl (para/tarih) etiketi üretimi burada toplanır. Yeni bir
// dil veya namespace eklemek dosya YARATMAKTAN ibarettir — burada kod değişmez
// (bkz. aşağıdaki `import.meta.glob` kararı).

import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

/** Desteklenen diller — `users.locale` beyaz listesiyle (config/syncra.php) BİREBİR aynı küme. */
export const SUPPORTED_LOCALES = ['tr', 'en', 'de', 'fr'] as const
export type Locale = (typeof SUPPORTED_LOCALES)[number]

/** Varsayılan VE fallback dil (§1.1). Türkçe eksikse basılacak bir şey kalmaz — bu bilinçli. */
export const DEFAULT_LOCALE: Locale = 'tr'

/** Pre-auth (giriş ekranı) dil seçimi burada yaşar; oturum açıldığında `users.locale` otoritedir. */
export const LOCALE_STORAGE_KEY = 'syncra-locale'

/**
 * KISA DİL KODU → BCP-47 ETİKETİ (Intl.NumberFormat / Intl.DateTimeFormat için).
 *
 * KARAR: `users.locale` ve i18next dil kodu KISA formdadır (`tr`/`en`/`de`/`fr`);
 * bölgesel varyant yalnızca BİÇİMLENDİRME için türetilir. Sebep: sözlük dosyalarını
 * bölgeye göre çoğaltmak (en-GB/en-US) çeviri maliyetini ikiye katlar, biçimlendirme
 * farkı ise tek bir eşlemeyle çözülür. `users.locale` char(5) seçilmesi ileride
 * gerçekten bölgesel bir sözlük gerekirse ('pt-BR' gibi) şemanın yetmesi içindir.
 *
 * `en` → `en-GB`, `en-US` DEĞİL: sayı gruplaması ikisinde de aynıdır (`1,234.56`),
 * ama tarih sırası farklıdır — uygulama Türkiye/Avrupa merkezli olduğu için
 * gün-ay-yıl beklentisi korunur (`en-US` ay-gün-yıl basardı ve mevcut TR
 * alışkanlığıyla çelişirdi).
 */
const INTL_TAGS: Record<Locale, string> = {
  tr: 'tr-TR',
  en: 'en-GB',
  de: 'de-DE',
  fr: 'fr-FR',
}

/**
 * SÖZLÜK YÜKLEME — `tr` eager, diğerleri lazy (§1.1 "başlangıç bundle'ı yalnız `tr` taşır").
 *
 * `import.meta.glob` KARARI (elle yazılmış import listesi DEĞİL): Faz 14 boyunca 18 feature
 * için ~1.100 dize, yani onlarca yeni namespace dosyası eklenecek. Elle tutulan bir import
 * listesi her yeni dosyada bu dosyanın da düzenlenmesini gerektirir; paralel çalışan
 * şeritler için bu tek bir çakışma noktası demektir. Glob ile yeni bir
 * `locales/<dil>/<ns>.json` dosyası KENDİLİĞİNDEN devreye girer.
 *
 * `eager: true` yalnız `tr` için: Vite bunu ana bundle'a katar (ilk boyada Suspense/flash
 * yok). `en/de/fr` glob'u eager DEĞİL → Vite her dosya için ayrı bir chunk üretir ve yalnızca
 * dil değiştirildiğinde indirilir.
 */
const eagerDefaultBundles = import.meta.glob<Record<string, unknown>>('./locales/tr/*.json', {
  eager: true,
  import: 'default',
})

const lazyBundles = import.meta.glob<Record<string, unknown>>('./locales/{en,de,fr}/*.json', {
  import: 'default',
})

/** `./locales/de/deals.json` → `deals` */
function namespaceOf(path: string): string {
  return path.slice(path.lastIndexOf('/') + 1).replace(/\.json$/, '')
}

function defaultBundles(): Record<string, Record<string, unknown>> {
  const prefix = `./locales/${DEFAULT_LOCALE}/`
  const out: Record<string, Record<string, unknown>> = {}
  for (const [path, resource] of Object.entries(eagerDefaultBundles)) {
    if (path.startsWith(prefix)) out[namespaceOf(path)] = resource
  }
  return out
}

/** Hangi dillerin sözlüğü belleğe alındı — aynı dili iki kez indirmemek için. */
const loadedLocales = new Set<Locale>([DEFAULT_LOCALE])

async function ensureBundlesLoaded(locale: Locale): Promise<void> {
  if (loadedLocales.has(locale)) return

  const prefix = `./locales/${locale}/`
  const entries = Object.entries(lazyBundles).filter(([path]) => path.startsWith(prefix))

  await Promise.all(
    entries.map(async ([path, load]) => {
      const resource = await load()
      // `deep`/`overwrite` açık: aynı namespace'e sonradan eklenen anahtarlar mevcut ağacı
      // EZMEDEN birleşsin, tekrar yüklenirse tazesi kalsın.
      i18n.addResourceBundle(locale, namespaceOf(path), resource, true, true)
    })
  )

  loadedLocales.add(locale)
}

export function isSupportedLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

function readStoredLocale(): Locale | null {
  try {
    const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY)
    return isSupportedLocale(stored) ? stored : null
  } catch {
    // Gizli sekme / storage kısıtı: dil seçimi kalıcı olmaz, uygulama yine çalışır.
    return null
  }
}

/**
 * Açılış dili: localStorage (kullanıcının son seçimi) → tarayıcı dili → `tr`.
 *
 * `users.locale` BURADA okunmaz: `/api/me` henüz dönmemiştir. Oturum açılınca
 * `applyUserLocale()` sunucu tercihini uygular — otorite odur (§1.3).
 */
function resolveInitialLocale(): Locale {
  const stored = readStoredLocale()
  if (stored) return stored

  const browser = typeof navigator !== 'undefined' ? navigator.language.slice(0, 2).toLowerCase() : ''
  return isSupportedLocale(browser) ? browser : DEFAULT_LOCALE
}

const isDevOrTest = import.meta.env.DEV || import.meta.env.MODE === 'test'

/**
 * Açılış dili TEK KEZ çözülür ve hem `init({ lng })` hem de aşağıdaki açılış yüklemesi
 * AYNI değeri kullanır. İkisi ayrı ayrı `resolveInitialLocale()` çağırsaydı, arada
 * localStorage değişirse (çok sekmeli oturum) "dil X, sözlük Y" ayrışması doğardı.
 */
const initialLocale = resolveInitialLocale()

const initPromise = i18n.use(initReactI18next).init({
  lng: initialLocale,
  fallbackLng: DEFAULT_LOCALE,
  supportedLngs: [...SUPPORTED_LOCALES],
  resources: { [DEFAULT_LOCALE]: defaultBundles() },
  defaultNS: 'common',
  // React zaten XSS'e karşı kaçış yapıyor; i18next'in ikinci kez kaçış yapması
  // `&#39;` gibi çift-kaçışlı metinler üretirdi.
  interpolation: { escapeValue: false },
  // `useSuspense: false` KARARI: dil değişimi `setLocale()` içinde ÖNCE sözlüğü indirip
  // SONRA `changeLanguage()` çağırdığı için React'in askıya alacağı bir an yok. Suspense
  // açık olsaydı her dil değişiminde tüm ağaç bir fallback'e düşerdi (ekran titremesi) ve
  // her sayfa için ayrı bir Suspense sınırı bakımı gerekirdi.
  react: { useSuspense: false },
  /*
   * EKSİK ANAHTAR DAVRANIŞI (§1.7 — "sessizce bozulan" sınıfı).
   *
   * `saveMissingTo` VARSAYILANI (`fallback`) bilinçli olarak korunur: handler yalnızca anahtar
   * FALLBACK dilde (tr) de bulunamadığında tetiklenir. Yani `en/de/fr`de henüz çevrilmemiş bir
   * anahtar sessizce Türkçeye düşer ve uygulamayı KIRMAZ — bu, ~1.100 dizenin kademeli
   * çevrileceği bir fazda zorunlu; aksi halde her yarım çeviri geliştirmeyi durdururdu. Diller
   * arası EKSİKLİK ayrı bir anahtar-parite denetleyicisinin işidir (§1.7).
   *
   * Buradaki throw, gerçekten TANIMSIZ anahtarı (hiçbir dilde yok — yazım hatası, silinmiş
   * anahtar) yakalar. Üretimde sessizdir: kullanıcı ham anahtar görür ama uygulama çökmez.
   */
  saveMissing: isDevOrTest,
  missingKeyHandler: (_lngs, ns, key) => {
    if (!isDevOrTest) return
    throw new Error(
      `[i18n] Tanımsız çeviri anahtarı: "${ns}:${key}" — fallback dilde (${DEFAULT_LOCALE}) de yok.`
    )
  },
})

/**
 * AÇILIŞ HAZIRLIK SÖZÜ — "İngilizce seçtim, yeniledim, Türkçe gördüm" hatasının kilidi.
 *
 * HATA NEYDİ: `init({ lng })` seçili dili (`en`) kurardı ama `resources` YALNIZCA eager
 * `tr` paketini taşırdı; `en/de/fr` sözlükleri sadece `setLocale()` içindeki
 * `ensureBundlesLoaded()` ile inerdi. Tam sayfa yenilemede `setLocale()` HİÇ çağrılmaz →
 * `i18n.language === 'en'` ama `en` sözlüğü bellekte YOK → i18next sessizce
 * `fallbackLng: 'tr'`e düşer: dil seçici "English" gösterirken arayüz Türkçe basardı.
 *
 * ÇÖZÜM: seçili dil varsayılan DEĞİLSE, o dilin paketleri İLK RENDER'DAN ÖNCE indirilir.
 * `main.tsx` `createRoot().render()` çağrısını bu söz çözülene kadar geciktirir — yani
 * "önce Türkçe boya, sonra İngilizceye dön" diye bir ara kare hiç oluşmaz (flash YOK),
 * çünkü o ana kadar HİÇBİR ŞEY boyanmamıştır.
 *
 * `tr` SEÇİLİYSE HİÇ BEKLEME YOK: `tr` eager olduğu için sözlük zaten ana bundle'da;
 * fonksiyon ağ işine hiç girmeden anında döner (bu yüzden `initPromise` bile beklenmez —
 * `initImmediate` bir makro-görev geciktirmesi ekler ve Türkçe kullanıcı bunu hak etmiyor).
 *
 * SÖZLÜK İNMEZSE ÇÖKME YOK: ağ hatası / eksik chunk durumunda konsola uyarı basılır ve
 * dil açıkça `tr`ye çevrilir. `changeLanguage` şart: yoksa `i18n.language` `en` kalır,
 * metin Türkçe basılır ve dil seçici yine yalan söylerdi — yani tam da düzelttiğimiz
 * hatanın sessiz hâline geri dönerdik. localStorage'daki seçim KORUNUR (silinmez):
 * kullanıcının niyeti geçerli, sonraki yenilemede indirme yeniden denenir.
 *
 * `react: { useSuspense: false }` KARARINA DOKUNULMADI: bekleme React ağacının DIŞINDA,
 * render'dan önce yapılıyor; askıya alınacak bir bileşen ağacı yok.
 */
async function bootstrapInitialLocale(): Promise<void> {
  if (initialLocale === DEFAULT_LOCALE) return

  try {
    // Sıra önemli: `addResourceBundle` i18next store'unu gerektirir, o da `init()`in
    // tamamlanmasıyla hazırdır.
    await initPromise
    await ensureBundlesLoaded(initialLocale)

    // AYNI DİLE `changeLanguage` — gereksiz görünür, DEĞİL: `init()` çalıştığı anda
    // `en/de/fr` sözlüğü henüz bellekte yoktu ve i18next `resolvedLanguage`i "çevirisi
    // olan İLK dil" kuralıyla `tr`ye sabitledi. Paket sonradan eklendiğinde bunu
    // KENDİLİĞİNDEN tazelemez. Tazelenmezse `t()` doğru çalışırken `getActiveLocale()`
    // `tr` döner → dil seçici "TR" gösterir ve `getIntlLocale()` para/tarihi `tr-TR`
    // biçimlendirir; yani metin İngilizce, biçimler Türkçe kalırdı.
    await i18n.changeLanguage(initialLocale)
  } catch (error) {
    console.warn(
      `[i18n] "${initialLocale}" sözlüğü yüklenemedi; arayüz varsayılan dile (${DEFAULT_LOCALE}) düşüyor.`,
      error
    )
    try {
      await i18n.changeLanguage(DEFAULT_LOCALE)
    } catch {
      // i18next'in kendisi de kurulamadıysa yapacak bir şey yok; uygulama yine de
      // `tr` eager paketiyle (ya da en kötü ham anahtarlarla) render edilir, ÇÖKMEZ.
    }
  }
}

/**
 * `main.tsx` bunu bekler. Dışa açık diğer imzalara (setLocale/applyUserLocale/...)
 * dokunulmadı; bu SADECE eklenen bir açılış kapısı.
 */
export const i18nReady: Promise<void> = bootstrapInitialLocale()

export function getActiveLocale(): Locale {
  const current = i18n.resolvedLanguage ?? i18n.language
  return isSupportedLocale(current) ? current : DEFAULT_LOCALE
}

/**
 * Aktif dilin (ya da açıkça verilen dilin) `Intl.*` etiketi.
 * `money.ts` / `datetime.ts` sabit `'tr-TR'` yerine bunu kullanır (§1.8).
 */
export function getIntlLocale(locale?: string): string {
  if (isSupportedLocale(locale)) return INTL_TAGS[locale]
  return INTL_TAGS[getActiveLocale()]
}

/**
 * Dili değiştirir: sözlüğü indirir → i18next'i çevirir → localStorage'a yazar →
 * `<html lang>`'i günceller.
 *
 * SUNUCUYA YAZMA BURADA DEĞİL: bu fonksiyon pre-auth (giriş ekranı) dahil her yerde
 * çalışmalı. `users.locale`'e yazma kararı oturum durumunu bilen `LanguageSwitcher`e
 * aittir — böylece i18n çekirdeği API/auth katmanına bağımlı olmaz.
 */
export async function setLocale(locale: Locale): Promise<void> {
  await ensureBundlesLoaded(locale)
  await i18n.changeLanguage(locale)

  try {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  } catch {
    // Kalıcılık bir kolaylık; oturum içi dil değişimi yine geçerli.
  }
}

/**
 * `/api/me` döndüğünde sunucudaki kişisel tercihi uygular (§1.3: `users.locale` = otorite).
 *
 * KULLANICI BU TARAYICIDA ELLE BİR DİL SEÇTİYSE EZMEZ: localStorage'da bir seçim varsa
 * kullanıcının açık niyeti kazanır — aksi halde giriş ekranında seçilen dil, giriş biter
 * bitmez geri alınmış gibi görünürdü. Seçim sunucuya da yazıldığı için ikisi normalde
 * zaten aynıdır; ayrışma yalnızca yazma başarısız olduğunda olur ve o durumda da
 * kullanıcının gördüğü dil değişmez.
 */
export async function applyUserLocale(locale: string | null | undefined): Promise<void> {
  if (!isSupportedLocale(locale)) return
  if (readStoredLocale() !== null) return
  await setLocale(locale)
}

i18n.on('languageChanged', (lng) => {
  if (typeof document !== 'undefined') document.documentElement.lang = lng
})

if (typeof document !== 'undefined') document.documentElement.lang = getActiveLocale()

export default i18n
