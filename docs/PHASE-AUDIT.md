# PHASE-AUDIT — Faz 13: Güvenlik Denetimi, Kırmızı Takım, Kullanıcı Kabul & Attio Analizi

> **Statü: BAĞLAYICI FAZ SÖZLEŞMESİ (plan).** Bu doküman, Faz 12 (Chat) tamamlandıktan
> sonra çalıştırılacak Faz 13'ün tek doğruluk kaynağıdır. Faz üç iş kolundan (İz) oluşur:
> **İz A** kırmızı takım güvenlik denetimi, **İz B** 6-rol kullanıcı kabul turu, **İz C**
> Attio ANALİZİ (kabul/red kararı — özellik inşası değil). Kapsam, tehdit modeli, test matrisi,
> ön bulgular ve paralelleştirme planı burada sabitlenir; sapma gerekirse önce bu doküman güncellenir.
>
> **Faz bölme kararı uygulandı (kullanıcı onayı, 2026-08-24):** i18n + çoklu para birimi + Attio
> ÖZELLİK inşası ayrı bir faza — **Faz 14, `docs/PHASE-INTL.md`** — taşındı. Bu doküman yalnız
> Faz 13'ü (güvenlik denetimi/sertleştirme + kabul turu + Attio analizi) tarif eder. Teslim = Faz 15.
>
> İlgili sözleşmeler: `docs/PHASE-INTL.md` (Faz 14 — Attio özellik inşası + i18n + para birimi),
> `docs/ROADMAP.md` (§2, §2.1, §4, §5), `docs/AUTH-FLOWS.md`, `docs/SETTINGS-SAFETY.md`,
> `docs/DATABASE.md`, `docs/PROGRESS.md`. Tarih: 2026-08-24.
>
> **Bu bir PLAN'dır.** Aşağıdaki "ön bulgular" (§4) kodu OKURKEN saptandı; bu fazda
> KAPATILACAK, planlama sırasında değiştirilmedi.

---

## 0. Faz Yerleşimi ve Numaralandırma Kararı

**Karar: Fazlar üçe bölündü (kullanıcı onayı, 2026-08-24).** Nihai sıra:
**Faz 12 Chat → Faz 13 Güvenlik Denetimi/Kırmızı Takım/Kabul + Attio ANALİZİ → Faz 14
i18n + Çoklu Para Birimi + Attio ÖZELLİKLERİ (`docs/PHASE-INTL.md`) → Faz 15 Teslim & Final.**
Bu iki kaydırmayı içerir: (1) yeni denetim fazı teslimden önce eklendi; (2) i18n/para birimi/
Attio-özellikleri ayrı bir faza çıkarıldı ve teslim sona (Faz 15'e) itildi. İki dokümandaki +
yeni `PHASE-INTL.md`'deki tüm faz numarası referansları tutarlı güncellendi.

**Neden Faz 12'den sonra, teslimden önce:** Kırmızı takım turu Faz 12'nin (Chat) getirdiği
yeni saldırı yüzeylerini — `private-conversation.{id}` kanalı, mesaj gövdesi XSS, dosya
yükleme, imzalı URL — test etmek zorunda; bu yüzden Chat'ten SONRA gelmeli. Teslim/README/
son kabul turu ise her zaman en sonda (Faz 15).

**Neden bölme:** i18n + para birimi + Attio-özellikleri yeni ÖZELLİK inşasıdır; güvenlik
denetimi teslim öncesi bir kapıdır. İki doğa ayrı fazlarda daha temiz yürür (risk izolasyonu,
bağımsız "bitti" kriteri, ayrı inceleme havuzu). Gerekçe: eski PHASE-AUDIT §12 (uygulandı).

**Faz 13 ile (eski teslim fazından gelen) güvenlik işlerinin çakışma çözümü — iş bir kez tarif edilir:**

| Kalem | Eski Faz 13'te miydi? | Yeni yeri | Gerekçe |
|---|---|---|---|
| Güvenlik header'ları (CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy) | Evet | **Faz 13** (§7-H1) | Sertleştirme; kırmızı takımın doğruladığı yüzeyin parçası |
| Upload sertleştirme (MIME/boyut/rastgele isim/public-dışı/imzalı URL) | Evet | **Faz 13** (§7-H6) | Faz 12 chat upload'ı ile aynı turda test edilip sertleştirilir |
| IDOR + mass-assignment kontrol turu | Evet | **Faz 13** (§2-A2, §2-A4) | Kırmızı takımın çekirdeği; burada yapılır |
| Güvenlik/yetki regresyon testleri (auth, yetki, IDOR, kanal) | Kısmen (feature testleri) | **Faz 13** (§6) | Bir bulgu, onu kilitleyen test yazılmadan "kapatıldı" sayılmaz |
| Genel feature/E2E test kapsamı tamamlama (deal CRUD, log, chat mesajı vb.) | Evet | **Faz 15** | Salt işlevsel kapsama; güvenlik dışı |
| README final (kurulum + API endpoint listesi + ER diyagramı) | Evet | **Faz 15** | Teslim çıktısı |
| Son kabul turu (Bölüm 6 global kriterler) | Evet | **Faz 15** | Teslimin son adımı |

Böylece hiçbir iş iki fazda tarif edilmez: **Faz 13 = bul + sertleştir + kilitle (test) +
Attio analizi**; **Faz 14 (`PHASE-INTL`) = i18n + para birimi + Attio özellik inşası**;
**Faz 15 = işlevsel kapsamayı tamamla + belgele + teslim et**.

**Faz 14 (i18n) ile sıralama kısıtı ARTIK YOK:** Önceki tek-faz planında "i18n, kabul turundan
önce bitmeli" kısıtı vardı; bölme sonrası **kabul turu (İz B) Faz 13'te, i18n Faz 14'te** olduğu
için bu kısıt geçersizdir. Sonucu: Faz 14 tüm UI metnini değiştirdiğinden **Faz 15'e kısa bir
yeniden-kabul turu eklenir** (metin + özellik smoke turu; tam İz B tekrarı değil) — bkz.
`docs/PHASE-INTL.md` §6 ve ROADMAP §2 Faz 15 satırı.

---

## 1. Yığına Özgü Tehdit Modeli

**Sistem sınırı (varsayımlar):** Kapalı devre, yalnız davetle, tek makine (Windows/XAMPP),
üretimde tek sunucu. Public register yok, dış e-posta/OAuth/AI/enrichment yok. Auth =
Sanctum **SPA cookie session** (token değil; `User` `HasApiTokens` KULLANMAZ — bkz.
`bootstrap/app.php`). CSRF = `XSRF-TOKEN` çerezi → `X-XSRF-TOKEN` header (`frontend/src/lib/axios.ts`).
Realtime = Reverb, 8 kanal (`routes/channels.php`). Kuyruk/cache/session/broadcast = Redis.

**Baş aktör (en yüksek olasılık): kimliği doğrulanmış ama düşük yetkili içeriden kullanıcı.**
6 rol var (`RolePermissionSeeder`): Super Admin (Gate::before ile tümü), Admin (57 izin),
Satış Müdürü (41), Satış Temsilcisi (26), İzleyici (15 — hiçbir yazma), Destek Temsilcisi (14).
Tehdidin ağırlığı, geçerli bir oturumu olan bir kullanıcının **yetkisi dışındaki veriye/işleme**
(başka temsilcinin fırsatı, teklif finansalı, audit logu, rol matrisi) ulaşmaya çalışmasıdır.

**İkincil aktörler:** (a) oturum çerezi çalınmış kullanıcı (XSS veya fiziksel erişim);
(b) tarayıcı içi XSS taşıyıcısı (mesaj gövdesi, e-posta şablonu `body_html`, custom field
değerleri); (c) dış anonim — yüzeyi kasıtla küçük: yalnız `POST /login`, `POST /password/forgot`,
`GET /sanctum/csrf-cookie`, `GET|POST /broadcasting/auth`.

**Korunan varlıklar (öncelik sırası):** 1) Kimlik/oturum bütünlüğü ve
inkâr-edilemezlik (`session_logs`/`activity_log` — bkz. AUTH-FLOWS §2); 2) yetki sınırı
(RBAC/Policy/Gate her uçta); 3) müşteri PII (leads/contacts/companies); 4) finansal veri
(deals tutarları, quotes/quote_items); 5) rol/izin matrisi (yetki yükseltme yüzeyi).

