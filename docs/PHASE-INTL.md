# PHASE-INTL — Faz 14: Uluslararasılaştırma (i18n + Çoklu Para Birimi) & Attio Özellikleri

> **Statü: BAĞLAYICI FAZ SÖZLEŞMESİ (plan).** Bu doküman, Faz 13 (Güvenlik Denetimi) tamamlandıktan
> sonra çalıştırılacak Faz 14'ün tek doğruluk kaynağıdır. Faz üç iş kolundan (İz) oluşur:
> **İz D** çok dilli destek (i18n: tr/en/de/fr) + README İngilizce; **İz E** çoklu para birimi +
> güncel kur (TCMB); **İz F** Attio kabul edilen özelliklerin İNŞASI (C1–C4). Kapsam, kararlar,
> kabul kriterleri ve paralelleştirme planı burada sabitlenir; sapma gerekirse önce bu doküman
> güncellenir.
>
> İlgili sözleşmeler: `docs/PHASE-AUDIT.md` (Faz 13 — Attio ANALİZİ §5, tehdit modeli, kabul turu),
> `docs/ROADMAP.md` (§2, §2.1, §4, §5), `docs/QUOTE-FINANCIALS.md` (para/bcmath disiplini — İz E ona
> uyar), `docs/DATABASE.md`, `docs/AUTH-FLOWS.md`, `docs/PROGRESS.md`. Tarih: 2026-08-24.

---

## 0. Faz Yerleşimi, Numaralandırma ve Faz Sınırları

**Karar: Fazlar üçe bölündü (kullanıcı onayı, 2026-08-24).** Sıra:
`Faz 12 Chat → Faz 13 Güvenlik Denetimi + Kırmızı Takım + Kabul + Attio ANALİZİ → Faz 14 (bu doküman)
i18n + Çoklu Para Birimi + Attio ÖZELLİKLERİ → Faz 15 Teslim & Final`. Bu, Faz 13'ten "teslim"i
Faz 15'e kaydıran ikinci kaydırmadır; iki dokümandaki tüm referanslar buna göre güncellendi.

**Neden Faz 13'ten SONRA, Faz 15'ten ÖNCE:** İz D/E/F yeni ÖZELLİK inşasıdır ve temiz, denetlenmiş
bir taban ister — Faz 13 güvenlik turu ve ön bulgu kapatma önce biter. Teslim (README final, API
listesi, son kabul) en sonda kalır.

**Faz 13 ↔ Faz 14 iş bölümü — tek yerde, tekrarsız:**

| Konu | Faz 13 (PHASE-AUDIT) | Faz 14 (bu doküman) |
|---|---|---|
| **Attio** | ANALİZ + kabul/red kararı (PHASE-AUDIT §5) | Seçilen özelliklerin İNŞASI: C1 komut paleti, C2 kayıtlı görünümler, C3 ilişkili-kayıt paneli, C4 küçük otomasyon (§3) |
| **XXE / giden-çağrı sertleştirme** | Yalnız tehdit sınıfı olarak kataloglanır (kırmızı takım); somut XML ayrıştırıcı YOK | **Somut iş burada:** TCMB XML ayrıştırıcısı bu fazda doğduğu için XXE yüzeyi de burada doğar → XXE-güvenli ayrıştırma + giden HTTP sertleştirme (H7) + test (A5.8) **yalnız §2.5'te tarif edilir** |
| **Türkçe İ/ı casing (F6/H8)** | Ön bulgu olarak saptandı, **düzeltmesi Faz 13'te** (H8; i18n altyapısı gerektirmeyen küçük correctness düzeltmesi) | Faz 14 locale işiyle uyumlu; burada TEKRARLANMAZ (yalnız bağ notu §1.5) |
| **`money.ts` locale + para birimi** | — | Burada (§1.8 + §2.7); ayraç=locale (İz D), para birimi ekseni + dönüşüm (İz E), tek imza |
| **6-rol kabul turu** | Faz 13'te, Türkçe UI'da (PHASE-AUDIT §3) | Faz 14 tüm UI metnini değiştirir → **Faz 15'te kısa yeniden-kabul turu** (§6) |

**Attio ayrımı (net):** kabul-red kararı ve gerekçesi Faz 13'tedir (PHASE-AUDIT §5.1/§5.2); bu
fazda yalnızca §5.1'de KABUL edilen C1–C4 **inşa edilir**. Yeni bir Attio değerlendirmesi yapılmaz.

---

## 1. İZ D — Çok Dilli Destek (i18n) + README İngilizce

**Diller:** Türkçe (varsayılan + fallback), İngilizce, Almanca, Fransızca. Bugün sıfır i18n
altyapısı var (recon: `frontend/package.json`'da i18next/react-intl/lingui YOK, `backend/lang/`
YOK, hiçbir `t()` çağrısı yok) — yeşil alan entegrasyon.

