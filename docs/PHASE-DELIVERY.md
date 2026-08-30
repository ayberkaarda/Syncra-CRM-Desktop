# PHASE-DELIVERY — Faz 15: Teslim & Final Kabul

> **Statü: KAPANIŞ RAPORU (sonuç).** Önceki iki faz dokümanının (`docs/PHASE-AUDIT.md`,
> `docs/PHASE-INTL.md`) aksine bu doküman bir PLAN değil — Faz 15 bittikten SONRA, gerçekleşen
> sonucu kayıt altına almak için yazıldı. Faz 15 son fazdır: `docs/ROADMAP.md` §2'deki 0–14
> fazların hiçbirini tekrar etmez (güvenlik header/upload/IDOR/mass-assignment Faz 13'te
> kapatıldı — `docs/PHASE-AUDIT.md`; i18n/çoklu para birimi/Attio özellikleri Faz 14'te inşa
> edildi — `docs/PHASE-INTL.md`). Faz 15 dört başlıkta çalıştı: **teslim dokümanı**, **işlevsel
> test kapsamı tamamlama**, **4 dilli gezinti denetimi**, **kısa yeniden-kabul turu + Bölüm 6 son
> tur**.
>
> İlgili sözleşmeler: `docs/ROADMAP.md` (§2 Faz 15 satırı, §6 Bölüm 6 kabul kriterleri),
> `docs/PROGRESS.md` (Faz Durum Tablosu, Karar Günlüğü 2026-08-25), `docs/PHASE-AUDIT.md`
> (§3.1 6-rol kabul turu — bu fazın kısa yeniden-kabulünün referans temeli), `docs/PHASE-INTL.md`
> (§6 kısa yeniden-kabul turu tanımı, §8 Faz 14 kapanışı — Faz 15'e devredilen kararlar/sınırlar).
> Tarih: 2026-08-25.

---

## 0. Faz Yerleşimi ve Kapsam

**Sıra:** Faz 14 (i18n + çoklu para birimi + Attio özellikleri) → **Faz 15 (bu doküman)**.
Faz 14 tüm kullanıcı-yüzeyli metni değiştirdiği ve yeni yüzeyler (C1–C4) getirdiği için Faz 15
teslimden önce kısa bir yeniden-kabul turu yapmak zorundaydı — tam bir İz B tekrarı değil, metin
+ özellik smoke turu (`docs/PHASE-INTL.md` §6).

**Kapsam dışı (bilinçli, başka fazlarda kapatıldı, burada tekrarlanmaz):** güvenlik header'ları,
upload sertleştirme, IDOR/mass-assignment turu (Faz 13); i18n altyapısı, çoklu para birimi/TCMB,
Attio C1–C4 inşası (Faz 14). Bu doküman yalnızca Faz 15'in kendi çıktısını tarif eder.

**Ölçülen kapanış sonucu (2026-08-25):**

| Ölçüm | Sonuç |
|---|---|
| Backend test suiti (kanonik `syncra_crm_test`, tek başına) | **1316 test / 9695 assertion, 0 hata** |
| Frontend tip kontrolü | `npx tsc -p tsconfig.app.json --noEmit` → 0 hata |
| i18n parite | `npm run i18n:check` iki yönde yeşil (27 namespace, ~2109 anahtar) |
| i18n bootstrap denetimi | `npm run i18n:check-bootstrap` yeşil (yeni — bkz. §2 hata #1/#2) |
| Para/para birimi biçimlendirme | `npm run test:money-currency` 16/16 |

Faz 14 kapanışında (`PHASE-INTL.md` §8) taban **1305 test / 9635 assertion**'dı; Faz 15 bu tabana
işlevsel kapsama testleri (§1) ve 4 dilli denetimde bulunan hataların regresyon testlerini (§2)
ekledi.

---

## 1. Teslim Dokümanı

**README:** `README.md` (İngilizce, birincil) + `README.tr.md` (Türkçe), her ikisi **885 satır**,
başlık hiyerarşileri birebir paralel (her iki dosyanın başında "English | Türkçe" çapraz bağlantısı
— Faz 14'te kurulmuştu, Faz 15 içeriği tamamladı). İçerik:

- Kurulum: XAMPP/MariaDB/Redis (WSL2)/Node.
- Çalıştırma komutları: `dev.bat`'in **5 süreç** başlattığı belgelendi — Reverb, API (`artisan
  serve`), kuyruk (`queue:work`), **zamanlayıcı** (`schedule:work`), frontend (`npm run dev`).
  Eski README taslağı (Faz 0) zamanlayıcıyı hiç saymıyordu (`logs:prune`/
  `tasks:dispatch-reminders`/`tickets:scan-sla`/`exchange:fetch-tcmb` bu süreç olmadan hiç
  çalışmaz) — bu bir teslim-dokümanı doğruluk hatasıydı, Faz 15'te düzeltildi.
- Doğrulama komutları (test/tsc/i18n:check/test:money-currency).
- **167 uçluk API listesi**, izin sütunlu; `php artisan route:list --json` çıktısıyla satır
  satır karşılaştırılıp birebir eşleştiği doğrulandı (uydurma/eksik uç yok).
- **5 gruplu mermaid ER diyagramı** (çekirdek CRM / log-audit / chat / ayarlar-otomasyon /
  finans-para birimi gruplaması — tek dev diyagram yerine okunabilir 5 parça).
- Varsayılan hesaplar (Super Admin + demo kullanıcılar, şifreler) ve lisans.

**Ekran görüntüleri:** `docs/screenshots/{tr,en}/` altında **11'er PNG** (doğrulandı: her iki
klasörde tam 11 dosya). Türkçe README Türkçe arayüz, İngilizce README İngilizce arayüz
görüntülerini kullanıyor — kullanıcının açık talebiydi (bir README'nin ekran görüntüsü diğer
dildeki arayüzü göstermesin). Görüntüler tek bir "ekran görüntüleri" duvarında toplanmadı; ilgili
anlatım bölümlerinin içine dağıtıldı (kurulum adımına yakın kurulum ekranı, Kanban anlatımına
yakın Kanban ekranı vb.); koyu tema görüntüleri `<details>` içinde katlanabilir tutuldu (README'yi
uzatmamak için).

---

## 2. İşlevsel Test Kapsamı Tamamlama

ROADMAP §2 Faz 15 satırının adıyla andığı 5 işlev (auth, yetki, deal CRUD, log kaydı, chat
mesajı) tek tek denetlendi. auth/RBAC/chat zaten önceki fazlarda kapsanıyordu (kanıtı:
`docs/PHASE-AUDIT.md` §2 A1/A2 test matrisi + §6 kabul kriterleri; chat 5 dosyalık test paketi —
`docs/ROADMAP.md` §2.1 Faz 12 sonucu). Log kaydı Faz 5'te (162 test) ve deal CRUD'un yazma tarafı
Faz 7'de (357 test, optimistic lock/409 dahil) zaten kilitliydi.

Denetimde **iki gerçek boşluk** bulundu ve kapatıldı — ikisi de "yazma zaten test edilmiş ama
okuma/farklı-olay-şekli hiç doğrulanmamış" sınıfından:

| # | Boşluk | Neden gerçek bir eksikti | Kapatan dosya |
|---|---|---|---|
| 1 | `GET /api/deals/{id}` yalnız yetkisiz-403 senaryosuyla test ediliyordu; **yetkili kullanıcının gerçek `show` yanıtı** (alan seti, ilişki çözümü — owner/pipeline stage/company/contact) hiç doğrulanmıyordu | Yazma tarafı (create/update/move) yoğun test edilmişti ama tek bir kaydın doğru şekilde okunduğunu doğrulayan test yoktu — bir Resource alanı sessizce kırılsa hiçbir test kırmızı olmazdı | `backend/tests/Feature/DealApiTest.php` |
| 2 | Audit trail'in **`created` olayının kendi diff şekli** hiç doğrulanmamıştı — `updated`/`deleted`/`restored` kapsanmıştı ama `created` yalnız `attributes` taşır, `old` taşımaz; bu asimetri testsizdi | `spatie/laravel-activitylog`'un `created` olayında `properties.old` alanının HİÇ olmaması (boş dizi değil, anahtarın kendisi yok) kolayca fark edilmeyecek bir regresyon sınıfı | `backend/tests/Feature/AuditTrailTest.php` |

---

## 3. 4 Dilli Gezinti Denetimi (kullanıcı isteği)

**Yöntem:** 236 sayfa/modal ziyareti, dört dil (tr/en/de/fr), her ekranda ham i18n anahtarı
(`namespace:key.path` biçiminde çevrilmemiş metin) sızıntısı arandı. **Sonuç: sıfır ham anahtar
sızıntısı** — ama tur, i18n dışı ve i18n içi **sekiz gerçek hata** ortaya çıkardı. Hepsi kapatıldı.

### 3.1 Bulunan hatalar, kök nedenleri ve kapatılan düzeltmeler

**#1 — Açılışta seçili dilin sözlüğü yüklenmiyordu (KRİTİK).**
`frontend/src/i18n/index.ts`: `i18n.init({ lng: resolveInitialLocale() })` seçili dili (ör. `en`)
kurar, ama `resources` yalnızca eager `tr` paketini taşırdı — `en/de/fr` yalnızca sonradan
çağrılan `setLocale()` içinde inerdi. Tam sayfa yenilemesinde `setLocale()` hiç çağrılmadığı için
`i18n.language === 'en'` olduğu hâlde `en` sözlüğü bellekte yoktu ve i18next sessizce
`fallbackLng: 'tr'`e düşerdi: dil seçici "English" gösterirken arayüz Türkçe basardı. **Her tam
sayfa yenilemesinde arayüz sessizce Türkçeye düşüyordu.**
**Düzeltme:** seçili dil varsayılan değilse, paketleri ilk render'dan ÖNCE indiren bir açılış
kapısı eklendi (`bootstrapInitialLocale()` → `i18nReady` sözü, `main.tsx` `createRoot().render()`
çağrısını bu söz çözülene kadar geciktiriyor). `tr` seçiliyken hiç bekleme yok (eager paket zaten
bellekte). Sözlük inmezse (ağ hatası) konsola uyarı + dil açıkça `tr`ye çevrilir — localStorage'daki
seçim silinmez, kullanıcı niyeti bir sonraki yenilemede yeniden denenir.

**#2 — Aynı kusurun ikinci yarısı: `resolvedLanguage` tazelenmiyordu.**
Paket sonradan (`ensureBundlesLoaded`) eklendiğinde i18next `resolvedLanguage`'i kendiliğinden
tazelemiyor, `init()` anında sabitlediği `tr`de kalıyordu — `t()` doğru çalışsa bile
`<html lang>`, dil seçici ve **`getIntlLocale()` (para/tarih biçimi)** yanlış kalıyordu (metin
İngilizce, sayı/tarih biçimi Türkçe).
**Düzeltme:** aynı açılış kapısı içinde paket yüklendikten sonra `i18n.changeLanguage(initialLocale)`
**aynı dile** açıkça çağrılıyor — görünüşte gereksiz ama i18next'in resolvedLanguage'i tazelemesinin
tek yolu bu.

**#3 — Seed edilmiş taksonomiler hiçbir dilde çevrilmiyordu.**
Rol adları, talep kategorileri, pipeline aşama adları dört dilde de aynı Türkçe metni basıyordu.
**Kök neden — yanlış yerde bir ölçüt:** Faz 14'ün §1.5 kararı "DB'den geliyorsa kullanıcı
verisidir, çevrilmez" diye okunmuştu; ama seed ettiğimiz taksonomiler DB'den gelir VE bizim
taksonomimizdir. Doğru ölçüt "kim yazdı" olmalıydı (bkz. `docs/PROGRESS.md` Karar Günlüğü
2026-08-25). **Düzeltme (pipeline aşamaları için):** yeni
`backend/database/migrations/2026_08_25_960001_add_name_key_to_pipeline_stages_table.php` —
`pipeline_stages.name_key` kolonu: DOLUYSA satır bizim taksonomimizdendir ve admin ismini hiç
değiştirmemiştir (frontend `enums.json`'dan çevirir); NULL'sa isim müşteri verisidir (admin
yeniden adlandırmış/yeni aşama açmış — frontend ham `name`'i basar). `PipelineStageSeeder`
doldurur; `PipelineStageService::update()` isim GERÇEKTEN değişince kolonu null'lar, yalnız
renk/sıra değişince korur. Geriye dönük göç, mevcut kurulumu doldururken yalnız slug'a değil
**`name`'in hâlâ seed değerine birebir eşit olmasına da** bakar — admin migration'dan önce zaten
yeniden adlandırmışsa o satır "müşteri verisi" sayılır, çeviriyle ezilmez.

**#4 — Aktiviteler sayfası tamamen çöküyordu.**
`DemoDataSeeder` bazı aktiviteleri backend'in artık kabul etmediği eski bir tip değeriyle
(`'visit'`) üretmişti (bkz. `DemoDataSeeder::ACTIVITY_TYPES` yorumu); backend validasyonu yalnızca
`call/meeting/email/note` kabul ediyor ama DB'de bu bypass'lı eski satır kalmıştı. Frontend'in
literal eşlemesi (`TYPE_ICON[type]`) bilinmeyen bir `type` için `undefined` döndürüyor,
`<Icon/>` React ağacını **tamamen çökertiyordu** (sayfa beyaz ekran).
**Düzeltme:** `activityTypeMeta.ts`'e `UNKNOWN_TYPE_ICON`/`UNKNOWN_TYPE_VARIANT`/
`UNKNOWN_TYPE_LABEL_KEY` nötr düşüşü + `resolveActivityTypeIcon()`/`resolveActivityTypeLabelKey()`
yardımcıları eklendi; `ActivityTypeBadge.tsx` artık karşılıksız `type` için çökmek yerine nötr
ikon + "Diğer" gibi bir etiket (`enums:activity.type.unknown`) gösteriyor. Ham DB değerini
(`'visit'`) doğrudan basmak yerine bu tercih edildi — teknik bir literal kullanıcıya sızmasın diye.
Dashboard'daki `RecentActivities.tsx` da aynı ortak çözücüye bağlandı (önceden iki bileşen aynı
durumu farklı ele alıyordu).

**#5 — `Select` primitive'i hem `value` hem `defaultValue` geçiriyordu.**
React'e controlled/uncontrolled karışık sinyal veriliyordu (konsol uyarısı). **Düzeltme:**
`frontend/src/components/ui/Select.tsx` artık `value !== undefined` ise controlled kabul ediyor
ve o durumda `defaultValue`'yu hiç geçirmiyor; `value` verilmemişse eskisi gibi
`defaultValue ?? (placeholder ? '' : undefined)` ile uncontrolled placeholder davranışını koruyor.

**#6 — Fransızcada filtre `<select>`leri metni kırpıyordu.**
Sabit `w-44` (126px) genişlik, en uzun FR filtre metnini (gereken ~143px) sığdırmıyordu.
**Düzeltme:** ilgili filtre `<select>` genişlikleri artırıldı.

**#7 — DE/FR/TR terminoloji tutarsızlığı.**
Aynı modül kenar çubukta bir kelimeyle, sayfa içinde başka bir kelimeyle anılıyordu (ör. bir
modülün DE çevirisinde sidebar'da farklı, sayfa başlığında farklı isim). **28 dosyada 215 değer**
tek terime birleştirilerek düzeltildi.

**#8 — Çevrilmemiş taksonomi sızıntıları (çözücüyü atlayan ham basım).**
Dört ayrı yerde enum/taksonomi değeri kod çözücüsünü (i18n `enums` namespace resolver'ı) atlayıp
ham DB değerini basıyordu: rol rozeti (`OnlineUsersPanel.tsx`, `OnlineUsersPopover.tsx`),
bilinmeyen aktivite tipi (`RecentActivities.tsx` — #4 ile aynı kök neden, ortak çözücüye
bağlanarak kapatıldı), talep kategorisi (`TicketDetailPage.tsx`), lead kaynağı
(`SourceAnalysisTab.tsx`). Her biri ilgili `enums`/`resolveX` çözücüsüne yönlendirilerek
düzeltildi.

### 3.2 Yeni regresyon araçları

Bu turun ürettiği en kalıcı çıktı, hatanın kendisini değil **sınıfını** kilitleyen bir araç:
`npm run i18n:check-bootstrap` — açılış-anında lazy paket yükleme + `resolvedLanguage` tazeleme
davranışını (#1/#2) regresyona karşı kilitler. Mevcut `npm run i18n:check` (anahtar parite) bu
sınıfı hiç yakalayamazdı çünkü anahtarların KENDİSİ eksik değildi — hangi paketin NE ZAMAN
belleğe indiği bir çalışma-zamanı/zamanlama sorunuydu.

---

## 4. Kısa Yeniden-Kabul Turu (PHASE-INTL §6)

`docs/PHASE-INTL.md` §6'nın tarif ettiği tur — Faz 13'ün 6-rol/tam İz B turunun tekrarı DEĞİL,
metin + özellik smoke turu:

- **(a) 6 rol × ana akışlar, çevrilmiş UI'da:** ana akışlar (lead→fırsat→teklif→görev/ticket→rapor
  zincirinin erişim/görünürlük boyutu) çevrilmiş metinle de doğru çalışıyor.
- **5 rol × 14 modül × 2 dil = 140 hücrenin 140'ı** `RolePermissionSeeder` ile tutarlı bulundu.
- Yetkisiz doğrudan URL erişimi her rolde doğru red ekranını gösterdi, **0 JS hatası**.
- **(c) Para birimi/kur etiketi/PDF kur satırı:** para birimi seçici, rapor/dashboard kur dipnotu
  + >4 gün bayatlık uyarısı, TRY teklifte kur satırının BASILMAMASI (kendi para biriminde belge —
  Faz 14 kararı) doğrulandı.
- **(d) C1–C4 yetki sınırı:** C1–C4 bağlayıcı kısıtları (`PHASE-AUDIT.md` §5.4) hem backend
  testiyle hem canlı kanıtla doğrulandı; **36/36 güvenlik testi yeşil.**
- Faz 14 özellik smoke'u (§4b'nin bir parçası olarak) genel olarak geçti.

---

## 5. Bölüm 6 Global Kabul Kriterleri — Sonuç

`docs/ROADMAP.md` §6'daki 10 maddelik listenin son turu: **8'i tam GEÇTİ, 2'si KISMEN, 0'ı
GEÇMEDİ.**

| Madde (özet) | Sonuç |
|---|---|
| 1. `migrate --seed` tek komutla dolu demo sistem | ✅ GEÇTİ |
| 2. Temiz şema + ER diyagramı README'de | ✅ GEÇTİ |
| 3. Server-side pagination/sort/filter/search | ✅ GEÇTİ |
| 4. Empty/skeleton/error/toast + klavye + dark/light + WCAG 2.1 AA | 🟨 **KISMEN** |
| 5. Ham SQL yok, `dangerouslySetInnerHTML` yok, `.env` disiplini | ✅ GEÇTİ |
| 6. Her endpoint'te Policy/Gate | ✅ GEÇTİ |
| 7. Güvenlik header'ları (CSP/HSTS/X-Frame-Options/X-Content-Type-Options/Referrer-Policy) | 🟨 **KISMEN** |
| 8. Feature testleri (auth/yetki/deal CRUD/log/chat) yeşil | ✅ GEÇTİ (1316/9695) |
| 9. README (kurulum + komutlar + API listesi) | ✅ GEÇTİ |
| 10. Audit trail JSON diff + Loglar sayfası canlı akış/presence | ✅ GEÇTİ |

**Madde 4 neden KISMEN:** empty state (41 dosyada `EmptyState`), loading skeleton (53 dosyada
`Skeleton`), error state + toast, dark/light mode kod kanıtıyla güçlü şekilde doğrulandı (odak
tuzağı, `aria-modal`, Esc, odak geri verme dahil). Ama WCAG 2.1 AA ve site geneli klavye turu
**tam otomatik denetlenemedi** — bu, araç/ortam kısıtıdır (bu fazda bağımlılıksız CDP yöntemiyle
elle sürülen tur erişim/yetki boyutuna odaklandı, tam bir otomatik erişilebilirlik taraması ayrı
bir araç seti gerektirir). Kapatılmadı; §7'de teslim sonrası aday olarak kayıtlı.

**Madde 7 neden KISMEN:** CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy canlı
yanıtta doğrulandı (4/5). **HSTS yalnız `$request->secure()` iken gönderiliyor**
(`SecurityHeaders.php` — Faz 13 tasarım kararı, bkz. `docs/PROGRESS.md` Karar Günlüğü
2026-08-25 "CSP tek profil değil..."); `config/security.php`'de `max_age=31536000` hazır ve
kodu doğru, ama yerel `http://localhost` ortamında `secure()` hiçbir zaman `true` dönmediği için
başlık **gözlemlenemedi** — bu bir kod eksikliği değil, ortam kısıtı. HTTPS ortamında canlı
doğrulama teslim sonrası aday.

Ayrıca doğrulandı: **Madde 6** (Policy/Gate) taraması 165 rotanın hepsini kapsadı,
**açıklanamayan yetkisiz uç bulunmadı.**

---

## 6. Bilinen Sınırlar ve Kapsam Dışı Bırakılanlar

Bu liste Faz 15'te **bilinçli olarak kapatılmayan** veya kapsam dışı tutulan kalemlerdir —
hata değil, kayıtlı karardır:

1. **WCAG 2.1 AA tam otomatik denetim yok (Bölüm 6 madde 4).** Kod kanıtı güçlü ama site geneli
   otomatik tarama (ör. axe-core) çalıştırılmadı — bkz. §5.
2. **HSTS yalnız HTTPS'te gözlemlenebilir (Bölüm 6 madde 7).** Kod hazır, yerel ortamda test
   edilemez — bkz. §5.
3. **Frontend birim/entegrasyon test altyapısı (vitest) hâlâ yok.** Faz 13'te aday olarak
   kaydedilmiş, Faz 14'te tekrar değerlendirilmiş, Faz 15'te de bilinçli olarak ertelendi:
   API sözleşmesi 1316 backend testiyle kilitli, 6-rol kabul turu kritik UI yollarını geçti,
   teslime iki adım kala sıfırdan jsdom+config+iskelet kurmak orantısız risk taşıyordu
   (bkz. `docs/PROGRESS.md` Karar Günlüğü 2026-08-25).
4. **F6 — Türkçe İ/ı collation sınırı.** `TurkishCase.php`/`turkishCase.ts` katlamayı düzeltti
   ama `utf8mb4_unicode_ci` collation `I`=`ı` saymadığı için yalnız-isimle aranan `Irmak`/`ırmak`
   çifti SQL ön-filtresinde PHP katlamasına hiç ulaşamıyor (bkz. `docs/PHASE-AUDIT.md` §4 F6).
   Faz 15'te KAPATILMADI.
5. **F11 — `CustomFieldController::index` yetkilendirme çağrısı taşımıyor** (kabul edilmiş bilinen
   durum — müşteri verisi değil şema metadata'sı, kapalı devre + davetle giriş nedeniyle düşük
   risk; bkz. `docs/PHASE-AUDIT.md` §4.1 F11). Faz 15'te KAPATILMADI.
6. **F12 — Kanban'ın yetkisiz rollerde attığı zararsız boşa istek** (`GET /api/users?per_page=100`
   403 dönüyor ama toast göstermiyor, "Sahip" filtresi zarifçe gizleniyor; bkz.
   `docs/PHASE-AUDIT.md` §4.1 F12). Faz 15'te KAPATILMADI.
7. **`QuoteFormPage`'de para birimi seçicisi yok** ve **`Company`de `currency` alanı yok** —
   Faz 14'ün bilinçli kapsam sınırları (`docs/PHASE-INTL.md` §8 (a)/(d)), Faz 15'te de
   değiştirilmedi.
8. **`docs/` içi spec dosyaları Türkçe kalıyor** (ROADMAP, PROGRESS, PHASE-AUDIT, PHASE-INTL,
   PHASE-DELIVERY, DATABASE, AUTH-FLOWS, SLA-DESIGN, QUOTE-FINANCIALS, SETTINGS-SAFETY,
   DESIGN-SYSTEM) — bilinçli sınır, Faz 14 §1.6'da karara bağlandı: README = teslim/dış-yüzey
   dokümanı (EN+TR), `docs/` = geliştirici çalışma dokümanı (TR). Teslim değeri yaratmayan bir
   çeviri işini kapsam dışı tutar.
9. **Super Admin rolü UI'da elle test edilemedi** (Faz 13 §3.1'den devralınan sınır — demo
   Super Admin şifresi önceki bir oturumda değişmiş; backend testleriyle zaten kilitli).
   Faz 15'te tekrar denenmedi, gerek görülmedi (Super Admin `Gate::before` ile tüm izinleri
   short-circuit ediyor, davranış tekildir).