**Yatay izolasyon kararının yorumu (Model C — bkz. PROGRESS karar günlüğü 2026-08-25 #1):**
`PRODUCT-BRIEF.md:36`'daki "her kayıt erişiminde sahiplik/yetki kontrolü" ifadesi,
OKUMADA yetki / YAZMADA sahiplik+yetki olarak yorumlandı; bu okuma aynı belgenin
paylaşılan-Kanban (satır 63) ve kayda-bağlı-sohbet (satır 75) gereksinimleriyle tek tutarlı
okumadır. Okuma tarafında sahiplik aranmaması, satır 36'nın en katı okumasından BİLİNÇLİ BİR
SAPMADIR ve gerekçesi burada kayıtlıdır.

**Kapsam dışı (bilinçli):** Dış ağ/altyapı sertleştirme (tek makine, işletim sistemi/XAMPP
konfigürasyonu organizasyonel), DDoS/hacimsel saldırı, fiziksel güvenlik, dış e-posta kanalı
(yok). Bunlar bu CRM'in tehdit yüzeyinde değil.

---

## 2. İZ A — Kırmızı Takım Test Matrisi

Her satır çalıştırılabilir bir denemedir. **Yöntem** sütunu bu repodaki gerçek uç/dosyayı
gösterir. Testler `tests/Feature/Security/` altında feature testine dönüştürülür (bir bulgu,
regresyon testi yazılmadan kapatılmış sayılmaz). "Durum" alanı yürütmede ⬜/✅/🔴 ile işlenir.

### A1 — Kimlik Doğrulama / Oturum

| # | Saldırı | Yöntem (uç/dosya) | Beklenen | Durum |
|---|---|---|---|---|
| A1.1 | CSRF'siz mutasyon | `POST /api/leads` (X-XSRF-TOKEN'sız) | 419 `CSRF_TOKEN_MISMATCH`; axios yalnızca 1 kez retry eder (`axios.ts`) | ✅ |
| A1.2 | Session fixation | `POST /login` öncesi/sonrası session id | Login `session()->regenerate()` yapar (AUTH-FLOWS §1); id değişir | ✅ |
| A1.3 | `password.changed` atlama | `routes/api.php`'deki grubun DIŞINDA uç var mı? `route:list` ile tara; whitelist yalnız `logout`/`me`/`password/change` olmalı | Grup dışı veri ucu = 🔴 kritik (R11). Şu an yapı beyaz-listeli — doğrula | ✅ |
| A1.4 | Deaktive edilmiş kullanıcının canlı oturumu | Kullanıcı pasifleştir → sonraki istek | `active` middleware 403 `USER_DEACTIVATED`; `UserDeactivated` WS ile düşürme | ✅ |
| A1.5 | Login brute-force | `throttle:login` (email+IP), artan bekleme 1→2→4→8→16→32→60 dk (`AppServiceProvider`) | Kilitleme + `session_logs` `locked_out` kaydı | ✅ |
| A1.6 | `current_password` oracle'ı | `POST /api/password/change` tekrarlı | `throttle:6,1` sınırlar (routes/api.php:82) | ✅ |
| A1.7 | Diğer oturumların şifre değişiminde düşmesi | Şifre değiştir → başka oturumdan istek | Sanctum `AuthenticateSession` hash uyuşmazlığında 401 (config/sanctum.php) | ✅ |
| A1.8 | `/broadcasting/auth` üzerinden yetki sızıntısı | `password.changed` bilinçle YOK (bootstrap/app.php) | Yalnız kimlik + zaten sahip olunan izinler; veri ucu değil — doğrula | ✅ |

### A2 — Yetkilendirme / IDOR

Her `show/update/destroy` ve `assign/status/move/convert` ucu için sahiplik + izin turu.
`routes/api.php`'deki tüm parametreli uçlar hedeftir.

| # | Saldırı | Yöntem | Beklenen | Durum |
|---|---|---|---|---|
| A2.1 | Başkasının kaydını okuma | Satış Temsilcisi A → `GET /api/deals/{B'nin_deal}`, aynısı leads/contacts/companies/quotes/tasks/tickets/activities | 200 (bilinçli düz görünürlük — bkz. §1, Model C: yatay okuma sahiplikten bağımsız, yalnız modül `.view` izni gerekir) | ✅ |
| A2.2 | Başkasının kaydını güncelleme/silme | `PATCH`/`DELETE` çapraz sahip | 403 (sahiplik yazmada zorunlu; `*.assign` izni olmadan çapraz-sahip yazma engellenir) | ✅ |
| A2.3 | Yatay atama manipülasyonu | `PATCH /api/deals/{id}/assign` ile kaydı kendine/başkasına atama | İzin + Policy; yetkisiz atama 403 | ✅ |
| A2.4 | İzleyici yazma denemesi | İzleyici rolü (15 izin, yazma yok) tüm `POST/PATCH/DELETE` | Hepsinde 403 | ✅ |
| A2.5 | Super Admin muafiyeti kapsamı | `Gate::before` (`AppServiceProvider:145-153`) `null` döndürüyor mu (short-circuit yok)? | Yalnız Super Admin `true`; diğerleri `null` → normal Gate akışı. **Recon: SAFE** | ✅ |
| A2.6 | Yetki yükseltme (yukarı) | `settings.manage` olan aktör `PATCH /api/settings/roles/{role}/permissions` ile kendine izin ekler | Sync yalnız var olan izinleri taşır; Super Admin rolü `ROLE_NOT_EDITABLE`; `CANNOT_REVOKE_OWN_SETTINGS_ACCESS`. **Recon: SAFE — ama "kendine yukarı yükseltme" için de açık test yaz** | ✅ |
| A2.7 | Log/rapor/dashboard izin sınırı | `logs.view`/`reports.view`/`dashboard.view` olmayan rol ilgili uçlara | 403 (kontrol `Gate::allows`/Policy) | ✅ |
| A2.8 | Kendini kilitleme / son Super Admin | `UserPolicy`: kendini pasifleştirme/silme, son aktif Super Admin koruması (Faz 2) | Engellenir | ✅ |

### A3 — Broadcast Kanalları (8 kanal — `routes/channels.php`)

Her kanal için: yetkisiz abone denemesi + payload sızıntısı denemesi. Testler `reverb`
sürücüsünü zorlar, `null` DEĞİL (R14 — `NullBroadcaster` her isteğe 200 döner ve hiçbir
şeyi doğrulamaz).

| # | Kanal | Saldırı | Beklenen | Durum |
|---|---|---|---|---|
| A3.1 | `user.{id}` | Başkasının id'sine abone (admin dahil) | Katı kimlik; `$user->id === (int)$id`. Admin override YOK | ✅ |
| A3.2 | `online` | Pasif hesapla abone | `is_active` false → reddet; payload yalnız id/name/email/role/department (PII fazlası sızmasın — doğrula) | ✅ |
| A3.3 | `record.{type}.{id}` | (a) `type` sınıf enjeksiyonu (`App\Models\...`), (b) izin yokken abone, (c) var olmayan id ile enumerasyon | Whitelist (ChannelRegistry) → izin → varlık; üçü de reddet (IDOR sızıntısı yok) | ✅ |
| A3.4 | `conversation.{id}` (Faz 12) | Katılımcı olmayan abone; `chat.use` yokken | Pivot kontrolü + `chat.use`; ikisi de gerekli | ✅ |
| A3.5 | `logs` / `dashboard` / `deals` / `tickets` | İlgili `.view` izni olmadan abone | 403 (her callback `is_active` + izin) | ✅ |
| A3.6 | Payload sızıntısı | Her event payload'ı — model değil düz skaler dizi mi? Fazla alan var mı? | `DealMoved`/`Ticket*` payload'ları düz; hassas alan yok — doğrula | ✅ |