### 1.1 Frontend kütüphanesi — **react-i18next (i18next)**
**Gerekçe:** olgun ve kapalı-devre dostu (tüm çeviri JSON'ları yerel bundle, CDN/dış çağrı yok);
CLDR çoğul kategorileri built-in (TR/EN one·other, DE one·other, FR 0/1→one · diğer→other/many —
Fransızca 0 ve 1'i tekil sayar, i18next bunu suffix'li anahtarla `key_one`/`key_other` çözer);
`i18next-parser` CLI ile anahtar çıkarımı; **`missingKeyHandler`/`saveMissing`** ile eksik anahtar
yakalama (test gereksinimini doğrudan karşılar — bkz. §1.7). Varsayılan+fallback `tr`. Non-default
locale JSON'ları `dynamic import` ile lazy-load → başlangıç bundle'ı yalnız `tr` taşır (bundle
kaygısı giderilir). **Alternatif değerlendirildi:** lingui (daha küçük runtime, macro/derleme
tabanlı) — ekosistem, missing-key mekanizması ve daha az yapılandırma nedeniyle react-i18next
seçildi.

### 1.2 Hacim (recon ölçümü, tahmin değil)
Türkçe karakter içeren `.ts/.tsx` dosyası: **274**; eşleşen satır: **3.915**; kullanıcı-yüzeyli
JSX literal (placeholder/label/title/metin düğümü): **~1.103**; 18 feature klasörü. Backend'de
Türkçe-karakterli satır 5.104 (çoğu yorum/docblock); yorum-dışı gerçek kod string'i **~698**;
custom `messages()` tanımlayan Form Request: **66**. Kaba çevrilecek dize hedefi: **~1.100 FE
kullanıcı-yüzeyli + ~700 BE mesaj/hata + enum etiketleri**.

### 1.3 Anahtar şeması, dosya yapısı, çoğul, dil seçici, kullanıcı tercihi
- **Namespace = feature adı** (+ `common`, `validation`, `enums`, `errors`). Örn.
  `deals:board.empty`, `common:actions.save`, `enums:lead.status.qualified`.
- Dosyalar: `frontend/src/i18n/locales/{tr,en,de,fr}/{ns}.json`; `frontend/src/i18n/index.ts` init.
- **Enum etiketleri (recon §1): tümü kodda, DB'de değil** — `frontend/.../leads/utils.ts`,
  `DealStatusBadge.tsx`, `ticketPriorityMeta.ts`, `ticketStatusMeta.ts`, `activityTypeMeta.ts`,
  `QuoteStatusBadge.tsx` içindeki `*_LABEL(S)` map'leri `enums` namespace'ine taşınır. **DB göçü
  gerekmez.** Makine değerleri (`new/won/lost`...) değişmez.
- **Dil seçici:** header (`AppLayout`) kullanıcı menüsü yanı **ve** login ekranı (pre-auth metin de
  Türkçe). Seçim `localStorage`'da (pre-auth anlık) + girişte `users.locale`'e yazılır.
- **Kullanıcı dil tercihi:** yeni **`users.locale` char(5) kolonu** (additive göç, default `'tr'`)
  = otorite. `Accept-Language` yalnız pre-login/anonim yanıtlar (login hatası, şifre sıfırlama)
  için fallback. Mevcut global `settings.general.language='tr'` (recon §6) = uygulama VARSAYILAN
  locale'i (anonim fallback), `users.locale` = kişisel override. **İz E'nin `users.preferred_currency`
  kararıyla aynı mekanizma** (ikisi de `users` tablosunda kişisel tercih, tek göçte eklenir).

### 1.4 Backend i18n
- `backend/lang/{tr,en,de,fr}/`: `validation.php` (Laravel'in kendi anahtarları — 66 Form
  Request'teki `messages()` metinleri `:min/:max` placeholder'larını KORUYARAK buraya taşınır,
  attribute adları `attributes` anahtarında), `errors.php` (`bootstrap/app.php`'deki sabit
  Türkçe hata cümleleri — "Bu işlem için yetkiniz yok." vb. — `__('errors.forbidden')` olur),
  `auth.php`/`passwords.php`, ve domain mesajları (status-machine `denyTransition()` cümleleri:
  `QuoteStatusMachine`/`TicketStatusMachine`; `UserDeactivated::$message`; `DealVersionConflictException`).
- **Locale çözümü:** yeni `SetLocale` middleware `auth:sanctum` sonrası `App::setLocale($user->locale ?? Accept-Language ?? config default)`.
  `bootstrap/app.php` exception handler `__()` kullanır ve locale request'ten gelir.
- **Bildirim metni — `notifications.data` DONMA sorunu:** Bugün `CrmNotification::toDatabase()`
  render edilmiş Türkçe `title`/`body`'yi DB'ye yazıyor (recon §4) → dil gönderim anında donuyor,
  kullanıcı sonradan dil değiştirse tarihsel bildirimler asla çevrilmez. **ÇÖZÜM: render edilmiş
  metin DEĞİL, çeviri ANAHTARI + parametre saklanır; OKUMA anında çözülür.**
  `data = { type, title_key, body_key, params, link, meta }`. `NotificationResource` serileştirmede
  isteği yapan kullanıcının locale'iyle `__($title_key, $params)` çözer. Broadcast payload'ı da
  key+params taşır; alıcı istemci kendi diliyle render eder (tutarlı). **Geriye dönük uyum:** eski
  satırlarda düz `title`/`body` varsa Resource onları fallback basar (`title_key` yoksa). **Gerekçe:**
  önemli olan OKUYANIN dili ve o değişebilir; donmuş string değişemez — SLA'nın "türetilmiş değer,
  bayrak değil" felsefesiyle (PROGRESS karar günlüğü) aynı. Reddedilen alternatif: gönderim anında
  alıcının `users.locale`'iyle render — alıcı sonradan dil değiştirince eski bildirimler yanlış
  dilde donar.

### 1.5 Veritabanındaki Türkçe metinler — kategori kararları
**Net sınır: kullanıcının/şirketin kendi GİRDİĞİ veri çevrilmez; UI kabuğu/etiket/mesaj çevrilir.**

| Kategori (recon) | Karar | Nasıl |
|---|---|---|
| Enum etiketleri (lead/deal/ticket/quote/activity) | **Çevrilir** | Kodda map → `enums` namespace; DB değeri değişmez |
| Validation/hata/bildirim/status-machine mesajları | **Çevrilir** | `backend/lang/*` + notifications key+param (§1.4) |
| Setting **açıklamaları** (`settings.description` — ayar anlatan yardımcı metin) | **Çevrilir** | UI `settings:keys.<key>.description` anahtarıyla render; DB `description` seed-metadata/fallback olur |
| `pipeline_stages.name`/`slug` ("Yeni Fırsat"...) | **Çevrilmez — kullanıcı verisi** | Ayarlar pipeline editöründen düzenlenebilir; şirket kendi çalışma dilinde adlandırır. Seed varsayılanı TR kalır. `slug` türetilmiş/makine, gösterilmez |
| `settings.company.*` (şirket adı/adres/vergi no) | **Çevrilmez — kullanıcı verisi** | Şirketin kendi bilgisi |
| `settings.quote.terms` (teklif şartları paragrafı) | **Çevrilmez — kullanıcı verisi** | Şirketin sözleşme metni; çok dilli şart = gelecekte manuel (kapsam dışı) |
| `email_templates` (name/subject/body_html) | **Çevrilmez — kullanıcı verisi** | Admin yazar; prod'da seeder YOK (recon §6, yalnız factory). Çok dilli şablon = admin dil başına ayrı şablon (gelecek) |
| `custom_fields.name`/`options` | **Çevrilmez — kullanıcı verisi** | Admin tanımlar |
| `tags.name`/`slug` | **Çevrilmez — kullanıcı verisi** | Kullanıcı oluşturur |
| `DemoDataSeeder` Türkçe demo veri | **Çevrilmez** | Prod'da atlanır (env guard); yalnız örnek veri, TR kalır |

**Türkçe İ/ı tuzağı (F6):** `DuplicateDetector::sameText`/`normalizeEmail` ve chat mention filtresi
Türkçe casing'i bozuyor — bu i18n'den bağımsız MEVCUT hatadır ve **düzeltmesi Faz 13'tedir (H8)**;
burada tekrarlanmaz. **Kapsam dışı (bilinçli):** `utf8mb4_unicode_ci` collation Türkçe sıralamayı
doğru yapmaz (i/ı, İ/I ayrımı yok) — collation değişimi büyük ve riskli iş; bilinen sınır olarak
belgelenir, bu fazda değişmez.

### 1.6 README kararı
**`README.md` → İngilizce (birincil); mevcut Türkçe içerik → `README.tr.md`.** İki dosyanın da
başına "English | Türkçe" bağlantısı. **Gerekçe:** README barındırıcının/teslimin gösterdiği
birincil giriş dokümanıdır; İngilizce teslim çıktısı için birincil dil İngilizce olur, Türkçe
korunur. **Sınır (kapsamı patlatmamak için):** README = teslim/dış-yüzey dokümanı → **EN + TR**.
`docs/` altındaki iç spec'ler (ROADMAP, PROGRESS, PHASE-AUDIT, PHASE-INTL, DATABASE, AUTH-FLOWS,
SLA-DESIGN, QUOTE-FINANCIALS, SETTINGS-SAFETY, DESIGN-SYSTEM) geliştirici çalışma dokümanıdır →
**TR kalır** (hepsini çevirmek kapsamı patlatır, teslim değeri yok). **Faz 14/15 iş bölümü:** İz D,
iki dilli README yapısını kurar + mevcut içeriği çevirir; Faz 15 "README final" API endpoint listesi
+ ER diyagramını **iki dile de** ekler (farklı içerik, çift-iş değil).