---

## 7. Teslim Sonrası Aday İşler

Öncelik sırasıyla değil, kayıt sırasıyla:

1. **Frontend test altyapısı (vitest/jest)** — birim + entegrasyon testleri, özellikle `Select`
   controlled/uncontrolled sınıfı ve i18n açılış-sırası (§3.2) gibi çalışma-zamanı hatalarını
   derleme zamanında değil ama en azından CI'da yakalayacak bir regresyon katmanı.
2. **WCAG 2.1 AA tam otomatik denetimi** (ör. axe-core/pa11y ile site geneli tarama) + site
   geneli klavye-only gezinme turu.
3. **HSTS'in gerçek HTTPS ortamında doğrulanması** (yerel geliştirme ortamı `http://localhost`
   olduğu için gözlemlenemedi; kod zaten hazır).
4. **Uzun firma adlarının filtre `<select>`ine sığmaması** — §3.1 #6'daki FR genişlik düzeltmesi
   noktasal bir yama; kalıcı çözüm mevcut native `<select>`lerin (özellikle firma/kişi seçen
   filtreler) bir combobox/autocomplete bileşenine taşınması.
5. **F6 — isim arama için collation-bağımsız SQL ön-filtresi** (telefon eşleştirmedeki
   "SQL'de garantili üst küme + PHP'de kesin doğrulama" deseninin isimlere de uygulanması).
6. **F12 — Kanban'ın yetkisiz rollerde attığı boşa isteğin önlenmesi** (rol bazlı önkoşul kontrolü
   isteği hiç göndermeden atlama).
7. **`docs/` içi spec'lerin İngilizceye çevrilmesi** — yalnızca dış paydaşlarla `docs/` paylaşılırsa
   gerekli; şu an teslim kapsamının (README) dışında tutulan bilinçli bir sınır, iş listesine
   yalnızca ihtiyaç doğarsa girer.
8. **PDF tarih biçiminin yeniden değerlendirilmesi** — Faz 14'ün "d.m.Y dört dilde de sabit kalsın"
   kararı (bkz. `docs/PROGRESS.md` Karar Günlüğü 2026-08-25) kullanıcı tarafından teslim sırasında
   onaylandı; ileride PDF'i UI'nın locale davranışına uydurma isteği gelirse tek satırlık bir
   formatter parametresi değişikliği yeterli.
9. **`QuoteFormPage` para birimi seçicisi** ve **`Company.currency`** — Faz 14 §8'de kaydedilmiş,
   Faz 15'te de teyit edilmiş küçük/izole eksikler.