### A4 — Mass Assignment

Tüm modellerde `$fillable` denetimi + FormRequest kesişimi. **Recon bulgusu: SAFE** —
hassas alanlar `$fillable`'da olsa da `UpdateXRequest`'lerde `['missing']` kuralıyla
istemciden gelirse 422; controller yalnız `$request->validated()` geçirir.

| # | Alan | Model / koruma | Beklenen | Durum |
|---|---|---|---|---|
| A4.1 | `pipeline_stage_id`, `position`, `version`, `status` | `Deal` — `UpdateDealRequest:38-41` `missing` | Gönderilirse 422 (yalnız `/move` değiştirir) | ✅ |
| A4.2 | `status` + 8 `sla_*` | `Ticket` — `UpdateTicketRequest:55-64` `missing` | 422 (yalnız `/status` ve servis) | ✅ |
| A4.3 | `status`, totals, `parent_quote_id`, `revision` | `Quote` — `UpdateQuoteRequest` `missing` | 422 | ✅ |
| A4.4 | `role`, `is_active` | `User` — mass assignment DIŞI (`UserService` `unset($data['role'])`, `toggleActive` ayrı) | Store/update'te sessizce yok sayılır / ayrı Policy'li uç | 🔴 (bkz. §4.1 F7 — `create()` rol yükseltmesi, KAPATILDI) |
| A4.5 | `must_change_password` | `User` `$fillable`'da — dış uçtan set edilebiliyor mu? | Yalnız admin reset akışı yazmalı; store/update gövdesinden set → engellenmeli (doğrula, test yaz) | ✅ |
| A4.6 | `owner_id`, `assigned_to`, `is_primary` | Deal/Lead/Ticket/Contact | Yalnız yetkili atama ucundan; çapraz atama 403 (A2.3 ile örtüşür) | 🔴 (bkz. §4.1 F8 — `.update` üzerinden `.assign` atlatma, KAPATILDI) |

### A5 — Injection / XSS