### 1.7 Test — eksik anahtar sessizce anahtar adı basar (tam "sessizce bozulan" sınıfı)
- **Anahtar-parite denetleyici:** tr/en/de/fr JSON anahtar kümeleri birebir eşit olmalı; herhangi
  birinde eksik anahtar → kırmızı. Backend `lang/{tr,en,de,fr}` için de parite. (Node script /
  CI adımı; frontend test altyapısı yoksa saf bir parite betiği yeterli.)
- i18next `missingKeyHandler` dev/test'te **throw** (fallback'e sessizce düşmesin).
- 4 dil nokta-kontrolü **Faz 15 yeniden-kabul turunda** (§6) — Faz 14'te dört dilin de temel
  akışlarda ham anahtar basmadığı geliştirici tarafından doğrulanır.

### 1.8 Biçimlendirme (İz E ile ortak `money.ts` sözleşmesi)
- **Para birimi dilden BAĞIMSIZDIR** (veri ekseni): TRY bir fırsat Almanca arayüzde de TRY/₺'dir.
  **Ayraç/gruplama dile bağlıdır** (`1.234,56` tr/de vs `1,234.56` en). `money.ts` bugün
  `'tr-TR'` sabiti (recon §5) → aktif UI locale ile parametrelenir. **Ek borç (recon §5):**
  `features/quotes/utils/money.ts` merkezi `money.ts`'i ihlal eden ikinci bir `Intl.NumberFormat('tr-TR')`
  kopyası taşıyor + 24 dosyada sabit `Intl.DateTimeFormat('tr-TR')` var — hepsi aktif locale'e bağlanır,
  quotes kopyası merkeze devredilir.
- İmza (İz E ile birleşik, §5): `formatMoney(amount, currency, { locale, displayCurrency?, rate? })`.

---

## 2. İZ E — Çoklu Para Birimi + Güncel Kur (TCMB)

**Kapsam (Karar B):** Kayıtlar kendi para biriminde saklanır (`deals.currency char(3)` zaten var);
kullanıcı tercih ettiği para biriminde **görür**; raporlar/dashboard seçilen para biriminde
toplanır; **teklif `sent` olunca kur donar**, PDF'te kur+tarih yazar. **Kapsam dışı:** ürün/fiyat
listesi başına çoklu fiyat, kalem bazlı farklı para birimi (kullanıcı seçmedi).

### 2.1 Kur kaynağı ve seçilen kur — TCMB (gerçek XML doğrulandı)
`https://www.tcmb.gov.tr/kurlar/today.xml` WebFetch ile incelendi (24.08.2026, Bülten 2026/157).
Yapı: kök `<Tarih_Date Tarih="dd.mm.yyyy" Date="mm/dd/yyyy" Bulten_No>`; her `<Currency CrossOrder Kod CurrencyCode>`
altında `Unit, Isim, CurrencyName, ForexBuying, ForexSelling, BanknoteBuying, BanknoteSelling,
CrossRateUSD, CrossRateOther`. **Kritik: `Unit` para birimine göre 1 veya 100'dür** — doğrulandı:
USD/EUR/GBP `Unit=1`, **JPY `Unit=100`**. Saklanan kur = **`ForexBuying / Unit`** = 1 birim için TRY.
- **Seçilen kur: `ForexBuying` (TCMB Döviz Alış).** **Gerekçe (araştırıldı):** Türkiye'de yabancı
  para cinsinden düzenlenen faturaların TL karşılığı ve VUK md. 280 değerlemesi **TCMB döviz alış
  kuru** ile yapılır (muhasebe standardı). `ForexSelling`/`Banknote*` saklanabilir ama kanonik
  dönüşüm `ForexBuying`. (`Efektif satış` yalnız özel sözleşme senaryolarında; bizim standart
  dönüşümümüz alış.)
- **Kaynak politikası (Karar A):** TCMB günlük XML zamanlanmış görevle çekilir (API anahtarı yok,
  ücretsiz). Erişilemezse son başarılı kur kullanılır + Ayarlar'dan manuel girilebilir. Kapalı
  devreye **tek, dar, denetlenebilir, tek-yönlü (yalnız alır)** istisna.
- **Hafta sonu/tatil:** TCMB bu günlerde ve ~15:30 öncesi yayın YAPMAZ. **Yayın yokluğu HATA
  DEĞİL, normal davranıştır** — son yayınlanan kur geçerlidir. Çekme komutu "bugün yeni veri yok"u
  başarı sayar, son kuru korur, `info` loglar (Faz 9 KDV sınıfı sessiz-hata değil; bkz. bayatlık §2.6).

### 2.2 Desteklenen para birimleri
**TRY (temel) + USD + EUR + GBP.** **Gerekçe:** tek şirket Türkiye merkezli → TRY temel; diller
tr/en/de/fr olduğuna göre EUR (DE/FR Euro bölgesi), GBP (İngiltere), USD (küresel ticaret + İngilizce
varsayılanı). Dördü de TCMB today.xml'de `Unit=1`. Set küçük ve denetlenebilir tutuldu; `Unit`
işleme genel yazılır (ileride JPY gibi `Unit=100` eklense bozulmaz).

### 2.3 Şema (yeni additive göçler; float YOK — QUOTE-FINANCIALS disiplini)
- **`exchange_rates`:** `currency char(3)`, `rate decimal(18,6)` (1 birim için TRY = `ForexBuying/Unit`),
  `unit smallint default 1`, `rate_date date`, `source enum('tcmb','manual')`, `entered_by` (nullable
  FK `users`), timestamps. **`unique(currency, rate_date)`** (aynı gün+para birimi tekil). Temel TRY
  örtük (rate 1.000000). Hassasiyet decimal(18,6): TCMB 4 hane yayınlar, 6 hane güvenli tampon.
- **Fırsatta donmuş temel tutar:** `deals` tablosuna `base_amount` (int-kuruş/decimal TRY),
  `base_rate decimal(18,6)`, `base_rate_date date` — **kapanışta (`won`/`lost` geçişinde) yazılır.**
  Böylece kapanmış fırsatın gerçekleşmiş değeri TRY olarak DONAR (rapor toplaması buradan; §2.4).
- **Teklifte donmuş kur:** `quotes` tablosuna `exchange_rate decimal(18,6)`, `exchange_rate_date date`
  — **`sent`'e geçişte yazılır** (nullable; taslakta boş). PDF bunları basar. Belge sonradan değişmez
  (Faz 9 `QUOTE_LOCKED` kilidiyle uyumlu).
- **Revizyon davranışı (`QTE-000007-R2`):** revizyon YENİ bir belgedir → **kendi `sent` anında TAZE
  kur alır**, ebeveynin donmuş kurunu DEVRALMAZ. **Gerekçe:** revizyon yeni bir ticari tekliftir,
  kuru kendi anlaşma tarihini yansıtmalı; ebeveyn kendi donmuş kurunu korur.
- **Kullanıcı görüntüleme tercihi:** **`users.preferred_currency char(3)` default `'TRY'`** —
  i18n'in `users.locale` kararıyla **aynı göçte, aynı mekanizma** (kişisel tercih, `users` tablosu).
- **Aritmetik:** dönüşüm `bcmath` (çarpma/bölme), toplama/karşılaştırma int-kuruş; float asla
  (R27/R26 disiplini). Görüntüleme dönüşümü frontend'de yalnız GÖSTERİM için; otoriter para matematiği
  (rapor, teklif, donmuş tutar) sunucuda.