| # | Yüzey | Yöntem | Beklenen | Durum |
|---|---|---|---|---|
| A5.1 | Rapor `group_by`/`sort` | `GET /api/reports/*?group_by=` sahte değer; `?sort=` sahte kolon | `GroupByPeriod::validate()` whitelist (day/week/month) → 422; `resolveSort()` bilinmeyen kolonu güvenli varsayılana düşürür. **Recon: SAFE** | ✅ |
| A5.2 | Ham SQL | `app/` altında `selectRaw`/`DB::raw`/`whereRaw` taraması | Yalnız statik parça veya çözümlenmiş whitelist sabiti; kullanıcı stringi asla raw'a girmez. **Recon: SAFE** | ✅ |
| A5.3 | E-posta şablonu `body_html` XSS | `EmailTemplateFormModal.tsx:185` `dangerouslySetInnerHTML`; `StoreEmailTemplateRequest` sanitizasyon YOK | **ÖN BULGU (§4-F5).** Şu an `settings.manage` arkasında + posta gönderimi yok (self-XSS); yine de sanitize + `dangerouslySetInnerHTML` kaldırma/izole render | 🔴 (F5, KAPATILDI) |
| A5.4 | Chat mesaj gövdesi XSS (Faz 12) | Mesaj `body` gönder → başka istemcide render | Frontend metni escape etmeli; `dangerouslySetInnerHTML` KULLANILMAMALI (kalite çizgisi) | ✅ |
| A5.5 | Teklif PDF | `resources/views/pdf/quote.blade.php` | Tüm dinamik alan `{{ }}` (escaped); `{!! !!}` YOK. **Recon: SAFE** | ✅ |
| A5.6 | CSV/XLSX formül enjeksiyonu | Kullanıcı adını/firma/notu `=cmd|...`/`=HYPERLINK(...)` yap → export'ta hücre | **ÖN BULGU (§4-F1).** `=+-@`, tab, CR ile başlayan hücre `'` ile nötrlenmeli | 🔴 (F1, KAPATILDI) |
| A5.7 | Custom field değeri XSS | `custom_field_values` metni ekranlarda render | Escape doğrula | ✅ |
| A5.8 | XXE (yalnız tehdit sınıfı olarak kataloglanır) | Bu repoda bugün XML ayrıştırma yüzeyi YOK; TCMB kur ayrıştırıcısı **Faz 14'te doğar** | Somut XXE testi (A5.8) + sertleştirme (H7) **Faz 14'te**, tek yerde: `docs/PHASE-INTL.md` §2.5. Burada yalnız kayıt altına alınır | ⬜ (Faz 14'te — bu fazda yüzey yok) |

### A6 — Dosya Yükleme

| # | Saldırı | Yöntem | Beklenen | Durum |
|---|---|---|---|---|
| A6.1 | CSV import kötü MIME/uzantı | `POST /api/leads/import` — `ImportLeadsRequest` `mimes:csv,txt` + `mimetypes:` çift + `max:5120` | Reddet. **Recon: SAFE** (uuid isim, `local` private disk) | ✅ |
| A6.2 | Chat dosya yükleme (Faz 12) | Çift uzantı (`x.php.jpg`), polyglot, path traversal isim, >boyut | MIME+uzantı beyaz liste, rastgele isim, public-DIŞI saklama | ✅ |
| A6.3 | Yüklenen dosyanın public erişimi | Doğrudan URL ile erişim denemesi | Yalnız imzalı URL; süre sınırlı (Faz 13-H6) | ✅ (imzalı URL yerine oturum çerezi + `AttachmentPolicy` ile korunuyor — bkz. A6.4 notu ve PROGRESS karar günlüğü 2026-08-25 #3) |
| A6.4 | İmzalı URL süresi/manipülasyon | Süresi geçmiş / imza değiştirilmiş URL | 403 | ⬜ — **bilinçli olarak UYGULANMADI.** İmzalı URL taşıyıcı yetkidir (referrer/tarayıcı geçmişinde sızar, paylaşılınca iptal edilemez); ekler yerine oturum çerezi + `AttachmentPolicy` ile korunuyor. Gerekçe: PROGRESS karar günlüğü 2026-08-25 #3 |

### A7 — Kaynak Tüketimi

| # | Saldırı | Yöntem | Beklenen | Durum |
|---|---|---|---|---|
| A7.1 | Sayfalama üst sınırı | `?per_page=100000` tüm liste uçları | `max:100` her `Index*Request`'te. **Recon: SAFE** | ✅ |
| A7.2 | Sınırsız rapor tarih aralığı | `GET /api/reports/*?from=2000-01-01&to=2026-12-31` | **ÖN BULGU (§4-F4).** `DateRangeResolver`'da MAX SPAN yok — üst sınır eklenmeli (ör. 366 gün + `insights` için daha uzun kural) | 🔴 (F4, KAPATILDI) |
| A7.3 | Export sıklık istismarı | `GET /api/logs/export`, `/reports/export`, `POST /api/leads/import` art arda | **ÖN BULGU (§4-F3).** Throttle YOK; yalnız izin var. Rate limit eklenmeli | 🔴 (F3, KAPATILDI) |
| A7.4 | Chat geçmişi / N+1 | Mesaj listesi derin sayfalama; timeline (R19) | Keyset/limit; N+1 teste bağlanmalı | ✅ |

### A8 — Sırlar / Konfig

| # | Kontrol | Yöntem | Beklenen | Durum |
|---|---|---|---|---|
| A8.1 | `.env` sızıntısı | Repo + build çıktısı | `.env` git'te yok (kabul kriteri) | ✅ |
| A8.2 | `APP_DEBUG` | `.env.example:4` = `true` | **ÖN BULGU (§4-F2).** `.env.example` `false` olmalı (`config/app.php` fallback zaten `false`) | 🔴 (F2, KAPATILDI) |
| A8.3 | Hata yanıtı stack trace | 500 tetikle | Generic `SERVER_ERROR`; trace/SQL/path sızmaz — debug'da bile yalnız `exception`+`message`. **Recon: SAFE** (`bootstrap/app.php`) | ✅ |
| A8.4 | Bağımlılık açıkları | `composer audit` + `npm audit` | Temiz; açık varsa kaydet/karar ver | ✅ |

---

## 3. İZ B — Gerçek Kullanıcı Kabul Testi (6 Rol Uçtan Uca)

> **Bu tur Türkçe UI üzerinde yapılır.** i18n Faz 14'te (`PHASE-INTL`) geldiği için burada dil
> kısıtı yoktur. Faz 14 tüm UI metnini değiştireceğinden, bu turun **kısa bir yeniden-kabulü
> Faz 15'te** yapılır (metin + özellik smoke turu; tam tekrar değil — iş mantığı/yetki değişmez).
> Bkz. `docs/PHASE-INTL.md` §6.

Amaç: her rolle giriş yapıp ana akışları yürütmek ve **her rolün görmemesi gerekeni
görmediğini** doğrulamak. Demo hesap şifresi `Demo!2026Syncra`, `must_change_password=false`
(PROGRESS §Bir Sonraki Adım). Ana akış zinciri: **lead → fırsat → teklif → görev/destek → rapor**.

**Rol × yetki beklentisi (RolePermissionSeeder):**

| Rol | Beklenen erişim | Görmemesi gereken |
|---|---|---|
| Super Admin | Her şey (Gate::before) | — |
| Admin (57) | Tüm modüller + ayarlar + kullanıcı yönetimi | (Super Admin'e özel korumalar) |
| Satış Müdürü (41) | Lead/deal/quote/report tam; ekip görünürlüğü | Ayarlar/kullanıcı yönetimi sınırı; logs? |
| Satış Temsilcisi (26) | Lead/deal/quote/task — **okuma tüm ekip** (düz görünürlük, bilinçli — bkz. §1 not, Model C), **yazma yalnız kendi kaydı** | Ayarlar, loglar, kullanıcı yönetimi, rol matrisi |
| Destek Temsilcisi (14) | Ticket + ilgili aktivite | Deal/quote finansalı, ayarlar, raporlar |
| İzleyici (15) | Salt-okuma | **Her türlü yazma** (tüm POST/PATCH/DELETE 403) |

**Akış senaryoları (her uygun rolle):**
1. Lead oluştur → duplicate uyarısı → yine de kaydet → müşteriye dönüştür (contact+company+deal).
2. Deal'ı Kanban'da taşı (iki tarayıcı, realtime senkron + 409 çakışma davranışı).
3. Teklif oluştur → kalem ekle → hesap önizleme (`/quotes/calculate`) → gönder → PDF indir.
4. Görev ata + hatırlatıcı; ticket aç → SLA sayacı → durum akışı → iç not (activities).
5. Rapor aç + CSV/XLSX export; dashboard canlı güncelleme.
6. Chat (Faz 12): DM + kayda bağlı panel + @mention → bildirim.

**Kenar ve dayanıklılık durumları (her ekran):**
- Boş durum (empty state), yükleme (skeleton), hata durumu + toast.
- Çift tıklama / çift gönderim (idempotency; ör. `/tasks/{id}/complete` idempotent).
- Ağ kesintisi (offline → istek başarısız → tekrar); optimistic update geri alma.
- Oturum sona ermesi (401 → `/login` yönlendirme; `axios.ts` interceptor).
- Zorunlu şifre değişimi ekranındaki kullanıcı (yalnız whitelist uçlar).
- Deaktive edilme (403 `USER_DEACTIVATED` → toast + logout).
- Klavye erişilebilirliği + dark/light + WCAG 2.1 AA (kalite çizgisi).

**Not — frontend test altyapısı yok (vitest/jest kurulu değil; 805 backend testi, 0 frontend).**
Bu faz E2E doğrulamayı çoğunlukla **backend feature testi + elle rol turu** ile yapar.
Frontend birim/entegrasyon test altyapısı kurmak Faz 14'e (işlevsel kapsama) aday olarak
bırakılır; bu fazda güvenlik regresyonları backend'de kilitlenir.

### §3.1 Yürütme sonucu (2026-08-25)

**Yöntem:** Bağımlılık kurulmadan sürüldü — headless Chrome + Node'un global `WebSocket`'i ile
doğrudan Chrome DevTools Protocol (CDP) konuşuldu. `npm install` yapılmadı, Playwright/Puppeteer
kurulmadı (bkz. PROGRESS karar günlüğü 2026-08-25). Uygulama gerçek dev ortamında çalıştırıldı
(Laravel `:8000` + Vite `:5173` + Reverb + Redis + MariaDB).

**Kapsam ve sonuç — 5 rol × 18 rota = 90 erişim hücresi, 90'ı da doğru:**

| Rol | UI'da reddedilen sayfalar |
|---|---|
| Admin | — (hepsi açık) |
| Satış Müdürü | tickets, logs, settings, users |
| Satış Temsilcisi | tickets, reports, logs, settings, users |
| Destek Temsilcisi | leads, deals, deals/list, quotes, products, price-lists, reports, logs, settings, users |
| İzleyici | settings, chat |

Her hücre ölçülen izin matrisiyle karşılaştırıldı, sapma YOK. Hiçbir rolde JS istisnası veya
konsol hatası çıkmadı (0 hata).

**Faz 13 sahiplik arayüzü GÖRSEL olarak doğrulandı:** Satış Temsilcisi (Zeynep Demir) panosunda
başka temsilcilerin (EY, MK) kartlarında kilit ikonu var, kendi kartlarında (ZD) yok. DOM
ölçümü: 40 kilitli kart (`aria-disabled="true"` + `cursor-not-allowed`), 10 sürüklenebilir kart,
ipucu metni "Bu kartın sahibi değilsiniz, taşıyamazsınız.". `aria-disabled` sayesinde klavye/
ekran okuyucu kullanıcıları da kısıtı algılıyor.

**Ayrıca doğrulandı:** oturum düşünce `/login`'e yönlendirme çalışıyor; koyu ve açık tema ikisi
de doğru render ediliyor (kilit ikonları, kontrast, yerleşim korunuyor).

**Turun bulduğu YENİ bulgu:** bkz. §4.1 F12 (DÜŞÜK, KAPATILMADI, Faz 15 adayı).

**Kapsanmayan (dürüst kayıt):**
1. **Super Admin UI'da test EDİLEMEDİ:** `admin@syncra.local` hesabının şifresi önceki bir
   oturumda değiştirilmiş (`must_change_password` artık `false`), seeder'daki
   `SyncraAdmin!2026` çalışmıyor. Kullanıcının admin şifresi bilinçli olarak SIFIRLANMADI.
   Super Admin'in "her şeyi görür" davranışı backend testleriyle (`RoleAcceptanceTest`,
   `PrivilegeEscalationTest`) zaten kilitli.
2. **CRUD akış senaryoları uçtan uca sürülmedi** (lead → fırsat → teklif → görev/ticket → rapor
   zinciri). Tur erişim/yetki boyutunu ve yeni sahiplik arayüzünü kapsadı.
3. Çift gönderim (idempotency), çevrimdışı davranış ve klavye navigasyonu elle sınanmadı.

---

## 4. Ön Bulgular (Kod Okunurken Saptandı — Bu Fazda Kapatılacak)

> Bunlar planlama sırasında OKUMA ile bulundu; **hiçbiri şimdi düzeltilmedi.** Ciddiyet
> sırasıyla:

**F1 — CSV/XLSX formül enjeksiyonu (GERÇEK, düşük maliyetli). 🔴 KAPATILDI.**
Düzeltme: `app/Support/CsvFormulaGuard.php` (yeni, tek merkezî yardımcı). Kilitleyen testler:
`tests/Unit/CsvFormulaGuardTest.php`, `tests/Feature/Security/CsvFormulaInjectionTest.php`.
**Ek bulgu (yürütmede saptandı):** PhpSpreadsheet, `=` ile başlayan bir string'i otomatik
`TYPE_FORMULA` olarak işaretleyip XLSX'e GERÇEK bir formül düğümü yazıyordu — yani XLSX yolu
CSV'den daha tehlikeliydi (CSV'de nötrleme yeterliyken XLSX'te PhpSpreadsheet'in kendi tip
algılamasını da bastırmak gerekti).
Export hattında `=`/`+`/`-`/`@` ile başlayan hücreler nötrlenmiyor; `fputcsv()` ham değere
çağrılıyor.
- `app/Services/Logging/LogQueryService.php:124` (`fputcsv($handle, $mapper($row))`); mapper'lar
  (~150-215) `user?->name`, `title` (sayfa başlığı), `description` (serbest metin firma/kişi/
  deal/lead adlarını yansıtır) gibi kullanıcı-etkili alanları içerir.
- `app/Exports/ActivityLogsExport.php:47-61`, `SessionLogsExport.php:44-63`,
  `PageVisitLogsExport.php:44-61` (XLSX, aynı alanlar).
- `app/Services/Reports/ReportExportService.php:100-115` daha düşük risk (sunucu-hesaplı agregalar).
Herhangi bir kimlikli kullanıcı kendi adını/firmasını/notunu `=HYPERLINK(...)` yapabildiğinden,
bu değerler admin'in Excel'de açtığı export'a düşer → sömürülebilir. **Düzeltme:** hücre
`=+-@`/tab/CR ile başlıyorsa başına `'` ekle (tek merkezî yardımcı).

**F2 — `.env.example` `APP_DEBUG=true` gönderiyor (`.env.example:4`). 🔴 KAPATILDI.**
Düzeltme: `.env.example`'da `APP_DEBUG=false`. Kilitleyen test: `tests/Feature/Security/EnvExampleTest.php`.
`config/app.php:42` fallback `false` (kod güvenli) ama örnek şablonun literal değeri `true`;
dikkatsiz kopyada stack trace sızabilir. **Düzeltme:** örnekte `APP_DEBUG=false`.

**F3 — `leads/import`, `logs/export`, `reports/export` uçlarında rate limit YOK. 🔴 KAPATILDI.**
Düzeltme: `POST /leads/import` → `throttle:5,1,leads-import`; `GET /logs/export` +
`GET /reports/export` → ortak `throttle:10,1,heavy-export`. Anahtar kullanıcı bazlı (Laravel
kimlikli isteklerde zaten user id ile anahtarlıyor). Kilitleyen test:
`tests/Feature/Security/ExportThrottleTest.php`.
`routes/api.php:155`, `:133`, `:400` — yalnız izinle korunuyor; sıklık sınırı yok. Pahalı,
DB/CPU-ağır işlemler → maliyet/DoS yüzeyi. `LogQueryService::EXPORT_ROW_LIMIT=50000` satır
sayısını sınırlar ama istek SIKLIĞINI değil. **Düzeltme:** uygun `throttle:` ekle.

**F4 — Rapor/dashboard tarih aralığında MAX SPAN yok. 🔴 KAPATILDI.**
Düzeltme: `DateRangeResolver::MAX_WINDOW_DAYS = 366`, aşımda 422. **Ek bulgu (yürütmede
saptandı):** Carbon 3'ün `diffInDays()` metodu float döndürüyor; `endOfDay()` ile alınan diff
365.999… çıkıp off-by-one üretiyordu — tam 366 günlük geçerli bir aralık yanlışlıkla
reddediliyordu. `startOfDay()` ile diff alınarak düzeltildi. Kilitleyen test:
`tests/Feature/Security/ReportDateRangeTest.php`.
`app/Services/Reports/Support/DateRangeResolver.php:34-38` — `from`/`to` biçim doğrulanır ve
`from>to` reddedilir, ama üst sınır yok; `from=2000-01-01&to=2026-12-31` sınırsız aralık
agregasyonu tetikler. **Düzeltme:** makul üst sınır (ör. ≤366 gün; gerekiyorsa rapor tipine
göre) + 422.

**F5 — Sanitize edilmemiş `body_html` + `dangerouslySetInnerHTML` (düşük patlama yarıçapı, ŞİMDİ). 🔴 KAPATILDI.**
Düzeltme: sunucuda HTMLPurifier beyaz listesi + istemcide `dangerouslySetInnerHTML` KALDIRILDI,
yerine `<iframe sandbox="" srcDoc>` (izole render, `allow-scripts`/`allow-same-origin` birlikte
verilmedi — bkz. PROGRESS karar günlüğü 2026-08-25 #6). Kilitleyen testler:
`tests/Unit/HtmlSanitizerTest.php`, `tests/Feature/Security/EmailTemplateXssTest.php`.
`StoreEmailTemplateRequest` yalnız `['required','string','max:65535']`; sanitizasyon yok.
`frontend/src/features/settings/components/EmailTemplateFormModal.tsx:185`
`<div dangerouslySetInnerHTML={{ __html: bodyHtml }} />`. Şu an düşük: yalnız `settings.manage`
görebilir/yazabilir (self-XSS) ve posta gönderimi yok (`MAIL_MAILER=log`). **Ama** kalite
çizgisi "`dangerouslySetInnerHTML` yok" der; ileride `body_html` gerçek e-postaya veya düşük
yetkili önizleyiciye render edilirse stored XSS olur. **Düzeltme:** sunucuda HTML sanitize
(izinli etiket beyaz listesi) + `dangerouslySetInnerHTML`'i kaldır/izole et; kalite çizgisi
ihlalini gider.

**F6 — Türkçe İ/ı büyük-küçük harf katlaması bozuk (mevcut hata, i18n'den bağımsız). 🔴 KAPATILDI.**
Düzeltme: `app/Support/TurkishCase.php` + `frontend/src/lib/turkishCase.ts`, agresif katlama
(`İ`/`I`/`ı`/`i` → `i`). **Bilinen sınır (ölçüldü):** `utf8mb4_unicode_ci` collation `İ`=`i`
sayıyor ama `I`=`ı` SAYMIYOR; bu yüzden yalnız-isimle aranan `Irmak`/`ırmak` çifti SQL
ön-filtresinde takılıp PHP katlamasına hiç ulaşamıyor. Collation kapsam dışı (bu bölümün
kendisi zaten öyle diyor). Kapatma yolu: telefon eşleştirmede kullanılan "SQL'de garantili
üst küme + PHP'de kesin doğrulama" deseni isimlere de uygulanabilir — **Faz 15 adayı** olarak
kaydedildi (bkz. PROGRESS "Bir Sonraki Adım").
`app/Services/Leads/DuplicateDetector.php:407` (`sameText`, isim/firma karşılaştırması) ve
`:371` (`normalizeEmail`) `mb_strtolower` kullanıyor; `frontend/.../chat/components/MessageComposer.tsx:51`
mention filtresinde `.toLowerCase()` var. PHP `mb_strtolower` ve JS `toLowerCase` Türkçe kuralını
uygulamaz: `İ` → `i̇` (birleşik nokta), `I` → `i` (`ı` değil). Sonuç: "İhsan" vs "ihsan" duplicate
tespitinde eşleşmez; mention araması Türkçe adlarda ıskalar. **Bugün var olan bir hata** (i18n
bunu ortaya çıkarmıyor, yalnız görünür kılıyor). **Düzeltme (H8):** locale-duyarlı/deterministik
katlama. Kapsam dışı (bilinçli): `utf8mb4_unicode_ci` collation Türkçe sıralamayı doğru yapmaz —
büyük iş, bu fazda collation değişmez; bilinen sınır olarak §10.5'te not düşülür.

**SAFE doğrulananlar (recon):** mass assignment (`missing` kuralı deseni), yetki yükseltme
(Super Admin rolü `ROLE_NOT_EDITABLE`, `Gate::before` `null` döner, `CANNOT_REVOKE_OWN_SETTINGS_ACCESS`),
rapor SQL enjeksiyonu (`GroupByPeriod` + `resolveSort` whitelist), PDF escape (hep `{{ }}`),
sayfalama tavanı (`max:100`), CSV import upload (uuid + private disk + çift MIME), login
rate limiting (email+IP + artan bekleme), exception handler (trace sızmaz). `SecurityHeaders`
middleware'i **yok** (beklendiği gibi — bu fazda eklenecek).

---

## 4.1 Yürütmede Bulunan Yeni Açıklar (F7–F8)

> Ön bulgular (§4, F1–F6) kod OKUNARAK bulunmuştu. Aşağıdakiler denetim **YÜRÜTÜLÜRKEN**
> (gerçek istek atılarak) bulundu — recon'da görünmeyen, yalnız çalıştırınca ortaya çıkan sınıf.

**F7 (YÜKSEK) — Admin → Super Admin yetki yükseltmesi. 🔴 KAPATILDI.**
`UserService::update()` rol değişiminde `Gate::authorize('manageRoles')` çağırıyordu ama
`create()` çağırmıyordu; doğrudan `syncRoles([$role])`. `Admin` rolü `users.create` taşıyor ama
`users.manage_roles` TAŞIMIYOR. İstismar: Admin `POST /api/users` ile `role: "Super Admin"` +
kendi seçtiği şifreyle hesap açar, o hesaba girer, tam Super Admin olur. Canlı doğrulandı
(201 + gerçekten Super Admin).
**Kapatıldı:** `assertActorMayGrantRole()` — "rol tavanı": `users.manage_roles` yoksa
(a) `Super Admin` her hâlükârda yasak (yetkisi `Gate::before`'dan geldiği için izin kümesi boş,
alt-küme testi sahte geçerdi), (b) atanacak rolün izin kümesi aktörün etkin izinlerinin ALT
KÜMESİ olmalı; aksi 403. Düz "rol gönderirse 403" seçilmedi çünkü `role` alanı `required` ve bu
Admin'in `users.create` iznini tamamen işlevsiz bırakırdı. Konsol/seeder bağlamı (aktör yok)
muaf.
Test: `MassAssignmentTest::admin_without_manage_roles_cannot_mint_a_super_admin` +
aşırı-düzeltme koruması.

**F8 (ORTA) — `.assign` izninin `update` üzerinden atlatılması. 🔴 KAPATILDI.**
`/assign` uçları `deals.assign` istiyordu ama `PATCH /api/deals/{id}` (yalnız `deals.update`)
gövdede `owner_id` kabul ediyordu. Ölçülen etki: Satış Temsilcisi → deals/leads/tasks; Destek
Temsilcisi → tasks.
**Kapatıldı:** `Update{Deal,Lead,Task,Ticket}Request`'lerde `owner_id`/`assigned_to`
`['missing']` (422); create tarafında yeni `ForcesRecordOwnerOnCreate` trait'i `*.assign` izni
yoksa alanı `auth()->id()`'ye zorluyor.
Test: `MassAssignmentTest` + `OwnershipIsolationTest`.

**F9 (DÜŞÜK, yan bulgu) — `LeadConversionService` oluşturduğu deal'a sahip yazmıyordu.** 🔴 KAPATILDI.
Contact/company'ye yazıyordu, deal'a yazmıyordu. Sahipsiz lead'den doğan fırsat sahipsiz
kalıyor, yeni yazma kuralı (F8'in getirdiği sahiplik zorlaması) altında "herkesin yazabildiği"
kayıt olarak doğuyordu. Kapatıldı.

**F10 (DÜŞÜK, UI savunma katmanı) — `TicketStatusControl.tsx` hiçbir izin kontrolü taşımıyordu.** 🔴 KAPATILDI.
Salt-okuma rolüne durum geçiş kontrolü görünüyordu (backend zaten 403 veriyordu, yani sızıntı
yok). `usePermission('tickets.update')` + `ticket.can.status` ile bağlandı.

**F11 (BİLGİ) — `CustomFieldController::index` yetkilendirme çağrısı taşımıyor.** ⬜ KAPATILMADI (kabul edilen bilgi durumu.)
Kimliği doğrulanmış herkes herhangi bir `entity_type` için aktif özel alan TANIMLARINI
listeleyebiliyor. Müşteri verisi değil, şema metadata'sı; kapalı devre + davetle giriş nedeniyle
düşük. Karar: kabul edilebilir, ama kayda geçiriliyor (bkz. PROGRESS "Bir Sonraki Adım").

**F12 (DÜŞÜK) — Kanban her yüklemede yetkisiz kullanıcı için 403'e giden boşa istek atıyor.** ⬜ KAPATILMADI (Faz 15 adayı.)
İz B'nin elle kabul turunda saptandı (§3.1). Fırsatlar (Kanban) sayfası her yüklemede
`GET /api/users?per_page=100` çağırıyor; `users.view` izni olmayan rollerde (Satış Temsilcisi,
Destek Temsilcisi) bu istek **403** dönüyor. Zararsız — "Sahip" filtresi zarifçe gizleniyor,
kullanıcıya hata toast'ı gösterilmiyor — ama her pano açılışında boşa giden başarısız bir istek.
KAPATILMADI; Faz 15 adayı olarak kaydedildi (bkz. PROGRESS "Bir Sonraki Adım").

---

## 5. İZ C — Attio ANALİZİ (kabul/red kararı; özellik İNŞASI Faz 14'te)

> **Bu bölüm yalnız ANALİZ ve karardır.** §5.1'de KABUL edilen C1–C4'ün İNŞASI Faz 14'tedir
> (`docs/PHASE-INTL.md` §3, İz F). Faz 13 hiçbir Attio özelliği inşa etmez — yalnız neyin kabul/
> red edildiğine karar verir.

**Attio'yu klasik CRM'den ayıran mekanizma (pazarlama değil):** Sabit veri modeli yerine
kullanıcı-tanımlı **Object / Attribute / Record / List** dörtlüsü. Object = tablo şablonu;
Attribute = kolon (metin/sayı/para/seçim/tarih + **relationship** attribute = objeler arası
bağ, çift-yönlü otomatik yüzeye çıkar); List = objenin üstüne serilen kayıt grubu, **list'e
özel attribute** (ör. "Stage") yalnız o listede yaşar. Üstüne: no-code **Workflows**
(trigger+logic+action+AI blokları), email/calendar senkronu, email sequences, Insights/Funnel
raporları + "Segment by", **command palette** (kayıt/not/görev arası anlık arama), dış
**enrichment**, not/yorum/@mention işbirliği.

### 5.1 KABUL — bizim kapalı devre/Türkçe/tek şirket CRM'ine gerçekten değer katanlar

| # | Fikir | Nedir | Bizde nereye oturur / hangi şema | Maliyet | Neden ŞİMDİ / neden |
|---|---|---|---|---|---|
| C1 | **Global komut paleti + çapraz arama (Ctrl-K)** | Tüm modüllerde kayıt/anahtar arayıp anında atlama + hızlı aksiyon | Bizde global arama YOK; her modül kendi liste aramasını yapıyor. Mevcut repository `q` aramalarını tek `GET /api/search?q=` altında birleştirir (deal/lead/contact/company/quote/ticket/user), yetki filtreli | **M** | ŞİMDİ: en çok hissedilen eksik; mevcut arama altyapısının üstüne oturur, yeni şema gerektirmez |
| C2 | **Kayıtlı Görünümler (Saved Views)** | Filtre+sıra+kolon setini adlandırıp kaydet/paylaş; Attio'nun "list" fikrinin hafif hali | Liste ekranları zaten server-side `?page&sort&filter[]&q` (Faz 6 sözleşmesi). Yeni `saved_views` tablosu (`user_id`, `module`, `name`, `query_json`, `is_shared`) | **S/M** | ŞİMDİ: doğrudan mevcut liste altyapısının üstüne; günlük kullanımda yüksek kaldıraç |
| C3 | **Çift-yönlü "ilişkili kayıtlar" paneli** | Bir kaydın bağlı olduğu diğer kayıtları her iki yönden göster | Mevcut contact↔company, deal↔contact ilişkileri + `TimelineBuilder` üstüne kayıt detayında "ilişkili kayıtlar" bölümü; yeni tablo gerekmez | **S/M** | ŞİMDİ: Attio'nun relationship çift-yönlülüğünün kapalı-devreye uygun, düşük maliyetli özü |
| C4 | **Basit no-code otomasyon kuralları** | "X olduğunda Y yap" (sabit trigger+action kataloğu; keyfi kod değil) | Faz 10 zaten event/observer tabanlı tetikleme yapıyor; bunu kullanıcı-tanımlı kurala genelleştir (ör. "deal şu aşamaya gelince görev oluştur / şuna bildir"). Yeni `automation_rules` tablosu | **L** | Değer yüksek ama maliyet L. **Faz 14'te YALNIZ küçük, sabit-katalog sürümü** (2-3 trigger, 2-3 action) inşa edilir. Keyfi kod/AI YOK |

### 5.2 RED — Attio yapıyor ama biz YAPMAMALIYIZ (gerekçeli)

| Fikir | Neden RED |
|---|---|
| **Dış veri zenginleştirme (enrichment)** | Kapalı devrede dış sağlayıcıya çağrı imkânsız ve tasarım gereği yasak; anlamsız. Ayrıca müşteri verisini dışarı taşıyan bir sızıntı yüzeyi olurdu |
| **Email/calendar senkronu (Gmail/Outlook/Exchange)** | Kapalı devre, dış posta yok; şifre sıfırlama bile bilinçle yalnız admin-onaylı. Dış OAuth + token saklama yeni ve gereksiz bir tehdit yüzeyi |
| **Email sequences / dış kampanya** | Satış-outreach aracı değiliz; KVKK/spam + dış gönderim yüzeyi. Kapalı devre modeliyle çelişir |
| **AI blokları / "Ask Attio" / AI araştırma şeritleri** | Dış LLM çağrısı gerekir; kapalı devrede imkânsız. Müşteri verisini dış servise sızdıran en büyük yüzey; AI altyapısı yok |
| **Runtime keyfi custom OBJECT** (kullanıcı çalışma anında yeni tablo/tip açar) | Şemamız sabit, migration disiplinli, RBAC izinleri modül başına sabit, audit model-bazlı. Runtime DDL/EAV patlaması migration + RBAC + audit modelini kırar. Uzatma ihtiyacı **zaten** `custom_fields` (morph) ile karşılanıyor |
| **Public/misafir paylaşım, dış paydaş erişimi** | Kapalı devre yalnız-davet; dış yüzey tasarım gereği yok. Eklemek çekirdek güvenlik modelini deler |
| **Marketplace / 3. taraf app + dış webhook** | Kapalı devre; dışarı giden entegrasyon yok |

### 5.3 ZATEN VAR (yeni fikir diye sunulmadı)

Kanban (deals), takvim (tasks), presence/online listesi, bildirim merkezi + @mention (Faz 10/12),
not/aktivite timeline (`activities`), custom_fields + tags, rol/izin matrisi editörü, audit
trail (JSON diff), CSV/XLSX export, PDF, uçtan uca realtime. Bunlar Attio'da da var ama bizde
kurulu — kabul listesine alınmadı.

### 5.4 Faz 14'e devredilen güvenlik kısıtları

> §5.1 (kabul C1–C4) ve §5.2 (red) listesi ONAYLANDI; İNŞA Faz 14'te
> (`docs/PHASE-INTL.md` §3, İz F). Aşağıdakiler bu Faz 13 denetiminin İz C çıktısıdır ve
> Faz 14'teki inşanın **bağlayıcı** güvenlik kısıtlarıdır — sözleşme ihlal edilirse Faz 14
> kabul edilmez.

- **C1 global arama:** tek uç 7 modülü birleştirdiği için tek bir hata TÜM veriyi sızdırır.
  Sonuçlar modül bazlı `.view` izniyle filtrelenmeli ve bu bir feature testiyle kilitlenmeli;
  izinsiz modül sonuç kümesinde HİÇ görünmemeli (sayı/varlık sızıntısı dahil).
- **C2 kayıtlı görünümler (`is_shared`):** paylaşılan bir görünüm AÇAN kullanıcının yetkisiyle
  çalışmalı, OLUŞTURANIN yetkisiyle değil — aksi hâlde "confused deputy" ile yetki yükseltme
  olur. `query_json` sunucuda yeniden doğrulanmalı, ham filtre olarak sorguya gömülmemeli.
- **C4 otomasyon kuralları:** bir kural, onu OLUŞTURAN kullanıcının kendi yapamayacağı bir
  eylemi tetikleyememeli (ör. `deals.assign` izni olmayan biri "aşamaya gelince sahibi
  değiştir" kuralı yazamamalı). Tetikleyici ve eylem kataloğu sabit ve izin-eşlemeli olmalı;
  keyfi kod YOK.

---

## 6. Kabul Kriterleri (Faz 13)

- [x] §2 (A1–A8) matrisindeki her satır çalıştırıldı; sonuç ⬜/✅/🔴 olarak işaretlendi.
- [x] §4'teki 6 ön bulgunun (F1–F6) her biri düzeltildi **ve** düzeltmeyi kilitleyen bir
      regresyon testi (`tests/Feature/Security/`) yeşil.
- [x] Güvenlik header'ları (CSP, HSTS, X-Frame-Options: DENY, X-Content-Type-Options: nosniff,
      Referrer-Policy) her yanıtta doğrulandı (H1). `SecurityHeadersTest.php` ile kilitli.
- [x] IDOR turu: her `show/update/destroy/assign/status/move/convert` ucunda sahiplik/yetki
      testi var; çapraz-sahip erişim 403/404. `AuthzIdorTest.php`, `OwnershipIsolationTest.php`,
      `MassAssignmentTest.php` (F7/F8 dahil).
- [x] 8 broadcast kanalının her biri için yetkisiz-abone testi var ve `reverb` sürücüsüyle koşuyor.
      `ChannelAuthorizationTest.php`, `ChannelPayloadLeakTest.php`.
- [x] 6 rolün her biri için "görmemesi gerekeni görmüyor" turu (backend testi + elle) tamamlandı.
      5 rol (Admin, Satış Müdürü, Satış Temsilcisi, Destek Temsilcisi, İzleyici) UI'da CDP ile
      sürüldü (90/90 erişim hücresi doğru, bkz. §3.1); Super Admin backend testleriyle kapsandı
      (UI'da test edilemedi — şifre değişmiş, bkz. §3.1 kapsanmayan #1).
- [x] `composer audit` + `npm audit` temiz (veya açık kararı kayıtlı).
- [x] Attio §5 kabul/red listesi karara bağlandı (analiz çıktısı); kabul edilen C1–C4'ün İNŞASI
      Faz 14'e (`PHASE-INTL` §3) devredildi. **Bu fazda hiçbir Attio özelliği inşa edilmez.**
      §5.4'te Faz 14'e devredilen güvenlik kısıtları (C1/C2/C4) kayda geçirildi.
- [x] Tüm mevcut test suiti (Faz 12 sonrası) hâlâ yeşil; yeni testlerle birlikte sayı arttı.
      **1098 test / 8843 assertion, 0 hata** (Faz 12 sonunda 899 / 7558 idi).

> İz D (i18n) ve İz E (para birimi) kabul kriterleri Faz 14'tedir — bkz. `docs/PHASE-INTL.md` §4.

---

## 7. Sertleştirme İş Kalemleri (Bulgulardan Türeyen Somut Düzeltmeler)

| # | İş | Dosya(lar) | Kaynak |
|---|---|---|---|
| H1 | Güvenlik header middleware | `app/Http/Middleware/SecurityHeaders.php` (yeni) + `bootstrap/app.php` alias/append | Eski Faz 13 |
| H2 | CSV/XLSX formül nötrleme yardımcısı | `app/Support/CsvFormulaGuard.php` (yeni) + `LogQueryService`, `app/Exports/*` | F1 |
| H3 | `.env.example` `APP_DEBUG=false` | `backend/.env.example` | F2 |
| H4 | Export/import throttle | `routes/api.php` (import/logs-export/reports-export) | F3 |
| H5 | Tarih aralığı max span | `app/Services/Reports/Support/DateRangeResolver.php` | F4 |
| H6 | `body_html` sanitize + `dangerouslySetInnerHTML` giderme; upload sertleştirme + imzalı URL (Faz 12 dosya) | `StoreEmailTemplateRequest`/`UpdateEmailTemplateRequest`, `EmailTemplateFormModal.tsx`; chat upload servis/uçları | F5 + eski Faz 13 upload |
| H8 | **Türkçe İ/ı büyük-küçük harf düzeltmesi (ön bulgu, İz A):** `DuplicateDetector::sameText()` (`mb_strtolower` isim/firma eşleşmesi) ve chat mention filtresi `MessageComposer.tsx:51` (`.toLowerCase`) Türkçe `İ/I/ı` için sessizce eşleşmiyor — locale-duyarlı katlama | `app/Services/Leads/DuplicateDetector.php`, `frontend/.../MessageComposer.tsx` | F6 (§4) |

> Not: H2/H3/H4/H5 küçük, izole, çakışmayan dosyalar; H1/H6 sertleştirme çekirdeği; H8 mevcut hata düzeltmesi.
> **H7 (TCMB kur çekme / XXE sertleştirme) Faz 14'e taşındı** — surface o fazda doğar; bkz. `docs/PHASE-INTL.md` §2.5. H7 numarası orada korunur (referans tutarlılığı).

---

## 8. Paralelleştirme Planı (docs/ENGINEERING-RULES.md §3–4 uyumlu)

Genel kural: Teknik lider dosya değiştirmez — işi böler, sözleşmeyi yazar, çıktıyı
inceler; commit'i kullanıcı atar. En kritik parça teknik liderin; aynı anda ikinci eşit-kritik
parça varsa deneyimli şerit; hacimli/mekanik parçalar standart şerit. Aynı dalgada iki şeride asla aynı
dosya verilmez. Düzeltmeler aynı şeride iletilir. Hiçbir şerit Git çalıştırmaz.
**Contract-first:** güvenlik testleri `tests/Feature/Security/` altında; her düzeltmenin uç/
alan sözleşmesi (§7 dosya sahipliği) dispatch'ten önce sabit.

| Dalga | Kritik parça (teknik lider) | 2. kritik (deneyimli şerit) | Hacimli (standart şerit, çakışmayan dosya sahipliği) |
|---|---|---|---|
| **W1 — Denetim/keşif** | Tehdit modeli doğrulama + §2 matris yürütme kararları + IDOR/authz/kanal turu (inceleme teknik liderde) | — (tek beyin; bulgular teknik liderde toplanır) | S1: A1/A2/A8 için feature test iskeleti (`tests/Feature/Security/AuthzTest`, `SessionTest`). S2: A3 kanal testleri (`reverb` sürücüsü). S3: 6-rol kabul turu betikleri + elle checklist |
| **W2 — Sertleştirme** (bulgular onaylandıktan sonra) | H1 (SecurityHeaders + CSP politikası) + H6 sanitizasyon/upload çekirdeği (güvenlik-kritik kararlar) | H6'nın ikinci yarısı: Faz 12 chat upload sertleştirme + imzalı URL (BE+FE sözleşmesi) — teknik lider H1/sanitize ile meşgulken paralel | S1: H2 CsvFormulaGuard + export/exports entegrasyonu. S2: H3 `.env.example` + H4 throttle + H5 date-range cap. S3: her düzeltme için regresyon testi |
| **W3 — Attio özellikleri** (bağımsız; W1/W2'ye paralel başlayabilir) | C1 arama sözleşmesi + C4 otomasyon kuralı kapsam kararı (küçük tut) | — | S1: C1 `GET /api/search` + FE komut paleti. S2: C2 `saved_views` tablosu + BE/FE. S3: C3 ilişkili-kayıt paneli (FE + varsa hafif BE ucu) |
| **W4 — i18n (İz D)** (kabul turundan ÖNCE biter) | i18n mimarisi: kütüphane kurulumu, namespace şeması, anahtar sözleşmesi, notifications key+param refactor sözleşmesi | Backend `lang/*` + `App::setLocale` middleware + notifications okuma-anı çözümü (BE) | S1: FE sözlük çıkarımı (~1.100 dize, 18 feature) tr+en. S2: enum/validation/error anahtarları + de/fr çevirileri. S3: money.ts/tarih locale parametreleme + dil seçici + README EN/tr split |
| **W5 — Para birimi (İz E)** (bağımsız; W4 money.ts'de kesişir — sözleşme önce) | Kur veri modeli + TCMB kaynak/rate seçimi + rapor toplama stratejisi (donmuş base_amount) + XXE/giden-çağrı sertleştirme kararı | Rapor/dashboard toplama refactor (`app/Services/Reports/*`) — karışık para birimi (BE) | S1: `exchange_rates` göç + `FetchTcmbRates` komut + Ayarlar manuel giriş. S2: teklif donmuş kur (`sent`) + PDF kur/tarih satırı. S3: FE para birimi seçici + görüntüleme dönüşümü (money.ts sözleşmesine bağlı) |

**Dosya sahipliği (çakışmasız):** güvenlik testleri yalnız `tests/Feature/Security/*`; H1
yalnız `SecurityHeaders.php` + `bootstrap/app.php`; H2 yalnız `CsvFormulaGuard.php` +
`LogQueryService`/`app/Exports/*`; H3/H4/H5 ayrı dosyalar; Attio C1–C4 yeni `search`/
`saved_views`/`automation_rules` yüzeyleri mevcut modül dosyalarına dokunmaz. Route eklemeleri
(`routes/api.php`) tek elden (teknik lider sözleşmesi) yapılır; şeritler controller/service/
test dosyalarını yazar.

**Sıralama:** W1 (bul) → W2 (sertleştir); W2'deki düzeltmeler W1'in doğrulanmış bulgularına
bağlıdır (sahte paralellik yapma). W3 (Attio), W4 (i18n) ve W5 (para birimi) **birbirinden ve
İz A'dan bağımsızdır** — W1 ile aynı anda başlayabilir; farklı dosya sahipliği (İz A güvenlik
testleri + BE sertleştirme; İz D `frontend/src/i18n/*` + `backend/lang/*`; İz E yeni
`exchange_rates`/`app/Services/Exchange/*` + kur göçü). **Tek zorunlu sıra: W4 (i18n çekirdeği:
sözlük + tr/en + dil seçici) → İz B (6-rol kabul turu)** — kabul turu çevrilmiş UI'ı sınamalı
(§3). **İki kesişim noktası, sözleşme-önce çözülür:** (1) `frontend/src/lib/money.ts` hem İz D
(ayraç = locale) hem İz E (para birimi ekseni + görüntüleme dönüşümü) tarafından değişir —
imzası (`formatMoney(amount, currency, { locale, displayCurrency?, rate? })`) dispatch'ten önce
sabitlenir, tek şerit yazar; (2) `resources/views/pdf/quote.blade.php` hem İz D (statik etiket
lang'e) hem İz E (kur/tarih satırı) tarafından değişir — tek şerit, birleşik değişiklik.

---

## 9. Özet — Faz 13 ↔ Faz 14 ↔ Faz 15 Sınırı

- **Faz 13 (bu doküman):** Kırmızı takım (İz A) + 6-rol kabul turu (İz B) + F1–F6 kapatma +
  H1–H6/H8 sertleştirme + güvenlik regresyon testleri + **Attio ANALİZİ/kararı (İz C, §5)**.
- **Faz 14 (`docs/PHASE-INTL.md`):** i18n (tr/en/de/fr) + README İngilizce (İz D) + çoklu para birimi
  + TCMB güncel kur (İz E) + **Attio kabul edilen özelliklerin İNŞASI C1–C4 (İz F)**. XXE/giden-çağrı
  sertleştirme (H7) ve TCMB XML testi (A5.8) **yalnız orada** tarif edilir — surface o fazda doğar.
- **Faz 15 (Teslim & Final):** İşlevsel feature/E2E test kapsamı tamamlama, README final (kurulum +
  API endpoint listesi + ER — iki dilde), **Faz 14 sonrası kısa yeniden-kabul turu** (PHASE-INTL §6),
  Bölüm 6 global kabul kriterleri son turu, teslim. Güvenlik header/upload/IDOR/mass-assignment
  İŞLERİ Faz 13'te — Faz 15'te TEKRAR TARİF EDİLMEZ.