### 2.4 Rapor/dashboard toplama — en ince nokta
Fırsatlar karışık para biriminde olabilir; `SUM(amount)` artık doğrudan toplanamaz.
- **Kapanmış/kazanılmış fırsat: KAPANIŞ ANI kuruyla (donmuş `deals.base_amount`, TRY), güncel kurla
  DEĞİL.** **Gerekçe (muhasebe vs bugünkü-değer çelişkisi, bilinçli seçim):** gerçekleşmiş gelir
  sabittir; Ocak'ta kazanılan bir fırsat o günkü kurla gerçekleşmiştir. Tarihsel geliri her gün
  güncel kurla yeniden değerlemek, geçen çeyreğin gelirini her gün değiştirir — "geçen ay neden
  değişti?" (Faz 9 KDV sınıfı sessiz hata). Gerçekleşmiş değer TRY'de DONAR.
- **Açık fırsat: GÜNCEL kur.** İleriye dönük borunun "bugünkü değeri" mantıklı olan.
- **Nerede (Faz 11 "ham SQL yok, tek sorgu, N+1 yok" kuralı korunur):** Kapanmış → `SUM(base_amount)`
  doğrudan (tek sorgu, dönüşüm yok, KARARLI). Açık → tek sorguda **`GROUP BY currency` + `SUM(amount)`**
  (para birimi başına kova; en fazla 4 kova), sonra her kovayı hedef görüntü para birimine PHP'de
  güncel kurla çevir (satır başına değil, sabit-boyut kova başına → N+1 yok, ham SQL yok).
- **Rapor çıktısı hangi kuru kullandığını + tarihini GÖSTERİR:** "Açık fırsatlar bugünkü kur
  (dd.mm.yyyy) ile TRY'ye çevrildi; kapanmış fırsatlar kapanış günü kuruyla donduruldu." Aksi halde
  rakamlar açıklanamaz.

### 2.5 Güvenlik — XXE + giden çağrı (XXE'nin TEK sahibi bu faz; H7)
TCMB XML döndürür → PHP `simplexml_load_string`/`DOMDocument` **XXE yüzeyidir**. Bu yüzey yalnız bu
fazda (TCMB ayrıştırıcısıyla) doğar; Faz 13 kırmızı takımı XXE'yi tehdit sınıfı olarak katalogladı,
**somut test ve sertleştirme burada**.
- **Güvenli ayrıştırma:** `simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET)`. **`LIBXML_NOENT`
  KULLANILMAZ** (o, varlık ÇÖZÜMLEMEYİ AÇAR — tehlikeli); `LIBXML_DTDLOAD` kapalı. libxml ≥2.9'da
  harici varlık zaten varsayılan kapalı; **PHP 8'de `libxml_disable_entity_loader()` kullanımdan
  kalktı/kaldırıldı** — ona güvenilmez, güvenlik `LIBXML_NONET` + `NOENT/DTDLOAD` bayraklarının
  KAPALI olmasından gelir. Ayrıştırmadan önce boyut sınırı (aşırı büyük gövde reddedilir).
- **Giden HTTP:** **sabit URL sabiti** (kullanıcı girdisi DEĞİL — Karar A garanti eder; SSRF yüzeyi
  yok), Laravel `Http::timeout(10)->connectTimeout(5)`, **TLS sertifika doğrulaması AÇIK** (varsayılan),
  yanıt boyut sınırı, yeniden deneme (2 deneme + backoff), toplam başarısızlık = **son kuru kullan**
  (kırılmaz). Manuel giriş yolu: pozitif, makul aralıkta decimal doğrulaması.
- **Sertleştirme kalemi H7:** `app/Services/Exchange/*` (yeni) + `app/Console/Commands/FetchTcmbRates.php` (yeni).
- **Test (A5.8):** taklit XML'e harici varlık (`<!ENTITY ... SYSTEM "file:///...">`) gömülür →
  ayrıştırıcı çözmemeli, yerel dosya sızmamalı; ayrıca aşırı-büyük yanıt reddedilmeli.

### 2.6 Bayatlık görünürlüğü
Kur bayatladığında (TCMB'ye günlerce ulaşılamıyor) kullanıcı **görmeli** — sessiz eski-kur hesabı
Faz 9 KDV sınıfı hatadır.
- **Her zaman "Kur dd.mm.yyyy tarihli" etiketi** gösterilir: para birimi seçicide, rapor/dashboard
  dipnotunda, teklif PDF'inde.
- **Uyarı eşiği: > 4 takvim günü** eski kur → amber uyarı. (4 gün: normal hafta sonu + 1 tatil
  toleransı; Cuma kuru Pazartesi sabahına dek geçerli sayılır, bu normal.)

### 2.7 Mevcut kodla temas
- `frontend/src/lib/money.ts`: locale (İz D ayraç) + para birimi ekseni + görüntüleme dönüşümü tek
  imzada (§5, §1.8). quotes'taki kopya merkeze devredilir.
- `resources/views/pdf/quote.blade.php`: para simgesi + **kur/kur-tarihi satırı** (İz D statik etiket
  çevirisiyle **aynı şerit**, birleşik değişiklik). **dompdf font: DOĞRULANDI** — DejaVu Sans (subsetting
  açık) DE/FR aksanlarını (ä ö ü ß é è ê ë à â û ç œ …) ve ₺ € $ £'yi eksiksiz render+round-trip etti
  (render→pdfparser, Faz 9 yöntemi; MISSING GLYPHS: none). **Font değişikliği YOK.**
- Faz 9 teklif hesap motoru (`app/Services/Quotes/`): donmuş kur hesap motorunu BOZMAZ — motor kendi
  para biriminde çalışır; kur yalnız `sent`'te ayrı kolona yazılır ve raporda/görüntülemede TRY dönüşümü
  için kullanılır, kalem/KDV matematiğine girmez.
- Faz 11 rapor servisleri (`app/Services/Reports/`): §2.4'e göre refactor (group-by-currency + kova).
- **Zamanlanmış görev:** `exchange:fetch-tcmb` (mevcut `logs:prune`/`tasks:dispatch-reminders`/
  `tickets:scan-sla` deseninde), **16:00** (TCMB ~15:30 yayınından sonra), idempotent (`unique(currency,rate_date)`),
  `--dry-run` destekli.

**İz E test matrisi eki:** A5.8 (XXE, §2.5) · kur çekme idempotent + hafta sonu "yeni yok"=başarı ·
`Unit=100` bölme doğruluğu · kapanmış fırsat `base_amount` donması ve rapor kararlılığı (aynı geçmiş
= aynı rakam ertesi gün) · teklif `sent` kur donması + revizyon taze kur · bayatlık > 4 gün uyarısı ·
bcmath/float kontrolü · `preferred_currency` görüntüleme dönüşümü izolasyonu (kayıt değeri değişmez).

---

## 3. İZ F — Attio Kabul Edilen Özelliklerin İnşası

**Analiz Faz 13'tedir (PHASE-AUDIT §5.1/§5.2).** Bu fazda yalnızca §5.1'de KABUL edilen dört aday
İNŞA edilir. Yeni değerlendirme yapılmaz; her adayın "nedir / nereye oturur / maliyet / neden" gerekçesi
PHASE-AUDIT §5.1'dedir.

| # | Özellik | İnşa kapsamı (özet) | Maliyet |
|---|---|---|---|
| C1 | Global komut paleti + çapraz arama (Ctrl-K) | Yeni `GET /api/search?q=` (deal/lead/contact/company/quote/ticket/user, yetki filtreli) + FE komut paleti bileşeni | M |
| C2 | Kayıtlı Görünümler (Saved Views) | Yeni `saved_views` tablosu (`user_id, module, name, query_json, is_shared`) + BE CRUD + FE liste ekranı entegrasyonu (mevcut `?filter[]&sort&q` sözleşmesinin üstüne) | S/M |
| C3 | Çift-yönlü ilişkili-kayıt paneli | Kayıt detayında "ilişkili kayıtlar" bölümü; mevcut contact↔company, deal↔contact ilişkileri + `TimelineBuilder` üstüne (yeni tablo gerekmez, gerekiyorsa hafif BE ucu) | S/M |
| C4 | Küçük no-code otomasyon kuralı | Sabit trigger+action kataloğu (2–3 trigger, 2–3 action; keyfi kod/AI YOK); yeni `automation_rules` tablosu; Faz 10 event/observer altyapısının üstüne | L (küçük tutulur) |

**Dosya sahipliği:** İz F yeni yüzeyler açar (`search`/`saved_views`/`automation_rules`) ve mevcut
modül dosyalarına dokunmaz; `routes/api.php` eklemeleri tek elden. İz D/E ile çakışmaz (§5).

---

## 4. Kabul Kriterleri (Faz 14)

- [ ] **İz D:** 4 dilde (tr/en/de/fr) uygulama gezilebilir; dil seçici header + login'de; hiçbir ekranda
      ham anahtar görünmüyor; anahtar-parite denetleyici (frontend tr/en/de/fr + backend `lang/*`) yeşil;
      DE/FR çoğul kuralları doğru; kullanıcı-verisi (aşama adı, custom field, tag, şirket profili, teklif
      şartları) çevrilmedi (§1.5 sınırı). **KISMEN:** dil seçici + parite denetleyici (`npm run i18n:check`,
      27 ns / ~2089 anahtar, iki yön yeşil) + kullanıcı-verisi sınırı doğrulandı; ancak dört dilin de
      gerçek ekranlarda ham anahtar/kırık düzen basmadığının **elle, göz ile** 4-dil × 6-rol nokta-kontrolü
      yapılmadı — bu kontrol bilinçli olarak Faz 15 kısa yeniden-kabul turuna ertelendi (§1.7, §6, §8).
      Ayrıca `pages/Showcase.tsx` çevrilmedi (§8 bilinen sınır (b)).
- [x] **İz D:** Bildirim metinleri `notifications.data` içinde anahtar+parametre olarak saklanıyor, okuma
      anında alıcının diliyle çözülüyor (donmuş metin yok); eski satırlar için düz-metin fallback var.
      12/12 bildirim tipi geçirildi; kuyrukta çalışan `UserDeactivated` alıcının kendi `locale`'iyle çözülüyor.
- [x] **İz D:** README.md İngilizce, README.tr.md Türkçe; `docs/` altı Türkçe kaldı (sınır §1.6).
- [x] **İz E:** TCMB kur çekme komutu çalışıyor (ForexBuying/Unit), manuel yedek Ayarlar'da; hafta sonu/
      tatil "yayın yok" hata değil (son kur geçerli); kur bayatladığında (>4 gün) her yüzeyde (switcher,
      rapor, PDF) tarih etiketi + uyarı görünüyor.
- [x] **İz E:** Kayıtlar kendi para biriminde; kullanıcı `preferred_currency` ile görüyor; kapanmış fırsat
      kapanış-anı TRY tutarıyla (donmuş `base_amount`), açık fırsat güncel kurla toplanıyor; raporda
      kullanılan kur + tarihi gösteriliyor; teklif `sent`'te kur donuyor, PDF'te kur+tarih var.
- [x] **İz E:** XXE testi (A5.8) yeşil; kur ayrıştırma `LIBXML_NONET` + harici varlık kapalı; giden çağrı
      sabit URL + zaman aşımı + TLS doğrulama + boyut sınırı (H7). Para hesabı float kullanmıyor (bcmath).
- [x] **İz F:** C1–C4 inşa edildi; her yeni uç Policy/izin kontrollü; global aramada yetkisiz kayıt
      sızmıyor; kayıtlı görünüm sahiplik/paylaşım kuralı çalışıyor.
- [x] Tüm mevcut test suiti (Faz 13 sonrası) hâlâ yeşil; yeni testlerle birlikte sayı arttı — **1305 test /
      9635 assertion (2026-08-25)**, Faz 13 kapanışında 1098 test / 8843 assertion'dı.

---

## 5. Paralelleştirme Planı (docs/ENGINEERING-RULES.md §3–4 uyumlu)

Genel kural: Teknik lider dosya değiştirmez — böler, sözleşmeyi yazar, inceler; commit'i
kullanıcı atar. Aynı dalgada iki şeride aynı dosya verilmez; düzeltmeler aynı şeride iletilir; hiçbir
şerit Git çalıştırmaz. **İz D, İz E, İz F büyük ölçüde bağımsız** (farklı dosya sahipliği), paralel
başlayabilir.

| Dalga | Kritik parça (teknik lider) | 2. kritik (deneyimli şerit) | Hacimli (standart şerit, çakışmayan dosya sahipliği) | Contract-first |
|---|---|---|---|---|
| **W1 — i18n (İz D)** | i18n mimarisi: kütüphane kurulumu, namespace şeması, anahtar sözleşmesi, notifications key+param refactor sözleşmesi | Backend `lang/*` + `SetLocale` middleware + notifications okuma-anı çözümü (BE) | S1: FE sözlük çıkarımı (~1.100 dize, 18 feature) tr+en. S2: enum/validation/error anahtarları + de/fr çevirileri. S3: `money.ts`/tarih locale parametreleme + dil seçici + README EN/tr split | `formatMoney` imzası + notifications data sözleşmesi önce (§1.4/§1.8) |
| **W2 — Para birimi (İz E)** | Kur veri modeli + TCMB kaynak/rate seçimi + rapor toplama stratejisi (donmuş base_amount) + XXE/giden-çağrı sertleştirme kararı | Rapor/dashboard toplama refactor (`app/Services/Reports/*`) — karışık para birimi (BE) | S1: `exchange_rates` göç + `FetchTcmbRates` komut + Ayarlar manuel giriş. S2: teklif donmuş kur (`sent`) + PDF kur/tarih satırı. S3: FE para birimi seçici + görüntüleme dönüşümü (money.ts sözleşmesine bağlı) | §2.3 şema + §2.5 XXE kararı önce |
| **W3 — Attio özellikleri (İz F)** | C1 arama sözleşmesi + C4 otomasyon kuralı kapsam kararı (küçük tut) | — | S1: C1 `GET /api/search` + FE komut paleti. S2: C2 `saved_views` tablosu + BE/FE. S3: C3 ilişkili-kayıt paneli (FE + varsa hafif BE ucu) | Yeni yüzeyler mevcut modül dosyalarına dokunmaz; route eklemeleri tek elden |

**İki kesişim noktası, sözleşme-önce çözülür (İz D ∩ İz E):** (1) `frontend/src/lib/money.ts` hem İz D
(ayraç = locale) hem İz E (para birimi ekseni + görüntüleme dönüşümü) tarafından değişir — imzası
(`formatMoney(amount, currency, { locale, displayCurrency?, rate? })`) dispatch'ten önce sabitlenir,
tek şerit yazar; (2) `resources/views/pdf/quote.blade.php` hem İz D (statik etiket lang'e) hem İz E
(kur/tarih satırı) tarafından değişir — tek şerit, birleşik değişiklik.

---

## 6. Faz 15 (Teslim) ile İlişki — Kısa Yeniden-Kabul Turu

Faz 13'teki 6-rol kabul turu (PHASE-AUDIT §3) **Türkçe UI** üzerinde yapıldı. Faz 14 tüm kullanıcı-yüzeyli
metni değiştirir (i18n), para birimi görüntüsünü ekler ve C1–C4 özelliklerini getirir. Bu yüzden
**Faz 15 kapsamına kısa bir yeniden-kabul turu eklenir** — Faz 13'ün tam turunun tekrarı DEĞİL:
- İş mantığı/yetki değişmediği için tam İz A/B tekrarı gereksiz; bu bir **metin + özellik smoke turu**.
- (a) 6 rolün ana akışları çevrilmiş UI'da hâlâ geçiyor mu (nokta-kontrol); (b) 4 locale (tr/en/de/fr)
  temel akışlarda ham anahtar/kırık düzen basmıyor; (c) para birimi seçimi + rapor kur etiketi + PDF
  kur satırı doğru; (d) C1–C4 özellikleri yetki sınırıyla çalışıyor.
- Bu tur ROADMAP §2'de Faz 15 "son kabul turu" kaleminin içine yazılır (ayrı bir faz açmaz).

---

## 7. Taşınan Ön Bulgular ve Bağlar

- **`features/quotes/utils/money.ts` kopyası (recon §5):** merkezi `money.ts`'i ihlal eden ikinci bir
  `Intl.NumberFormat('tr-TR')` — İz D locale parametrelemesinde merkeze devredilir (§1.8).
- **F6 / Türkçe İ/ı casing:** ön bulgu Faz 13'te saptandı, **düzeltmesi Faz 13'te (H8)**. Faz 14 locale
  işiyle felsefi olarak akraba ama burada tekrarlanmaz — yalnız bağ notu (§1.5).
- **`utf8mb4_unicode_ci` Türkçe sıralaması:** bilinen sınır, bu fazda da değişmez (§1.5).

---

## 8. Faz 14 Kapanışı — Sonuç, Ertelenen Kararlar ve Bilinen Sınırlar

**Ölçülen sonuç (2026-08-25):**
- Kanonik `syncra_crm_test` üzerinde, paralel şerit yokken: **1305 test / 9635 assertion, 0 hata**
  (Faz 13 kapanış tabanı 1098 test / 8843 assertion'dı).
- Frontend: `npx tsc -p tsconfig.app.json --noEmit` → **0 hata** (kök `tsconfig.json` solution-style
  olduğu için düz `npx tsc --noEmit` yanıltıcı biçimde sessizce 0 döner — hiçbir dosyayı kontrol etmez;
  bu tuzak `docs/PROGRESS.md` Ortam Durumu tablosuna ayrıca not düşüldü).
- `npm run i18n:check` → **27 namespace / ~2089 anahtar**, iki yönde de yeşil: (1) dil↔dil anahtar
  paritesi (tr/en/de/fr), (2) kod→sözlük statik tarama (`useTranslation` bağlamaları + `t()`/`<Trans>`
  kullanımları). 185 dosya tarandı; 70 dinamik anahtar (`t(\`x.${y}\`)` biçiminde, derleme zamanında
  çözülemeyen) statik olarak çözülemedi — **sessizce yutulmadı, raporlandı** ve kapsam dışı bırakıldı
  (bilinen sınır (e), aşağıda).
- `npm run test:money-currency` → **16/16** yeşil (`currencyDisplay: 'narrowSymbol'` regresyonu).
- Doğrulama komutları: `php artisan test` (backend, kanonik DB), `npx tsc -p tsconfig.app.json --noEmit`
  (frontend tip kontrolü — DOĞRU komut budur, bkz. yukarı), `npm run i18n:check` (i18n parite),
  `npm run test:money-currency` (para/para birimi biçimlendirme regresyonu).

**Faz 15'e ERTELENEN KARAR — PDF tarih biçimi:**
PDF'te tarihler dört dilde de sabit `d.m.Y` (`24.08.2026`) basılıyor; oysa uygulama arayüzü locale'e
göre biçimlendiriyor (`en-GB` → `24/08/2026`, `de-DE` → `24.08.2026`, `fr-FR` → `24/08/2026`).
**Gerekçe (bilinçli, çözülmedi):** müşteriye giden bir belgede `en-GB` slash biçimi (`24/08/2026`)
Amerikalı bir okuyucu tarafından `m/d/y` sanılıp `08.24.2026` gibi yanlış okunabilir; noktalı `d.m.Y`
biçimi bu belirsizliği taşımıyor (ay 13'ten büyük olamayacağı için tek okunuşu var). Ama bu, PDF ile UI
arasında görünür bir fark yaratıyor. Kararı — PDF'i UI'nın locale davranışına uydurmak mı, yoksa
belirsizlik-taşımaz `d.m.Y`'de sabit kalmak mı — **Faz 15 kabul turunda kullanıcı verecek**; değişikliği
uygulamak tek satırlık bir formatter parametresi.

**Bilinen sınırlar (bilinçli, hata değil):**
- **(a) `Company` tipinde `currency` alanı yok** (`annual_revenue` para birimsiz) → firma ekranlarında
  para birimi dönüşümü uygulanmadı. Kapsam kararı zaten Deal/Quote seviyesindeydi (§2.1 "Kayıtlar kendi
  para biriminde saklanır (`deals.currency` zaten var)"); Company'nin kendi bir para alanı hiç olmadı.
- **(b) `pages/Showcase.tsx` çevrilmedi** — kendi başlığında "kalıcı ürün sayfası değildir" diyen dev
  tasarım galerisi (Faz 1'den kalma); i18n kapsamı kullanıcı-yüzeyli üretim ekranlarıyla sınırlı tutuldu.
- **(c) Lead'in ters yönü (contact/company/deal → lead) şemada YOK** (`lead_id` kolonu yok) — bu Faz
  14'ün kapsamı değil (İz F ilişkili-kayıt paneli C3 mevcut ilişkiler üzerine kurulu), uydurulmadı.
- **(d) `QuoteFormPage`'de para birimi SEÇİCİSİ yok** — teklif para birimi seçimi bu fazın kapsamında
  değildi (§2 Kapsam: "kalem bazlı farklı para birimi kullanıcı seçmedi"); iskonto alanı ISO kodu
  basıyor çünkü `money.ts` yalnız-simge erişimcisi sunmuyor. Küçük, izole bir eksik.
- **(e) `i18n:check` 70 dinamik anahtarı** (`t(\`x.${y}\`)` biçiminde, derleme zamanında string
  birleştirmeyle üretilen) statik çözemiyor — sessizce yutmuyor, konsola raporluyor ve kapsam dışı
  bırakıyor. Bu anahtarlar elle gözden geçirilmedi; Faz 15 nokta-kontrolünde bu ekranlara özellikle
  bakılmalı.

**Faz 15'e taşınan iş:**
- §6'daki kısa yeniden-kabul turu: 4 dil × 6 rol nokta-kontrolü (özellikle dinamik anahtar taşıyan
  ekranlar, bkz. bilinen sınır (e)), para birimi seçimi/kur etiketi/PDF kur satırı doğrulaması, C1–C4
  yetki sınırı testi.
- PDF tarih biçimi kararı (yukarıda) — kullanıcı onayı bekliyor.
- README final: API endpoint listesi + ER diyagramı iki dile de eklenecek (Faz 14 yalnız README yapısını
  ve mevcut içeriği çevirdi, Faz 15 içerik tamamlar — §1.6).
