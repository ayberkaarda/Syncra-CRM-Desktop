# DESKTOP-THREAT-MODEL — Faz 6: Syncra Desktop STRIDE Tehdit Modeli

> **Statü: F6 GÜVENLİK ÇIKTISI (canlı doküman).** `SYNCDESKTOP.md` §9'un son maddesinin
> ("`docs/DESKTOP-THREAT-MODEL.md` — STRIDE tablosu, `PHASE-AUDIT.md` formatı") teslimatıdır.
> Biçim ve şiddet skalası `docs/PHASE-AUDIT.md`'den alınmıştır (YÜKSEK / ORTA / DÜŞÜK / BİLGİ;
> bulgu numaralandırması TM-F*). İçerik kopyalanmamıştır — o doküman tamamlanmış **web**
> ürününün denetimidir, bu doküman **masaüstü istemci + sync API** yüzeyini modeller.
>
> İlgili sözleşmeler: `SYNCDESKTOP.md` (§4.3, §4.4/A29, §6.3, §6.4, §9, K7, K9, K10),
> `docs/DESKTOP-SYNC-PROTOCOL.md` (§2.7, §2.8, §3, §8), `docs/DESKTOP-ARCHITECTURE.md`
> (§5, EK 1/2/3, kararlar A20–A24), `docs/AUTH-FLOWS.md`, `docs/DESKTOP-OPEN-ITEMS.md` (defter).
>
> **Doğrulama yöntemi (PHASE-AUDIT geleneği):** Her iddia `dosya:satır` ile desteklenir.
>
> **Sürüm 1 — 2026-08-31, HEAD `35da69b`.** F1–F3 yüzeyi; canlı kanıt toplandı: gerçek
> `$APPDATA` dizin dökümü, DB dosyasının ilk 16 baytının ham okuması, çalışan uygulamanın
> ürettiği log dosyası (§3 madde 3-4-9).
>
> **Sürüm 2 — 2026-09-01, HEAD `86f6388` (bu revizyon).** F4 ve F5 arada tamamlandı; §0'ın
> "F5 kod olarak yoktur" varsayımı **düştü**, sekiz OS yüzeyi artık gerçek koddur ve
> modellenir. Bu turda **statik doğrulama** yapıldı (dosya:satır, test adı); **canlı
> ölçüm tekrarlanmadı** — 2026-08-31 canlı kanıtları o tarihin ağacına aittir ve bu
> revizyonda yeniden koşulmamıştır. Bu ayrım her madde başında açıkça yazılıdır.
>
> Doğrulanamayan hiçbir şey "sağlanıyor" olarak yazılmadı; ölçülemeyenler açıkça
> **DOĞRULANMADI** / **ÖLÇÜLMEDİ** etiketi taşır.

---

## 0. Kapsam Kuralı — Bugünkü Gerçek Hâl

Bu model sistemin **2026-09-01 itibarıyla var olan** hâlini analiz eder: F1 (backend sync +
device auth), F2 (`syncra-sync` crate), F3 (Tauri kabuğu + veri katmanı), F4 (offline UX) ve
F5 (OS özellikleri) tamamlanmış; **F6 (bu belge) sürüyor, F7 (paketleme + updater)
yazılmamıştır.**

**2026-08-31'de "DEĞERLENDİRİLEMEZ-F5" olan yedi yüzey artık gerçek koddur** ve bu
revizyonda modellenmiştir: tray (`src-tauri/src/tray.rs`), quick-capture hotkey
(`quick_capture.rs`), deep link (`deep_link.rs` + `desktop/src/bridge/deeplink-routes.ts`),
drag-drop + PDF cache + screenshot (`commands/files.rs`), clipboard opt-in
(`clipboard.rs`), native bildirim (`notification` plugin'i), autostart/window-state
(`lib.rs:89-108`). Geriye **yalnız updater (F7)** DEĞERLENDİRİLEMEZ kalır.

Aynı ilke ters yönde de uygulanır: var olmayan bir koruma "var" sayılmaz; **yapılacak iş
yapılmış gibi yazılmaz.** Bu revizyonda düzeltilmemiş üç şey (§4.6/b id kayması, §4.7 ikinci
`is_image` tanımı, §4.9 şifresiz cache) açıkça **AÇIK / KABUL EDİLDİ** işaretlidir.

**Sistem sınırı varsayımları (PHASE-AUDIT §1 ile aynı):** kapalı devre, yalnız davetle, dış
anonim yüzey kasıtla küçük. Masaüstünün eklediği fark: **veri artık kullanıcının makinesine
iner** (çalınan laptop senaryosu doğar) ve **kalıcı bir bearer kimlik bilgisi** vardır
(cookie oturumunun aksine tarayıcı kapanınca ölmez).

**Baş aktörler (olasılık sırasıyla):**
1. Kimliği doğrulanmış, düşük yetkili içeriden kullanıcı — artık elinde resmî bir API
   istemcisi (masaüstü) ve onun token'ı var; istekleri kendi eliyle de üretebilir.
2. Cihaza fiziksel/uzaktan erişen üçüncü kişi (çalınan/paylaşılan makine) — hedefi lokal DB,
   keychain, log dosyaları.
3. Webview içi XSS taşıyıcısı — web'dekiyle aynı sınıf, ama masaüstünde IPC yüzeyine ve
   bellek içi token'a komşu.
4. Ağ üzerindeki aktif saldırgan — kapalı devrede düşük olasılık; TLS bu modelde işletim
   ortamı sorumluluğudur (dev ortamı bugün `http://localhost`).

---

## 1. Varlıklar ve Güven Sınırları

### 1.1 Korunan varlıklar

| # | Varlık | Nerede yaşıyor | Kanıt |
|---|---|---|---|
| V1 | **Cihaz token'ı** (Sanctum PAT, ability `desktop`) | Sunucuda hash'li (`personal_access_tokens`); istemcide **yalnız OS keychain** (Windows Credential Manager, servis `syncra-desktop`, girdi `device-token`) | üretim: `backend/app/Services/Auth/DeviceTokenService.php:118`; saklama: `desktop/crates/syncra-sync/src/keystore.rs:16,35-66`; canlı kanıt: `Syncra.log`'daki `WinCredential { username: "device-token", target_name: "device-token.syncra-desktop" }` satırları |
| V2 | **SQLCipher DB anahtarı** (64 hex) | Yalnız keychain (`db-key` girdisi); ilk açılışta CSPRNG'den üretilir | `keystore.rs:18,98-123` |
| V3 | **Lokal DB** (`syncra.db` + WAL/SHM) — CRM aynası, outbox, conflict store, cursors, ayarlar | `$APPDATA` → gerçekte `C:\Users\<u>\AppData\Roaming\com.syncra.desktop\syncra\` | canlı döküm §3/4; şifreli açılış `desktop/crates/syncra-sync/src/db/mod.rs:25-48` |
| V4 | **Outbox** (gönderilmemiş mutasyonlar) | V3'ün içinde (`outbox` tablosu) — ayrı dosya değil | `desktop/crates/syncra-sync/migrations/0001_init.sql` |
| V5 | **Sunucu verisi** — sync API'nin sunduğu her tablo | MariaDB; erişim beş katmanlı middleware zinciri arkasında | `backend/routes/api.php:123-136` |
| V6 | **Audit bütünlüğü** — desktop kaynaklı yazmaların inkâr edilemezliği | `activity_log` (`properties.channel='desktop'` + `batch_id`), `session_logs` (`channel='desktop'`) | `backend/app/Sync/SyncActivityContext.php:10`, `SyncPushService.php:113-142`, `DeviceTokenService.php` (`logDeviceLogin`) |
| V7 | **İzin matrisi** — manifest'teki efektif izinler | `GET /api/sync/manifest` yanıtı | `backend/app/Http/Controllers/Api/Sync/ManifestController.php:58` |
| V8 | **Cache blob'ları** (F5) — teklif PDF'leri, drag-drop/screenshot kuyruğu | `$APPDATA/syncra/cache/{quotes,attachments}` — **düz dosya, şifresiz** (`std::fs::write`) | `desktop/src-tauri/src/commands/files.rs:14-16,79,89,662-686`; wipe kapsamı `crates/syncra-sync/src/sync/mod.rs:183-200` (O67). Bkz. §2/I7 ve §4.9 |
| V9 | **Pano içeriği** (F5-6, opt-in) | Hiçbir yerde saklanmaz — yalnız `u64` parmak izi bellekte | `desktop/src-tauri/src/clipboard.rs:17-22,127-140`; §2/I8 |

### 1.2 Güven sınırları

```
[İnternet/LAN] ──TLS(prod)── [Laravel API + Reverb]          ← SINIR 1: ağ / sunucu
                                    ▲ bearer
[Rust çekirdeği: syncra-sync + Tauri komutları]              ← SINIR 2: webview / native
   │ rusqlite (proses içi)      ▲ invoke() = ipc://localhost
[SQLCipher DB dosyası]      [WebView2 (React UI)]
   ▲ PRAGMA key                     │ CSP: tauri.conf.json:28
[OS keychain]                       └ origin: http://tauri.localhost
[$APPDATA/syncra/cache/** — ŞİFRESİZ blob'lar (V8)]
                                                              ← SINIR 3: uygulama / OS
[Diğer lokal prosesler, diğer OS kullanıcıları, disk hırsızlığı]
   │ syncra:// url (argv / Apple event)      │ pano (opt-in)
   ▼                                         ▼
[deep_link::parse_deep_link]            [clipboard::detect]     ← SINIR 3'ün GİRİŞ yönü (F5)
```

> **SINIR 3 artık çift yönlüdür.** F5'ten önce SINIR 3 yalnız bir *sızıntı* sınırıydı
> (veri dışarı). Bugün OS'ten uygulamaya **veri giren** iki kanal var: `syncra://` deep
> link (kabuğun argv'sine herhangi bir lokal proses yazabilir — `Start-Process "syncra://…"`)
> ve opt-in pano yoklaması. İkisi de ayrıştırılmamış girdi kabul eden birer *parser*
> yüzeyidir; §2/S5, §2/E4 ve §2/I8'de modellenir.

- **SINIR 1 (istemci ↔ sunucu):** İstemcinin söylediği hiçbir şeye güvenilmez. Push'ta her
  mutasyon sunucuda FormRequest kuralları + Policy + horizontal boundary'den geçer
  (`backend/app/Services/Sync/MutationApplier.php:256,333,407,500,521` — `Gate::forUser($actor)->authorize(...)`).
  Pull'da tablo listesi modül `.view` iznine (`backend/app/Services/Sync/SyncScope.php:45-59`),
  dört tabloda satır kapsamına (`SyncScope.php:70-91`) tabidir.
- **SINIR 2 (webview ↔ Rust):** Webview'a ham SQL verilmez (`NamedQuery` beyaz listesi,
  `SYNCDESKTOP.md` §5.2); IPC `connect-src` politikasına tabidir. Kalıcı sırlar (V1, V2)
  Rust tarafında kalır; webview'ın bellek içi token kopyası için bkz. TM-F7.
- **SINIR 3 (uygulama ↔ OS):** SQLCipher + keychain, **cihaz çalındığında / disk imajı
  alındığında** veriyi korur. Aynı OS kullanıcı hesabı olarak çalışan kötücül yazılıma karşı
  koruma **iddia edilmez**: Windows Credential Manager girdileri aynı kullanıcının
  proseslerine açıktır. Bu, K9'un bilinçli sınırıdır — OS hesabı ele geçirilmişse oyun
  zaten kaybedilmiştir (PHASE-AUDIT §1 "kapsam dışı" ile tutarlı).

---

## 2. STRIDE Tablosu

Durum sözlüğü: **KAPALI** = kontrol var ve teste/kanıta bağlı · **AÇIK** = artık risk
kabul edilmemiş, iş gerekiyor · **KABUL EDİLDİ** = risk ölçüldü, karara bağlandı ve
bilinçle düzeltilmedi (gerekçe §4'te) · **DEĞERLENDİRİLEMEZ-F7** = yüzey henüz yok, o fazda
yeniden ele alınacak. *(2026-09-01: `DEĞERLENDİRİLEMEZ-F5` etiketi bu tabloda artık
kullanılmıyor — F5 yazıldı, yüzeyleri satır satır modellendi.)*

| # | Kategori | Saldırgan / senaryo | Yüzey | Bugünkü kontrol (kanıt) | Artık risk | Durum |
|---|---|---|---|---|---|---|
| S1 | Spoofing | Anonim: `POST /api/auth/device` üzerinden brute-force / hesap numaralandırma | Cihaz login ucu | Web login ile **paylaşılan** keyed lockout (email+IP, 5 deneme, 1→2→…→60 dk eskalasyon) — `DeviceTokenService.php:147-197`; hata mesajları web ile birebir aynı (numaralandırma önlenir, sınıf yorumu satır 42-49); testler `tests/Feature/Sync/DeviceTokenTest.php:133,160,182` | Dağıtık IP'lerden yavaş deneme (web ile aynı kalıntı) | KAPALI |
| S2 | Spoofing | İçeriden: cookie oturumu ile sync uçlarına erişim (TransientToken zaafı, protokol §3.3 K-A) | `/api/sync/*` | `EnsureDeviceToken` gerçek `PersonalAccessToken` sınıfını şart koşar (`backend/app/Http/Middleware/EnsureDeviceToken.php:42-59`), alias kayıtlı (`bootstrap/app.php:191,200`); test `DeviceTokenTest.php:263` (bilinçli `actingAs()` ile zaafı yeniden üretip 403 doğrular) | — | KAPALI |
| S3 | Spoofing | İçeriden: sahte `device_fingerprint` beyanı | Cihaz login ucu | Biçim doğrulaması `size:64` + hex (`Http/Requests/Auth/DeviceTokenRequest.php:38`); eski-token silme **yalnız kendi token'larına** kapsamlı (`DeviceTokenService.php:114-116` — "fingerprint is a device identifier, not an authorisation") | Kendi hesabı için cihaz-başına-tek-token kuralı atlatılır → token bolluğu (bkz. TM-F4). Başka hesabın token'ına dokunamaz | AÇIK (DÜŞÜK) |
| S4 | Spoofing | Ağda: sahte update manifest'i | Updater | Bugün updater fiilen yok: `tauri.conf.json`'da artık bir `plugins` bloğu **var** ama içinde yalnız `deep-link.desktop.schemes` duruyor — **`plugins.updater` yok**; plugin `#[cfg(not(debug_assertions))]` arkasında (`desktop/src-tauri/src/lib.rs:81-84`) ve `pubkey` zorunlu/varsayılansız olduğu için bloksuz release build hiç açılmaz (fail-closed, `lib.rs:67-80` yorumu). `capabilities/default.json`'daki `updater:default` izni bu yüzden **bugün ölü bir izindir** — arkasında kayıtlı plugin yok | İmza doğrulama akışı F7'de kurulacak ve o zaman test edilecek. Kalıntı: capability önden verilmiş, plugin F7'de gelince izin de aynı anda **canlanır** — F7 bunu ayrıca gözden geçirmeli | DEĞERLENDİRİLEMEZ-F7 |
| S5 | Spoofing | Lokal proses: `syncra://` linkiyle kullanıcıyı **başka bir kayda** yönlendirmek (linkte görünen id ≠ açılan id) | Deep link | Entity url'in **host**'udur ve hiçbir normalizasyon host'a ulaşmaz: `deal/../lead/1` host'u `deal` bırakır, `setting/../deal/42` allowlist'e takılır, `DEAL/42` küçük harfe katlanmadığı için reddedilir (`deep_link.rs:170-190`; test `normalisation_can_change_the_id_but_never_the_entity`, `the_normalisation_families_are_pinned`) | **`id` kayabilir:** `syncra://deal/1/../2` → deal **2**; `deal/9999999999999/../42` → deal 42 (regex'in reddedeceği 13 haneli segment sonrakiyle siliniyor). Ulaşılabilen küme = aynadaki kayıtlar, yani `SyncScope`'un pull anında zaten filtrelediği satırlar → yetki kazanımı yok, yalnız yanıltma | **AÇIK (DÜŞÜK) — KABUL EDİLDİ**, §4.6/b |
| T1 | Tampering | Fiziksel erişim: DB dosyasını değiştirme/okuma | `syncra.db` | SQLCipher — `PRAGMA key` ilk ifade (`db/mod.rs:38`), yanlış anahtar gürültülü hata (`db/mod.rs:41-43`, test `tests/encryption.rs:53-62`); anahtar keychain'de | Aynı OS hesabındaki kötücül proses keychain'i okuyabilir (SINIR 3, kabul edilmiş) | KAPALI |
| T2 | Tampering | İçeriden: outbox'u/lokal DB'yi elle değiştirip sahte mutasyon push'lamak | Push ucu | Sunucu istemci beyanına güvenmez: her mutasyonda Policy + FormRequest + horizontal boundary (`MutationApplier.php:256,333,407,500,521`); `changed_fields` dışı alan yazılmaz; yasak alanlar 422 (`SyncPushTest.php` matrisi) | Kullanıcı kendi YAPABİLDİĞİ işlemleri farklı bir istemciden yapmış olur — yetki kazanımı yok | KAPALI |
| T3 | Tampering | Şema evrimi: FK `ON DELETE CASCADE` sessiz veri kaybı (protokol §2.8, probe D3: cascade ne trigger ne Eloquent event tetikler) | `sync_deletions` bütünlüğü | İki katmanlı mimari kilit: `DELETE_RULE` envanteri `assertSame` ile sabit (`tests/Feature/Sync/SyncSchemaTest.php:62,104`), cascade ebeveynlerine hard-delete yolu yokluğu assert'li (`SyncSchemaTest.php:146`) | Bu bir **veri kaybı** riskidir, sızıntı değil; bugün yalnız soft delete sayesinde uykuda. `forceDelete`/KVKK purge eklenirse test kırmızıya düşer ve tasarım kararı zorlar | KAPALI (kilitli) |
| T4 | Tampering | Yanlış/eksik `.env` ile üretilen CSP | Build zinciri | CSP `frontend/.env`'den build-time üretilir (`desktop/scripts/tauri.mjs:46-97`), frontend bundle ile aynı kaynak/aynı öncelik; `https://crm.example.com` ile doğrulanmış (ARCHITECTURE EK 2) | `VITE_API_URL` yok/bozuksa sessiz `http://localhost:8000` fallback'i (`tauri.mjs:67`) — bkz. TM-F6 | AÇIK (BİLGİ) |
| R1 | Repudiation | İçeriden: "o işlemi ben yapmadım / masaüstünden yapılmadı" | Audit | Cihaz login'i `session_logs`'a `channel='desktop'` + cihaz adı + IP yazar (`DeviceTokenService.php` `logDeviceLogin`, test `DeviceTokenTest.php:79`); her applied mutasyon `activity_log`'a `causer` = token sahibi, `properties.channel='desktop'`, `batch_id` damgalar (`app/Sync/SyncActivityContext.php:10`, `SyncPushService.php:113-142`) | Audit satırı yazılamazsa login yine başarılı (bilinçli takas, `DeviceTokenService.php` yorum) — web listener'larıyla aynı | KAPALI |
| I1 | Info disclosure | İçeriden: izinsiz modülün verisini pull'lamak | Pull ucu | Modül `.view` yoksa tablo manifest'te ve pull'da **hiç yok** (`SyncScope.php:45-59`, `GlobalSearchService` ilkesi); satır kapsamlı 4 tablo: notifications sahibe, conversations/messages üyeliğe, saved_views sahibi+paylaşım, settings yalnız `is_public` (`SyncScope.php:70-91` — "stolen laptop should not carry" yorumu) | `sync_deletions` istisnası → I2 | KAPALI |
| I2 | Info disclosure | İçeriden: **başkasının silinmiş bildirim uuid'lerini görmek** | Pull `deletions` dizisi | YOK — `deletionsFor()` yalnız `conversation_user` için kapsam uygular (`SyncPullService.php:304-347`, kapsam 330-334); `notifications` tombstone'ları tabloya erişimi olan (izin `notifications.view`, `SyncableRegistry.php:118-121`) **her kullanıcıya** döner; şema sahip kolonu taşımaz (`migrations/2026_09_01_100002_...:26-36`), satır silindiği için sahibi geriye dönük çözülemez | Sızan şey yalnız **varlık** (uuid + sync_version) — içerik, tip, atıf yok. Yine de kapsam ilkesinin (I1) deliğidir. **2026-08-31: KAPANDI** — `sync_deletions.owner_key` eklendi (`SyncDeletionObserver.php:51`) ve `SyncPullService.php:542` sahibin **eşleşmesini** şart koşuyor; soldaki "YOK" değerlendirmesi kapanıştan öncedir | ✅ KAPALI (TM-F2) |
| I3 | Info disclosure | Fiziksel erişim: disk imajından CRM verisi | `syncra.db`, WAL | Şifreli dosya; başlıkta `SQLite format 3` yok — **canlı doğrulandı** (§3/3) ve regresyon testi düz metin satır sızıntısını da tarar (`tests/encryption.rs:17-48`) | WAL/SHM de SQLCipher kapsamında (aynı dosya ailesi); `wipe()` DELETE tabanlıdır ama boşalan sayfalar da şifrelidir | KAPALI |
| I4 | Info disclosure | Fiziksel erişim: diskte düz **token/anahtar** dosyası | `$APPDATA`, `$LOCALAPPDATA` | Dizin dökümü (§3/4, 2026-08-31): `syncra.db(-wal/-shm)` dışında dosya yok; token/anahtar dosyası yok; sırlar Credential Manager'da | Log dosyası keyring **girdi adlarını** yazıyor (değerlerini değil) → TM-F3'ün parçası. **2026-09-01 kapsam daralması:** bu satır artık yalnız *sır* dosyalarını kapsar — F5 aynı köke şifresiz **içerik** blob'ları yazıyor, ayrı satır I7 | KAPALI (sırlar için) |
| I5 | Info disclosure | Webview XSS: bellek içi token'ı sızdırmak | `desktop/src/platform/http.ts` | Token webview'da **yalnız bellekte** tutulacak şekilde tasarlı (`http.ts:9-23`), kalıcı kopya keychain'de; CSP `connect-src` yalnız kendi API/Reverb origin'i (`tauri.conf.json:28`) → sızdırma kanalı dar; kalite çizgisi `dangerouslySetInnerHTML` yasağı (PHASE-AUDIT F5 kararı) | **2026-08-31: yüzey artık canlı.** `setDeviceToken()` `platform/auth.ts:191` üzerinden besleniyor (WIRE-1); ayrıca aynı turda webview'daki `localStorage` oturum kopyası **kaldırıldı** ve eski kurulumların yazdığı `purgeLegacySessionCache()` ile siliniyor — yani XSS'in ulaşabileceği kalıcı kopya yok, token yalnız bellekte. TM-F7 buna göre yeniden değerlendirilmeli | AÇIK (BİLGİ, izlenecek — yüzey doldu) |
| I6 | Info disclosure | `sync_idempotency.result_json` içinde sunucu satırları | Sunucu DB | Kullanıcı bazlı anahtar; `logs:prune` 7 günde budar (`app/Console/Commands/PruneLogs.php:184-190`); tabloya API yüzeyi yok | — | KAPALI (BİLGİ) |
| I7 | Info disclosure | Fiziksel erişim: **şifresiz cache blob'ları** (teklif PDF'leri, drag-drop/screenshot kuyruğu) | `$APPDATA/syncra/cache/**` | Kısmi: `wipe_local()` blob'ları da siliyor (`sync/mod.rs:183-200`, O67 — üç wipe yolunda da), `open_cached` canonicalize + kök + uzantı kontrolü yapıyor (`files.rs:438-467`), retention ledger'ı (`cached_files`) tavan uyguluyor | **Blob'lar SQLCipher DIŞINDA, düz `std::fs::write` ile yazılıyor** (`files.rs:662-686`). T1/I3'ün "çalınan laptop" garantisi bu dosyaları **kapsamaz**: gönderilmiş bir teklif PDF'i ya da kuyruktaki ek, disk imajından doğrudan okunur | **AÇIK (ORTA)** — §4.9, TM-F13 |
| I8 | Info disclosure | Pano yakalama: kopyalanan içeriğin log'a/diske/bildirime sızması | `clipboard.rs` | Dört katmanlı: opt-in **varsayılan kapalı** ve kabuk kendi başına açmıyor (testler `clipboard_capture_is_off_by_default`, `nothing_in_the_shell_enables_capture_on_its_own`); bildirim metni panodan **parametre almıyor** (`the_notification_cannot_carry_clipboard_content`); değişim algılayıcı metni değil `u64` parmak izini tutuyor (`the_change_detector_keeps_a_number_not_the_text`); modülde hiçbir `fs::write`/`File::create`/`tracing::` çağrısı yok — **kaynak seviyesinde** assert edilmiş (`no_log_or_file_write_can_reach_the_clipboard_text`, `clipboard.rs:370-405`) | Pano webview'a hiç açılmıyor: `clipboard-manager:allow-read-text` capability'de **yok** ve okuma Rust'tan yapılıyor (`default.json` açıklaması) — capability vermek+kapılamaktan dar bir yüzey | KAPALI (§9/6 kanıtı) |
| I9 | Info disclosure | Mesaj eki **metadata**'sının aynaya inmesi (KARAR A29) | `messages` pull satırı | Metadata yalnız `SyncScope::applyRowScope`'un üyelik filtresinden geçmiş satırlara ekleniyor (`SyncScope.php:82` → `conversationIds()` `:119-129`, `conversation_user` pivotu); silinmiş mesaja **hiç** eklenmiyor (`SyncPullService.php:470,490` — `MessageResource` maskesiyle parite); baytlar senkronlanmıyor | Sunucu yüzeyine göre **yeni sızıntı yok** (§4.8 doğrulaması). Ama parite `AttachmentPolicy` ile değil `MessageResource`/`ChatAttachmentResource` **ile**dir; A29 metninin "AttachmentPolicy ile birebir aynı" ifadesi **doğrulanmadı ve yanlış çıktı** — §4.8/2 | KAPALI (sızıntı yok) + §4.8 düzeltme notu |
| D1 | DoS | İçeriden: sync uçlarını döngüye sokmak | Sync API | `throttle:30,1,sync` (manifest+pull), `throttle:20,1,sync-push` (`routes/api.php:127-135`); push batch ≤200 mutasyon ≤2 MB, pull yanıtı 5 MB kesme (şartname §4.4, `SyncPushTest`/`SyncPullTest` matrisi) | `sync_counter` küresel mutex'i (K-B) altında saldırgan yazma dalgası diğer yazarları kilit beklemesine sokabilir; P4a retry + throttle bunu sınırlar — ölçülmüş bir sorun değil | KAPALI |
| D2 | DoS | Kendi kendine: lokal disk şişmesi | Lokal DB | K8 tavanları + `retention_maintenance()` + `WriteBlocked` (crate, `SYNCDESKTOP.md` §5.6; `SyncRetentionTest.php` sunucu tarafı) | — | KAPALI |
| E1 | Elevation | İçeriden: `can.*` istemci bayraklarını `true` yapıp yetkisiz yazma | Lokal veri katmanı | Bayraklar **zaten** permissive `true` (KARAR A22, `desktop/src/platform/data/mappers.ts:30-33,190,330,388,406,496`) çünkü otorite istemci değil: push'ta sunucu Policy'leri reddeder (A14 3. katman, `MutationApplier.php` Gate çağrıları). İstemci tarafı izin **hiçbir zaman güvenlik kontrolü değildir** | Bedel güvenlik değil UX: red, push anında görünür; Conflict Inbox'un bunu anlaşılır göstermesi F4 yükümlülüğü (EK 3 A22) | KAPALI (tasarım gereği) |
| E2 | Elevation | İçeriden/webview: Tauri capability'leri üzerinden OS'e taşma | IPC / plugin yüzeyi | **2026-09-01 hâli** (`capabilities/default.json`, F5 ile genişledi): `shell` yalnız `allow-open` + `http(s)://*` URL kısıtı (komut çalıştırma izni yok); `fs` yalnız `fs:scope` iki kök (`$APPDATA/syncra/**`, `$TEMP/syncra/**`) ve **hiçbir `fs:allow-*` işlem izni yok**; `core:window` yalnız set-focus/show/hide; F5'in eklediği izinler dar ve amaca bağlı: `global-shortcut:allow-register/unregister`, `deep-link:default`, `notification:default`, `autostart:default`, `dialog:allow-open` (yalnız aç, kaydet yok), `os:allow-platform/arch`, `process:allow-restart`. **`clipboard-manager:allow-read-text` hâlâ VERİLMİYOR** — pano Rust'tan okunuyor (I8) | İki kalıntı: (1) `updater:default` verilmiş ama arkasında kayıtlı plugin yok (S4) — F7'de plugin gelince izin sessizce canlanır; (2) `windows` listesine `quick-capture` eklendi, yani bu izinler ikinci bir pencerede de geçerli. Her yeni izin bu satıra yazılır | KAPALI (bugünkü yüzey için) |
| E3 | Elevation | Token yaşam döngüsü: deaktive/silinen kullanıcının token'ı çalışmaya devam eder mi | Sunucu | Anında iptal: `toggleActive` / `delete` / **`resetPassword`** içinde `tokens()->delete()` (`app/Services/Users/UserService.php:203,230,251` — reset-password, protokolün D7 düzeltmesi); şifre değişiminde SPA'dan → tümü, masaüstünden → kendisi hariç tümü (`app/Services/Auth/AuthService.php:281-283`, TransientToken tuzağı çözülmüş); testler `DeviceTokenTest.php:203,225`, `DevicePasswordChangeTest.php` | **2026-08-31: istemci yarısı da kapandı** — deaktivasyon 403 `USER_DEACTIVATED` ile ayrı sinyal olarak geliyor ve `sync/mod.rs:1395` (koşul) + `:1412` (wipe) o sinyalde lokal DB + keychain'i wipe ediyor (KARAR A25, §3/2-A25) | ✅ KAPALI (sunucu + istemci) |
| E4 | Elevation | Deep link hedefinin **ikinci** savunma hattını (route tablosu) prototip zinciriyle delmek | `desktop/src/bridge/deeplink-routes.ts` | `Object.hasOwn(ROUTES, entity)` + `^[0-9]{1,12}$` id kontrolü (`deeplink-routes.ts:76,99-102`); testler `deeplink-routes.test.ts:74` (prototip anahtarları) ve `:94` (§6.4 dışı id) | **2026-09-01 öncesi bu hat KAPALI DEĞİLDİ** — düz property lookup `constructor` için boxed `String`, `toString` için `[object Undefined]` döndürüyor, `__proto__`/`valueOf`/`hasOwnProperty`/`toLocaleString` için Tauri event callback'i içinde `TypeError` fırlatıyordu; id hiç doğrulanmıyordu (`../admin` → `/deals/../admin`). Rust allowlist ayaktayken hiçbiri erişilebilir değildi — **ama ikinci hattın işi birincinin düştüğü varsayımı altında doğru olmaktır** | ✅ KAPALI (TM-F9), §4.6/a |
| — | (F5 yüzeyleri) | Tray, native bildirim, global hotkey, deep link, drag-drop + PDF cache, clipboard, autostart/window-state, screenshot | F5 yüzeyleri | **Artık kod var** (§0). Güvenlik açısından ayrıştırılmış satırlar: deep link → S5 + E4 (+ §3/5), pano → I8 (+ §3/6), cache blob'ları → I7, capability yüzeyi → E2 | Tray/quick-capture/autostart pencere ve yaşam-döngüsü yüzeyleridir; bu turda **ayrı bir tehdit satırı gerektirecek girdi kabul eden yüzey bulunmadı** — quick-capture kendi webview'ında aynı CSP ve aynı capability seti altında çalışır | Modellenmiş (satır satır) |

---

## 3. SYNCDESKTOP §9 Kontrol Listesi — Madde Madde

1. **`desktop` ability'siz token → `/api/sync/*` 403 (test).** **SAĞLANIYOR** — düzeltilmiş
   biçimiyle (protokol D2: "device token taşımayan istemci → 403"). `ability:desktop` tek
   başına yetmez: Sanctum, `HasApiTokens` eklendiği andan itibaren her cookie oturumuna
   `can()` → koşulsuz `true` dönen `TransientToken` verir (zincirin dört halkası vendor
   satırlarıyla `EnsureDeviceToken.php:13-31` blok yorumunda). Kapama iki parçalıdır:
   route zinciri `['auth:sanctum','active','password.changed','ability:desktop','device.token']`
   (`routes/api.php:123-124`) + `EnsureDeviceToken`'ın `instanceof PersonalAccessToken` şartı
   (`EnsureDeviceToken.php:50`) → cookie/TransientToken 403 `ABILITY_REQUIRED`. `ability`
   alias'ı Laravel 12'de varsayılan olmadığı için elle kayıtlı (`bootstrap/app.php:191`).
   Testler: ability'siz gerçek token → `DeviceTokenTest.php:247`; cookie oturumu →
   `DeviceTokenTest.php:263` (K-A gereği bu tek test bilinçli `actingAs()` kullanır ve
   yorumunda gerekçesi yazılıdır; diğer sync testlerinde `actingAs()` yasak, `InteractsWithDeviceTokens` helper'ı kullanılır).

2. **Deaktive/silinen kullanıcı → lokal DB + keychain wipe (test).**
   **KAPALI (2026-08-31, KARAR A25).** Aşağıdaki "karar gerekiyor" metni, kararın
   verilmesinden ve uygulanmasından **önce** yazılmıştır ve tarihsel gerekçe olarak
   bırakılmıştır — bugünkü davranış için §3/2-A25 notuna bakın.
   *Sunucu:* iptal anında ve üç akışta da var (§2/E3, `UserService.php:203,230,251`);
   sonraki bearer istek 401 alır (test `DeviceTokenTest.php:203`).
   *İstemci:* 401'de `handle_auth_lost()` keychain'den **token'ı siler**, oturumu düşürür —
   ama **DB'yi silmez** (`desktop/crates/syncra-sync/src/sync/mod.rs:1001-1011`). Bu,
   şartnamenin **kendi içindeki çelişkidir**: §5.5 "401 → AuthLost (outbox korunur, aynı
   user yeniden login → devam)" derken §9/2 "tamamen wipe" der. Kod §5.5'i uygulamıştır.
   Wipe bugün yalnız iki yerde: farklı kullanıcı login'i (`sync/mod.rs:187`) ve `logout`
   (`sync/mod.rs:238-251`). DB bu arada SQLCipher'lı ve anahtarı keychain'de kalır — düz
   metin maruziyeti yoktur; ama "hesap kapatıldı → cihazdaki kopya da gitsin" garantisi
   bugün **verilemez**. Karar gerekiyor (bkz. TM-F1); 401'in "deaktive" mi "şifre değişti"
   mi olduğunu istemci ayırt edemediği için naif "her 401'de wipe", şifre değiştiren
   kullanıcının gönderilmemiş outbox'unu da yok eder — çelişkinin sebebi budur.

   **§3/2-A25 — çözüm (2026-08-31).** Çelişki, 401'i yorumlamaya çalışarak değil,
   **ayrı bir sinyal** kullanılarak kapatıldı. Sunucu deaktive hesabı için 401 değil
   **403 + `code: USER_DEACTIVATED`** döndürür (`AuthService.php:355`); istemci yalnız bu
   kesin sinyalde tam wipe yapar (`sync/mod.rs:1395` koşul — `status == 403 && code ==
   USER_DEACTIVATED` — ve `:1412` wipe çağrısı), çıplak 401'de outbox'ı korur.
   **2026-09-01 genişlemesi:** o wipe artık `wipe_local()`'dir ve şifresiz cache blob'larını
   da siler (`:183-200`, defter O67 / TM-F15) — yani "hesap kapandı → cihazdaki kopya da
   gitsin" garantisi teklif PDF'lerini ve ek kuyruğunu da kapsar.
   Böylece §9/2 ("hesap kapandı →
   cihazdaki kopya da gitsin") ve §5.5 ("şifre değiştiren kullanıcının bekleyen işi
   yok olmasın") **aynı anda** sağlanır. Kilitleyen testler `tests/wire_contract.rs`:
   `a_deactivated_account_wipes_the_local_database` (pozitif),
   `a_plain_403_does_not_wipe_anything` ve `a_bare_401_still_keeps_the_outbox`
   (negatif kontroller — dar kapsamın kazara genişlemesini yakalar).

3. **DB dosyası düz `sqlite3` ile açılamıyor (test: header `SQLite format 3` yok).**
   **SAĞLANIYOR** — iki bağımsız kanıt:
   *Regresyon testi:* `tests/encryption.rs:17-48` header'ı **ve** yazılmış satır içeriğinin
   düz metin olarak dosyada geçmediğini assert eder; `:53-62` yanlış anahtarla açılışın
   hata verdiğini kanıtlar (F6 yorumu testin başında: "a build that silently falls back to
   plain SQLite fails the crate's own suite").
   *Canlı okuma (bu denetimde, 2026-08-31):* gerçek dosyanın ilk 16 baytı
   `od -c` ile `A 257 / 233 313 Y G , ...` — `SQLite format 3\0` değil.
   Mekanizma: `PRAGMA key` her şeyden önce (`db/mod.rs:38`), ardından anahtar doğrulama
   sorgusu (`db/mod.rs:41-43`).

4. **Keychain'de anahtar; app data'da anahtar/token dosyası yok (dizin taraması).**
   **SAĞLANIYOR** — canlı dizin dökümü (2026-08-31):
   - `C:\Users\<u>\AppData\Roaming\com.syncra.desktop\syncra\` → yalnız `syncra.db`,
     `syncra.db-shm`, `syncra.db-wal`. Başka dosya yok.
   - `C:\Users\<u>\AppData\Local\com.syncra.desktop\` → `EBWebView\` (WebView2 profili) ve
     `logs\Syncra.log`.
   - `Syncra.log` içinde keyring işlemlerinin **girdi adları** var (`db-key.syncra-desktop`,
     `device-token.syncra-desktop`), **değerleri yok** (keyring crate parola değerini
     loglamaz — canlı dosyada da gözlenmedi). Log yüzeyi için bkz. madde 9 / TM-F3.
   - Not: şartnamedeki `$APPDATA/syncra/` yolu gerçekte `Roaming\com.syncra.desktop\syncra\`
     olarak çözülür (Tauri `$APPDATA` = identifier dizini); `fs:scope`'un
     `$APPDATA/syncra/**` deseni (`default.json:26-28`) aynı çözümlemeyle eşleşir — tutarlı.

5. **Deep link reddi (fuzz 83 örnek).** **KAPALI (2026-09-01, defter O89).**
   2026-08-31'de bu madde DEĞERLENDİRİLEMEZ-F5'ti: handler yoktu, `syncra://` şeması
   kayıtlı değildi. Bugün üçü de var — `tauri.conf.json` → `plugins.deep-link.desktop.schemes:
   ["syncra"]`, plugin `lib.rs:104`'te kayıtlı, argv yolu `lib.rs:46-66`'da
   `handle_cli_arguments`'a bağlı.

   *Birinci hat (Rust, güvenlik sınırı).* `deep_link::parse_deep_link` (`deep_link.rs:170-190`)
   üç kural uygular ve **kendisi hiç normalizasyon yapmaz** — kırpma, küçük harfe çevirme,
   percent-decode yok; her biri "iki farklı dizginin aynı kabule düşmesi"nin, yani allowlist
   atlatmanın yoludur (`:160-169` yorumu). Kurallar: `syncra://` öneki → `^[a-z]+/[0-9]{1,12}$`
   → sekiz isimlik `ENTITIES` allowlist'i (`:97`).

   *Fuzz doğru katmanda.* Korpus artık plugin'in verdiği **ayrıştırılmış** `url::Url`
   katmanından besleniyor (`Url::parse` → `as_str()` → `parse_deep_link`; plugin'in
   `handle_cli_arguments`'ıyla aynı `url` sürümü). Ham dizgi üzerinden test etmek
   yanıltıcıydı: `deal/../29` ham hâlde reddedilir, gerçek yolda normalizasyon `..`'yi siler.
   **50 → 83 örnek.** İddia: her örnek **iki** meşru sonuçtan birine düşer (red · sözleşme
   içi `{entity, id}`), üçüncüsü yoktur — test `the_fuzz_corpus_reaches_no_third_outcome`
   (`deep_link.rs:675`). İddia **bağımsız** bir `CONTRACT_ENTITIES` kopyası + elle yazılmış
   şekil kontrolüyle kuruluyor (`:596,609`); `ENTITIES`'e sormak totoloji olurdu ve allowlist
   genişleyince assert de sessizce genişlerdi. Yardımcı testler: `the_normalisation_families_are_pinned`
   (`:732`), `the_fuzz_corpus_has_no_duplicates` (`:848`), `each_rule_has_its_own_rejection`
   (`:874`), `no_launch_url_outside_the_contract_is_ever_held` (`:972`).

   *Sınır — normalizasyon `id`'yi kaydırabilir, `entity`'yi asla.* `normalisation_can_change_the_id_but_never_the_entity`
   (`:809`) bu sınırı çiviliyor. **Düzeltilmedi, kabul edildi** — gerekçesi §4.6/b.

   *İkinci hat (TS) kapalı değildi, kapatıldı.* §4.6/a ve §2/E4.

   > **Kalıntı (F7'ye devredildi — `SYNCDESKTOP.md` §10 F7):** MSI/NSIS kurulumundan sonra soğuk başlangıç
   > teslimi ve `HKCU\Software\Classes\syncra` kaydının kurulumla gelmesi **paketlenmiş
   > kurulum olmadan doğrulanamaz** — bu turda ÖLÇÜLMEDİ.

6. **Clipboard içeriği log/diske yazılmıyor.** **Kanıt bu turda yazıldı — kod ve testler
   sağlıyor.** *(`SYNCDESKTOP.md` §9 satırı bu belge yazılırken hâlâ `⬜ DEĞERLENDİRİLEMEZ —
   F5-6` diyor; şartname bu belgenin sahipliğinde değildir, senkronizasyon teknik lidere
   bırakıldı — §7.)*

   Özellik F5-6'da yazıldı (`src-tauri/src/clipboard.rs`), poll döngüsü `lib.rs:232`'de
   başlatılıyor, kullanıcı anahtarı `ui/panels/DesktopPreferences.tsx:223-225`. Maddenin
   iddiası — "pano içeriği log'a/diske yazılmıyor" — dört bağımsız kontrolle destekleniyor:

   - **Varsayılan kapalı, kabuk kendi başına açmıyor:** `clipboard_capture_is_off_by_default`
     (`clipboard.rs:238`) ve `nothing_in_the_shell_enables_capture_on_its_own` (`:252`);
     tek yazar `storage::update_settings`, yani ayarlar ekranındaki bilinçli kullanıcı eylemi.
   - **Bildirim panodan parametre almıyor:** `the_notification_cannot_carry_clipboard_content`
     (`:334`) — bildirim metni uygulamanın kendi `desktop.json`'ından gelir, pano dizgisi
     bildirime giden hiçbir yolda görünmez.
   - **Bellekte metin tutulmuyor:** değişim algılayıcı `u64` parmak izidir, dizgi değil —
     `the_change_detector_keeps_a_number_not_the_text` (`:362`). Uzun ömürlü bir proses
     globali panoyu tutmuş olmaz.
   - **Yazma/loglama yolu modülde YOK:** `no_log_or_file_write_can_reach_the_clipboard_text`
     (`:378-405`) modülün **kendi kaynağını okuyup** `fs::write`, `File::create`,
     `OpenOptions::new`, `write_all`, `std::fs::` ve `tracing::` aramaları yapar. Bu bilinçli
     olarak **kaynak seviyesinde** bir assert'tir: "bu satır yok" özelliğini davranışsal bir
     test gösteremez — döngüyü koşup log'u boş bulmak yalnız örneğin o dalı tetiklemediğini
     kanıtlar. Needle'lar `concat!` ile kuruluyor ki test kendi kaynağıyla eşleşip yanlış
     sebeple yeşil vermesin.
   - **Webview panoya hiç erişemiyor:** `clipboard-manager:allow-read-text` capability'de
     **verilmiyor** ve verilmesine gerek de yok — okuma Rust'tan yapılıyor, capability'ler
     orada geçerli değil (`default.json` açıklaması). İzni verip kapılamaktan **dar** bir yüzey.

   Kalıntı: §9/9'un maskeleme kapsamı e-posta ve E.164 ile sınırlıdır; bu madde pano
   metninin log'a *hiç ulaşmamasına* dayanır, maskeye değil — doğru sıralama budur.

7. **CSP ve capabilities dar; `shell` yalnız `open`.** **SAĞLANIYOR — F5 sonrası yeniden
   okundu (2026-09-01).**
   *Capabilities* (`default.json`, F5 ile büyüdü — tam liste §2/E2): `shell:allow-open` yalnız
   `http(s)://*` URL'leri, komut çalıştırma izni yok; `fs` yalnız `fs:scope` iki kökle
   (`$APPDATA/syncra/**`, `$TEMP/syncra/**`) ve **hiçbir `fs:allow-*` işlem izni yok** —
   şartnamenin istediğinden bile dar; pencere izinleri yalnız set-focus/show/hide;
   `clipboard-manager:allow-read-text` **hâlâ yok** (madde 6). F5'in eklediği yedi izin
   (global-shortcut, deep-link, notification, autostart, dialog:allow-open, os:allow-platform/arch,
   process:allow-restart) tek tek amaca bağlıdır; `dialog` yalnız **aç**, kaydet yok.
   *CSP* (`tauri.conf.json` `app.security.csp` + build-time overlay `scripts/tauri.mjs:67-102`):
   `default-src 'self'`, `connect-src` yalnız IPC + API + Reverb origin'leri (`:81`),
   `img-src 'self' data: <apiOrigin>` (`:82`), `object-src 'none'`, `frame-ancestors 'none'`;
   `unsafe-inline` yalnız style ve style-attr'da (Tauri nonce düzeltmesi S2, script'te değil).
   Kalıntı notlar: `shell:allow-open`'ın `http://*` kabulü TLS'siz link açılmasına izin verir
   (tarayıcıda açıldığı için düşük — TM-F8), `.env` fallback'i TM-F6, `updater:default`
   izninin plugin'siz durması S4.

8. **Updater imza doğrulaması; imzasız manifest reddi.** **DEĞERLENDİRİLEMEZ-F7 —
   bugünkü durum fail-closed.** `tauri.conf.json`'da artık bir `plugins` bloğu **var**, ama
   içinde yalnız `deep-link.desktop.schemes` duruyor: `plugins.updater` **yok**. Plugin
   yalnız release build'de kayıt olur (`lib.rs:81-84` (comment `:68-80`)) ve `pubkey` alanı zorunlu/varsayılansız
   olduğu için bloksuz release **hiç açılmaz** (`lib.rs:67-80` yorumu; sahte pubkey commit'i
   bilinçle reddedilmiş — "a fake signing key committed to the repo is the kind of
   placeholder that survives to production"). Yani bugün ne imzalı ne imzasız hiçbir
   güncelleme yolu mevcut değil; doğrulama, gerçek minisign anahtarı üretildiğinde (F7 1.
   madde) yapılacak. "İmzasız manifest reddi" testi F7 kabul kriterine devredildi (§6).

9. **`tracing` PII filtresi (email/phone masked).** **KAPALI (2026-08-31).**
   `desktop/src-tauri/src/logging.rs` TM-F3'ün **iki adımını da** uyguluyor:
   `level_for_build()` release derlemesini `Info`'ya kısar (keyring'in DEBUG gürültüsü
   dahil her şey susar), `masking_format()`/`mask_pii()` e-posta ve E.164 telefonu sabit
   `[email]`/`[phone]` yer tutucularıyla değiştirir. İkisi de **aynı** fern dispatch'ine
   bağlı, yani hiçbir sink filtrelenmemiş akışa erişemez. 4 test + 1 negatif kontrol.
   Uzunluğu koruyan maske bilinçli olarak reddedildi (uzunluk da bilgidir).
   Aşağıdaki metin kapanıştan **önce** yazılmıştır, tarihsel gerekçedir: Log plugin'i F3'ten beri canlı (`lib.rs:78`, `Builder::new().build()` —
   filtre/seviye yapılandırması **yok**) ve gerçek makinede `$LOCALAPPDATA\com.syncra.desktop\logs\Syncra.log`
   dosyasına DEBUG seviyesinde yazıyor (canlı kanıt: keyring DEBUG satırları). Bugünkü
   gözlemlenen içerikte PII/sır yok (`syncra-sync` kaynağında payload/email/token loglayan
   `tracing` çağrısı grep ile bulunamadı) — ama bunu garanti eden hiçbir katman yok;
   koruma "kimse loglamadı"dan ibaret. Şartnamenin istediği maskeleme filtresi yazılmamış,
   dolayısıyla "clipboard içeriği loga yazılmıyor" (madde 6) gibi gelecekteki iddiaların
   altyapısı da hazır değil.

10. **`docs/DESKTOP-THREAT-MODEL.md` (STRIDE tablosu, PHASE-AUDIT formatı).** Bu doküman —
    bu teslimatla var. Canlı tutulma şartı §6'da.

---

## 4. Bilinen Açıklar — Derinlemesine

### 4.1 `sync_deletions` sahibe kapsamlanamıyor (TM-F2)

Şema sahip kolonu taşımaz (`table_name, row_key, sync_version, deleted_at` —
`migrations/2026_09_01_100002_create_sync_deletions_table.php:26-36`) ve tombstone, satır
silindikten **sonra** yazıldığı için sahip geriye dönük çözülemez. `deletionsFor()` yalnız
`conversation_user` için kapsam uygular (`SyncPullService.php:330-334` — `row_key`'in
`conversation_id:user_id` mantıksal anahtarı üzerinden LIKE); `notifications` tombstone'ları
kapsamsızdır. Sonuç: `notifications.view` iznine sahip herhangi bir kullanıcı, pull'da
**başka kullanıcıların silinmiş bildirim uuid'lerini** (yalnız uuid + sync_version; içerik,
tip, kime ait olduğu bilgisi YOK) görür. Satır-kapsamlı pull ilkesinin (I1,
`SyncScope::applyRowScope`) tombstone düzleminde deliğidir. `tags` ve `price_list_items`
tombstone'ları global veridir, sorun yok.

Masadaki öneri (F1 raporu; depoda henüz şemaya/koda yansımadı, `owner_key` araması 0 sonuç):
tombstone'a `owner_key VARCHAR(64) NULL` eklemek — `SyncDeletionObserver` silme anında
sahibi (`notifiable_id`) bilir ve yazabilir; `deletionsFor()` `notifications` için
`owner_key = user_id` filtresi uygular. Geriye dönük satırlar için `NULL` = "eski, herkese
görünür" kabulü ya da tek seferlik temizlik gerekir.

**Şiddet: DÜŞÜK** — sızan yalnız varlık bilgisi ve rastgele uuid'ler; korelasyon değeri çok
düşük. Ama düzeltme ucuz, ilke ihlali net.

### 4.2 `can.*` izinleri istemcide permissive `true` (KARAR A22)

`mappers.ts:190,330,388,406,496` tüm satır aksiyonlarını `can: {...: true}` döner; gerekçe
dosyanın başında (`mappers.ts:30-33`): satır bazlı izinler senkron kapsamında değil,
`false` masaüstünü offline salt-okunur yapardı. **Tehdit modelindeki anlamı:** istemci
tarafı yetki gösterimi bir güvenlik kontrolü **değildir ve hiç olmamıştır** — güven sınırı
SINIR 1'dir ve push'taki Policy zinciri (`MutationApplier.php`) tek otoritedir. Bir
saldırganın `can.*`'ı `true` yapması ona hiçbir şey kazandırmaz; zaten `true`. A22'nin
gerçek bedeli gizlilik/bütünlük değil **kullanılabilirlik ve hata görünürlüğüdür**: yetkisiz
kullanıcı offline'da işlem sahneleyebilir, red push anında gelir. Bu redlerin Conflict
Inbox'ta anlaşılır gösterilmesi F4 yükümlülüğüdür (EK 3 A22 "bedeli F4'e taşınıyor") ve
kapanmadan A22 "tamamlanmış" sayılmamalıdır. İkincil bir gösterim katmanı olarak manifest
efektif izinleri taşır (`ManifestController.php:58`) — F4 UI kapılaması bunu kullanacak
(SYNCDESKTOP F4: "izinler manifest'ten").

### 4.3 CSP host'ları build-time; `.env` yanlış/eksikse (TM-F6)

`tauri.mjs` CSP'yi `frontend/.env` → `.env.local` → süreç `VITE_*` önceliğiyle üretir
(`tauri.mjs:46-55`) — frontend bundle'ın kullandığı kaynağın aynısı; drift olasılığı
tek-kaynak kararıyla (D-2/D-3) kapatılmış. Kalan iki senaryo:
1. **`VITE_API_URL` eksik/parse edilemez** → `toOrigin` sessizce `http://localhost:8000`
   döner (`tauri.mjs:58-63,67`) ve `http.ts:32` aynı fallback'i kullanır. Paketlenmiş build
   gerçek API'ye hiç bağlanamaz; CSP ihlali paketli webview'da **sessizdir** (script'in
   kendi yorumu, satır 8-10). Gizlilik açısından fail-closed, kullanılabilirlik açısından
   sessiz kırılma. Ek uç senaryo: böyle bir build'de login ekranı kimlik bilgilerini
   `http://localhost:8000`'e POST eder — o portu dinleyen kötücül **lokal** proses
   kimlik bilgisi toplayabilir (SINIR 3 içi, düşük).
2. **`.env` saldırgan kontrolünde** → CSP ve API hedefi saldırgan origin'e işaret eder.
   Bu, "geliştirme makinesi ele geçirilmiş" senaryosudur ve model kapsamı dışıdır; yine de
   fallback'in sessiz olması yerine build'i durdurmak iki senaryoyu da ucuza küçültür.

### 4.4 `device_fingerprint` istemci beyanı (TM-F4)

Fingerprint'i istemci üretir; sunucu yalnız biçim doğrular (`DeviceTokenRequest.php:38`,
64-hex). **Cihaz başına tek token kuralı atlatılır mı? Evet — ama yalnız kendi hesabı
için:** her login'de farklı fingerprint gönderen kullanıcı sınırsız kalıcı token biriktirir
(silme sorgusu `$user->tokens()` üzerinden kendi hesabına kapsamlı —
`DeviceTokenService.php:110-116`; başka hesabın token'ına ulaşamaz, çapraz silme de
yapamaz). Etki: kendi hesabında token bolluğu — tümü `GET /api/me/devices`'ta görünür ve
tek tek iptal edilebilir (`routes/api.php:201-202`), kullanıcı deaktive edilince tümü
birden silinir (E3). Yetki kazanımı yok; kalıntı risk "unutulmuş kalıcı kimlik bilgisi"
sayısının artmasıdır. Ucuz sertleştirme: kullanıcı başına desktop token tavanı (ör. 10) —
öneri, uygulanmadı (§0.5).

### 4.5 FK `ON DELETE CASCADE` kör noktası (veri kaybı sınıfı)

Probe D3 (protokol §2.8) kanıtladı: MariaDB 10.4'te cascade ile silinen çocuk satır ne
`AFTER DELETE` trigger'ını ne Eloquent event'ini tetikler → ne `sync_version` bump'ı ne
tombstone yazılır → istemci aynası silinen satırı **sonsuza dek** taşır. Bugün tüm cascade
zincirleri yalnız ebeveynlerin soft delete kullanması sayesinde ölü — tasarım değil
tesadüf. F1 bu tesadüfü sözleşmeye çevirdi: `SyncSchemaTest` katman 1 kapsamdaki her FK'nın
`DELETE_RULE`'unu bilinen listeye kilitler (`SyncSchemaTest.php:62,104`), katman 2 cascade
ebeveynlerinde hard-delete yolu bulunmadığını assert eder (`:146`; `custom_fields.destroy`'un
gerçekte deactivate olduğu davranışsal olarak da test edilir, `:202-219`). `RESTRICT`
migration'ı bilinçle yapılmadı (protokol §10.1 — web şema semantiği, kapsam genişletmesi).
Sınıf: **bütünlük/veri kaybı**, gizlilik değil. Durum: kilitli; `forceDelete`/KVKK purge
gündeme gelirse test kırmızısı tasarım kararını zorlar.

> **2026-09-01 — "uykuda" varsayımı bir FK için artık geçerli değil.** Katman 2'nin dayanağı
> "cascade/SET NULL ebeveynlerine hard-delete yolu yok"tu. `messages.attachment_id`
> (`SET NULL`, `SyncSchemaTest.php:82`) için böyle bir yol **var**:
> `attachments:prune-orphans` `forceDelete` ile siler ve zamanlayıcıya bağlıdır. Ayrıntı ve
> kanıt §4.8'in sonundaki uyarıdadır.
>
> Katman 2 assert'i (`SyncSchemaTest.php:145-198`) bu yolu **biliyor ve muaf tutuyor**:
> yorumu aynen *"The one legitimate `forceDelete` in the codebase is `PruneOrphanAttachments`,
> and `attachments` is outside the sync scope entirely (§1.3)"* der (`:181-183`), ve
> `forceDelete` taraması `app/Console/Commands`'ı zaten kapsamıyor (`:186`).
> **Bu gerekçe KARAR A29'dan öncedir.** A29'dan sonra `attachments` hâlâ sync kapsamı
> dışındadır — ama `messages` satırı artık ekin metadata'sını taşır ve `SET NULL` **kapsam
> içindeki** o satırı sessizce değiştirir. Yani muafiyetin dayandığı "bu tablo kimseyi
> ilgilendirmiyor" önermesi artık doğru değil. Testin hatası değil, **gerekçesinin
> bayatlamasıdır**; düzeltme sahibi backend şerididir (§7).

### 4.6 Deep link: iki hat, iki ayrı sonuç (TM-F9 kapalı / TM-F10 kabul edildi)

Deep link tek bir yüzey değil, **iki savunma hattıdır**: Rust ayrıştırıcısı (güvenlik sınırı)
ve TS route tablosu (ikinci hat). Bu turda ikisi de ölçüldü ve **farklı sonuçlar** verdi.

**(a) İkinci hat kapalı değildi — DÜZELTİLDİ (TM-F9).**
`desktop/src/bridge/deeplink-routes.ts`'teki `ROUTES[entity]` düz bir property lookup'tı, yani
JavaScript'te **prototip zincirini yürüyordu**. "Elle yazılmış kapalı tablo" başlığını taşıyan
nesne kapalı değildi; ölçülenler:

| Girdi `entity` | Ölçülen davranış |
|---|---|
| `constructor` | `Object` çözülüyor → yol yerine **boxed `String`** dönüyor |
| `toString` | dizgi `[object Undefined]` dönüyor |
| `__proto__`, `valueOf`, `hasOwnProperty`, `toLocaleString` | **`TypeError`** — üstelik Tauri event callback'inin içinde, yani atılan hatanın gidecek yeri yok |

Ayrıca **`id` hiç doğrulanmıyordu**: `{entity: 'deal', id: '../admin'}` → `/deals/../admin`,
`{id: '42?x=1'}` → üzerinde query string taşıyan bir yol. İkisi de ölçüldü, ikisi de route
edildi.

**Kapatma:** `Object.hasOwn(ROUTES, target.entity)` + `ID_PATTERN = /^[0-9]{1,12}$/`
(`deeplink-routes.ts:76,99-102`). Kabul edilen küme **daralmadı** — Rust ayrıştırıcısı zaten
sekiz ismi ve aynı id şeklini dayatıyor, dolayısıyla gerçek bir deep link'in reddettiği
hiçbir şey yok. Testler: `deeplink-routes.test.ts:74` (prototip anahtarları), `:94` (şekil
dışı id), `:113` (§6.4'ün izin verdiği id sınırları hâlâ geçiyor — daralmadığın kanıtı).

**Neden önemli, madem Rust allowlist'i ayaktaydı:** hiçbiri erişilebilir değildi. Ama bu
modülün **işi** ikinci hat olmaktır; ikinci hattın doğruluğu, birincinin düştüğü varsayımı
altında ölçülür. Bir hattın "diğeri tutuyor" diye yanlış olması, iki hattı bir hatta indirger
ve bunu sessizce yapar. §9/5'in negatif kontrolü (allowlist'e dokuzuncu isim eklenince fuzz
kırmızıya düşüyor) tam olarak bu senaryoyu simüle eder.

**(b) URL normalizasyonu `id`'yi kaydırabiliyor, `entity`'yi asla — KABUL EDİLDİ (TM-F10).**
Ölçülen: `syncra://deal/1/../2` → **deal 2**. Linkte görünen id, açılan id değil.
`syncra://deal/9999999999999/../42` → deal 42 — regex'in tek başına reddedeceği 13 haneli
segment, sonraki `..` tarafından siliniyor, geriye sözleşme içi bir hedef kalıyor.

**Sınırın nerede olduğu ölçüldü:** entity url'in **host**'udur ve hiçbir path normalizasyonu,
nokta segmenti veya percent-encoding host'a ulaşmaz — `deal/../lead/1` host'u `deal` bırakır
(sonra allowlist'te olsa bile id şekli tutmaz), `setting/../deal/42` allowlist'e takılır,
`DEAL/42` host küçük harfe katlanmadığı için reddedilir. Test:
`normalisation_can_change_the_id_but_never_the_entity` (`deep_link.rs:809`).

**Karar: düzeltilmez.** Gerekçe iki katmanlı:
1. Kabuğun kendi URL normalizasyonunu yazması, `url` crate'ini **ikinci kez tahmin etmek**
   demektir. İki ayrıştırıcının aynı dizgiden farklı sonuç çıkarması (parser-differential)
   bugünkü id kaymasından **daha kötü** bir sınıftır; `parse_deep_link`'in "hiç normalizasyon
   yapmama" ilkesi (`deep_link.rs:160-169`) tam da bunun için var.
2. Kaymanın ulaşabildiği küme **aynadaki kayıtlardır** — yani `SyncScope`'un pull anında
   üyelik/izin filtresinden geçirdiği satırlar. Kullanıcının zaten görebildiği bir kayda
   yanlış link üzerinden gitmek yetki kazanımı değil, **yanıltmadır**. Şiddet: DÜŞÜK.

Davranış testle çivilendi; kararın kendisi `SYNCDESKTOP.md` §9/5'te kayıtlıdır.

### 4.7 `attachment_is_image`: bir tanım vaat edildi, iki tanım vardı (TM-F11 / TM-F12)

**Bulgu (bu tur, KARAR A29 kablolanırken).** `attachment_is_image` sunucuda **iki farklı
şekilde** tanımlanmıştı ve pull satırı gevşek olanı kullanıyordu:

| Tanım | Nerede | `image/svg+xml` |
|---|---|---|
| `str_starts_with($mime, 'image/')` | `ChatAttachmentResource::payload()` (`app/Http/Resources/Chat/ChatAttachmentResource.php:48`) | **`true`** |
| `Attachment::isInlineEligibleImage()` = `config('chat.attachments.inline_mime_types')` allowlist'i, tam olarak `image/jpeg\|png\|gif\|webp` | `Attachment.php:64-66`, `AttachmentResource.php:30`, `AttachmentController::show()`'un `?inline=1` kapısı (`:62`) | **`false`** |

Allowlist SVG'yi **bilerek** dışarıda bırakır: SVG XML'dir, `<script>` ve olay dinleyicisi
taşıyabilir, inline render'ı bilinen bir XSS vektörüdür (`config/chat.php:19-22` bunu açıkça
yazar).

**Ağırlaştırıcı — yalan söyleyen docblock.** `SyncPullService::attachMessageAttachments()`'in
docblock'u *"K7: ONE definition of `is_image`, never re-derived here from a MIME prefix"* diye
**uyum iddia ederken** hemen altındaki kod tam olarak o MIME prefix'ini kullanıyordu. Bu
belgenin varlık sebebi bu sınıftır: bir yorumun sözleşmeye atıf yapması, koda bakmadan
doğrulanmış sayılamaz. Denetimin kuralı — *iddiayı değil satırı oku* — burada işledi.

**(TM-F11) Sunucu pull satırı — KAPALI.** `SyncPullService.php:503` artık
`$attachment->isInlineEligibleImage()` çağırıyor; satır Eloquent üzerinden (`DB::table()`
değil) çekiliyor ki tanım gerçekten kaynağından okunabilsin (`:479-486` yorumu). Kilitleyen
testler `tests/Feature/Sync/SyncPullMessageAttachmentTest.php`:
`test_attachment_is_image_false_for_svg_despite_matching_the_image_mime_prefix` (`:142`),
`test_attachment_is_image_true_for_each_allowlisted_mime` (`:183`, dört tür),
`test_pull_row_attachment_is_image_matches_attachment_resource_is_image` (`:255` — pull
satırını `AttachmentResource` ile **karşılaştıran** parite testi; SVG korpusta, `:288`).

**(TM-F12) Sunucuda hâlâ İKİ tanım var — AÇIK (BİLGİ).** Düzeltme, sync satırını **sıkı**
tanıma taşıdı; gevşek tanımı **ortadan kaldırmadı**. Bugünkü gerçek hâl:

- `ChatAttachmentResource::payload():48` hâlâ `str_starts_with($mime, 'image/')` ve **web
  sohbet balonunun kullandığı tanım budur** (`Chat/MessageResource.php:114`).
- Yani K7'nin "tek tanım" şartı sağlanmıyor; ayna satırı ile web sohbet yüzeyi bir SVG eki
  için **birbirine zıt** cevap verir.
- `SYNCDESKTOP.md` §4.4 A29 *"Alanlar `ChatAttachmentResource::payload()`'dan düzleştirilir"*
  diyor; kod artık `AttachmentResource`'un tanımını kullanıyor. **Şartname metni kodla
  uyumsuz** → §7 (teknik lidere).

**Bugünkü erişilebilirlik — üç bağımsız katman, hepsi ölçüldü:**
1. **SVG hiç yüklenemiyor.** `config/chat.php`'nin `mime_map` allowlist'inde SVG yok
   (dosya başlığı `:19-22` bunu bilinçli olarak kayda geçirir) ve `AttachmentTypeGuard`
   uzantı **ve** içerikten tespit edilen MIME'ı birlikte dayatır. Masaüstü tarafındaki
   kuyruk listesi de aynı sebeple SVG içermez (`commands/files.rs:33-44`).
2. **Sunucu SVG'yi inline servis etmiyor.** `?inline=1` kapısı `isInlineEligibleImage()`'a
   bağlı (`AttachmentController.php:62`) → SVG `Content-Disposition: attachment` +
   `X-Content-Type-Options: nosniff` ile iner.
3. **Masaüstünde önizleme kanalı zaten kapalı.** `mapMessage` `is_image`'ı koşulsuz `false`
   düşürüyor (`desktop/src/platform/data/mappers.ts:718-758`; `attachment_is_image` okunup
   `void` ediliyor — KARAR A29/O90b: `<img>` isteği bearer taşıyamaz, 401).

**O hâlde neden bir bulgu?** Çünkü **kolonu aynalamamızın tek sebebi** "önizleme kanalı
ileride açıldığında yeniden pull gerekmesin"di (A29). Ayna, bugün kullanılmayan ama gelecekte
bir render kararına bağlanacak bir boolean taşıyor. Yanlış tanımla dolan böyle bir kolon,
kanal açıldığı gün **düzeltilmesi gereken bir veri** değil, çoktan dağıtılmış bir yanlış
cevaptır. Bugün etkisiz, yarın canlı — sınıfı budur.

### 4.8 KARAR A29'un sızıntı yüzeyi — doğrulama

**Soru:** `messages` pull satırına eklenen dört alan (`attachment_name`, `attachment_mime`,
`attachment_size`, `attachment_is_image`) **yeni bir sızıntı yüzeyi** açıyor mu?

**1. Kapsam pariteği — DOĞRULANDI, sızıntı yok.** Metadata `rowsFor()`'un zaten sayfaladığı
satırlara ekleniyor (`SyncPullService.php:466-506`), yani `SyncScope::applyRowScope`'un
filtresinden **geçmiş** satırlara. `messages` için o filtre:
`$query->whereIn('conversation_id', $this->conversationIds($userId))` (`SyncScope.php:82`),
`conversationIds()` ise `conversation_user` pivotundan kullanıcının konuşmalarını çeker
(`:119-129`). Ek olarak `messages` tablosunun manifest'te olması `chat` modülünün `.view`
iznine bağlıdır (`SyncScope.php:45-59`). Sonuç: metadata **yalnızca zaten okunabilen bir
mesaja** iliştirilir. Silinmiş mesaja hiç iliştirilmez (`:470,490`) — `MessageResource`'un
maskesiyle (`Chat/MessageResource.php:112-116`) parite. **Baytlar senkronlanmıyor**,
`attachments` tablosu aynada yok. Yeni sızıntı yüzeyi **yoktur**.

**2. `AttachmentPolicy` pariteği — İDDİA DOĞRU ÇIKMADI (yeni bulgu, TM-F14).**
A29 metni *"Satır kapsamı `SyncScope`'un üyelik filtresinden geçtiği için `AttachmentPolicy`
ile birebir aynıdır"* diyor. Yüzey benzerliği aldatıcı: `AttachmentPolicy::view()` **iki
dallıdır** (`app/Policies/AttachmentPolicy.php:43-79`) ve hangi dala gireceği
`attachable_id`'ye bakar —

- `attachable_id === null` → **yalnız yükleyen** (`uploaded_by`) erişebilir;
- `attachable_type === Message::class` → konuşma **üyeleri** erişebilir (aynı
  `conversation_user` sorgusu, yani `SyncScope` ile aynı yüklem).

İkinci dal gerçekten `SyncScope` ile aynıdır — **ama üretim yolunda o dala hiç girilmiyor.**
Ölçüm: `AttachmentUploadService::store()` `Attachment::create([...])` çağrısında
`attachable_type`/`attachable_id` **yazmıyor**; `MessageService::create()` mesajın
`attachment_id` kolonunu dolduruyor ama ekin `attachable_*` alanlarına **dokunmuyor**
(`app/Services/Chat/MessageService.php:156-163`). Depo genelinde `attachable` yazan tek yer
`LeadConversionService.php:389-391`'dir (lead → contact devri), Message için yazan yok.
`AttachmentApiTest.php:100` yükleme sonrası `attachable_id`'nin **null** olduğunu zaten
assert ediyor; `AttachmentFactory`'nin `attachable_type => Message::class` yazan state'i
(`:58-59`) yalnız testlerde kullanılıyor.

**Sonuç:** mesaj ekleri kalıcı olarak `attachable_id = NULL` kalıyor, dolayısıyla Policy
fiilen **birinci dala** düşüyor ve bir eki yalnız **yükleyeni** indirebiliyor. Yani
`AttachmentPolicy` bugün `SyncScope`'tan **daha dar**dır, daha geniş değil.

**Bunun sızıntı sonucu:** yok. Metadata düzleminde parite `AttachmentPolicy` ile değil,
**`MessageResource`/`ChatAttachmentResource` ile**dir: web sohbeti zaten aynı ad/boyut/tip
bilgisini aynı konuşma üyelerine gösteriyor (`Chat/MessageResource.php:112-116`). Masaüstü
aynası web yüzeyinin **kopyasıdır**, genişlemesi değil. Sızıntı değerlendirmesi **KAPALI**;
düzeltilmesi gereken şey A29'un *gerekçe cümlesi* (yanlış otoriteye atıf yapıyor) ve
`attachable_*`'ın hiç yazılmaması (aşağıdaki uyarı).

> ⚠️ **Kapsam dışı ama burada kayda geçiyor (§7'ye taşındı).** `attachable_id`'nin hiç
> yazılmaması ikinci bir sonuç doğuruyor: `attachments:prune-orphans` komutu
> `Attachment::query()->unattached()` = `whereNull('attachable_id')` üzerinden siler
> (`PruneOrphanAttachments.php:120`, `Attachment.php:74-77`) ve `routes/console.php:59`'da
> `--force` ile **günlük 03:47'ye zamanlanmıştır**. Komutun kendi docblock'u ise hâlâ
> *"zamanlayıcıya KAYITLI DEĞİL"* diyor (`:12-17`) — §4.7 ile aynı "yalan söyleyen docblock"
> sınıfı. Sınıf: **veri kaybı**, gizlilik değil.
>
> **Masaüstü tarafındaki asıl sonuç — §4.5'in kör noktasının canlı bir örneği.**
> `messages.attachment_id` FK'sı `nullOnDelete()`'tir
> (`2026_08_23_200006_create_messages_table.php:20`; `SyncSchemaTest.php:82`'de
> `'messages.attachment_id' => 'SET NULL'` olarak **kilitli**). Yani ek silindiğinde FK,
> `messages` satırını **veritabanı seviyesinde günceller** — probe D3'ün gösterdiği gibi bu
> ne Eloquent event'ini ne trigger'ı tetikler, dolayısıyla `sync_version` **bump edilmez** ve
> tombstone yazılmaz. Ayna, artık var olmayan bir eke ait ad/mime/boyut alanlarını
> **süresiz** taşımaya devam eder. §4.5 bu sınıfı "bugün yalnız soft delete sayesinde uykuda"
> diye kaydetmişti; `PruneOrphanAttachments` **gerçek bir hard-delete yoludur** ve
> zamanlayıcıya bağlıdır — yani uyku varsayımı bu FK için geçerli değil.
>
> **Bu belge bunu düzeltmez ve düzeltilmiş saymaz** — sahibi backend şerididir, teknik lidere
> raporlandı (§7). Bu tur içinde **çalıştırılarak doğrulanmadı**; kanıt statik okumadır
> (migration + zamanlayıcı kaydı + komut sorgusu + FK kilidi).

### 4.9 Cache blob'ları SQLCipher dışında, düz metin (TM-F13)

F5, aynanın yanına **ikinci bir kalıcı depo** ekledi: `$APPDATA/syncra/cache/quotes`
(teklif PDF'leri) ve `$APPDATA/syncra/cache/attachments` (drag-drop ve screenshot kuyruğu) —
`commands/files.rs:14-16,79,89`. Yazma yolu `write_atomically()` (`files.rs:662-686`):
`std::fs::write` + atomik `rename`. **Şifreleme yok.**

T1/I3'ün "çalınan laptop" garantisi SQLCipher'a dayanır ve **yalnız `syncra.db` ailesini**
kapsar. Bir teklif PDF'i ya da kuyruktaki bir ek, disk imajından hiçbir anahtar
gerektirmeden okunur. Bugünkü kontroller şunları **kapsıyor**, at-rest şifrelemeyi değil:
- **Wipe kapsamı (O67, KAPALI):** `wipe_local()` artık `SELECT DISTINCT path` → `remove_cached_blob`
  → `db::wipe` sırasını izliyor (`crates/syncra-sync/src/sync/mod.rs:183-200`) ve üç çağrı
  noktasının **üçü de** dönüştürüldü: farklı-kullanıcı login (`:311`), logout (`:397`),
  A25 403 `USER_DEACTIVATED` (`:1412`). Yani A kullanıcısının teklif PDF'leri B login
  olduğunda diskte kalmıyor. Negatif kontrol: `wipe_local` `db::wipe`'a geri indirgenince
  üç test de kendi panik mesajlarıyla kırmızı.
- **Yol doğrulaması:** `open_cached` canonicalize eder, sonucun cache kökünün **altında**
  kaldığını ve uzantısının bu uygulamanın kendi yazdığı listede olduğunu şart koşar
  (`files.rs:438-467`) — metinsel `contains("..")` kontrolünün yakalayamayacağı
  `cache/sub/../../secret.pdf` biçimi de böyle eleniyor.
- **Tavan:** `cached_files` ledger'ı + `run_retention` disk şişmesini sınırlar (D2).

**Kalan risk (AÇIK, ORTA):** aynı OS hesabındaki kötücül proses zaten keychain'i okuyabildiği
için (SINIR 3, kabul edilmiş) bu blob'lar ona yeni bir şey vermez. Fark **disk imajı /
çalınan laptop** senaryosundadır: orada SQLCipher tutuyor, cache tutmuyor. Bu, K9'un
"veri şifreli iner" ifadesiyle **kısmen çelişir** ve bilinçli bir karar olarak kaydedilmemiştir.
Seçenekler (uygulanmadı, §0.5): (a) blob'ları DB anahtarıyla şifreleyip açarken çözmek —
`open_cached`'in OS'e dosya yolu vermesiyle çelişir; (b) cache'i oturum sonunda düşürmek —
"çevrimdışı teklif aç" özelliğini öldürür; (c) sınırı belgeleyip kabul etmek. **Bu belge
bugün (c)'yi de karar saymıyor: karar verilmemiştir, madde AÇIK'tır.**

**ÖLÇÜLMEDİ:** bu turda canlı `$APPDATA` dizin dökümü **tekrarlanmadı**; §4.9 statik kod
okumasıdır. Gerçek bir cache dizininin içeriği ve dosya izinleri (ACL) ölçülmemiştir.

---

## 5. Bulgu Tablosu

Şiddet skalası ve statü sözlüğü PHASE-AUDIT §4/§4.1 ile aynı (🔴 kapatılmalı /
⬜ kabul edilmiş-kayıtlı). "Faz" = kapanışın ait olduğu faz.

| # | Şiddet | Bulgu | Nerede / Kanıt | Nasıl bulundu | Önerilen düzeltme | Faz | Statü |
|---|---|---|---|---|---|---|---|
| ~~TM-F1~~ | **KAPANDI 2026-08-31** | §9/2'nin istemci yarısı sağlanmıyor: 401 sonrası lokal DB cihazda kalıyor (yalnız token siliniyor); şartname kendi içinde çelişik (§5.5 "outbox korunur" ↔ §9/2 "tamamen wipe") | `sync/mod.rs:1001-1011`; wipe yalnız farklı-user login (`:187`) ve logout (`:238-251`) | Bağımsız denetim (RISK-1) + kod okuması — karar belgeye geçmiş, kodda karşılığı yoktu | Kullanıcı kararı: (a) §5.5 davranışı + şifreli-beklet kabul edilip §9/2 metni revize edilir, ya da (b) sunucu 401 gövdesine `reason: deactivated\|deleted` ayrımı eklenir ve istemci yalnız o durumda tam wipe yapar (outbox kaybı bilinçli). **Karar verildi ve uygulandı: (b)'nin daha keskin bir biçimi.** 401'e `reason` eklemek yerine sunucu deaktivasyon için **403 `USER_DEACTIVATED`** döndürüyor (`AuthService.php:355`); istemci yalnız o sinyalde wipe ediyor (`sync/mod.rs:1395` (koşul) + `:1412` (wipe)), çıplak 401'de outbox korunuyor. §3/2-A25 ve KARAR A25. | F6 | ✅ KAPALI — 3 test (1 pozitif, 2 negatif kontrol) |
| ~~TM-F2~~ | **KAPANDI 2026-08-31** | `notifications` tombstone'ları sahibe kapsamlanmıyor; başka kullanıcının silinmiş bildirim uuid'leri pull'da görünüyor | `SyncPullService.php:304-347` (kapsam yalnız conversation_user), şema `...100002:26-36` | Kod okuması (F1 denetimi): `deletionsFor()` kapsam sorgusu ile şema karşılaştırması | **Uygulandı:** migration `2026_09_01_100010_add_owner_key_to_sync_deletions_table`, `SyncDeletionObserver.php:51` sahip yazımı, `SyncPullService.php:542` filtresi. Filtre `owner_key`'in **eşleşmesini** şart koşuyor ("başkasınınki değil" değil) — sahipsiz eski satırlar da sızmaz. | F6 | ✅ KAPALI — hedefli pull testleri |
| ~~TM-F3~~ | **KAPANDI 2026-08-31** | `tracing`/log filtresi yok; log plugin'i varsayılan (DEBUG dahil) seviyede F3'ten beri diske yazıyor; PII maskesi yazılmamış | `lib.rs:78`; canlı `Syncra.log` (keyring DEBUG satırları; bugün sır/PII gözlenmedi) | **Canlı ölçüm** — gerçek makinede `Syncra.log` içeriği okundu (keyring DEBUG satırları) | İki adım: (1) F6'da seviye filtresi (`Builder::level(Info)` + hedef bazlı susturma — keyring DEBUG dahil), (2) email/telefon maskeleme katmanı; **Her iki adım da `logging.rs`'te:** (1) `level_for_build()` → release'de `Info`; (2) `mask_pii()` → `[email]`/`[phone]`. Aynı dispatch'e bağlı. **F5-6 clipboard'ın ön koşulu böylece sağlandı.** | F6 (1) + F5 öncesi (2) | ✅ KAPALI — 4 test + negatif kontrol |
| TM-F4 | **DÜŞÜK** | `device_fingerprint` istemci beyanı: kendi hesabı için cihaz-başına-tek-token kuralı atlatılabilir → token bolluğu (çapraz hesap etkisi YOK) | `DeviceTokenRequest.php:38`, `DeviceTokenService.php:110-116`, test `DeviceTokenTest.php:119` | Kod okuması — `DeviceTokenRequest` doğrulaması ile `DeviceTokenService` silme kapsamının karşılaştırılması | Kullanıcı başına desktop token tavanı (ör. 10; aşımda en eskisi düşer) — şartnamede yok, onay gerektirir | F6 adayı (onaylıysa) | ⬜ KAYITLI |
| TM-F5 | **BİLGİ** | DB anahtarı 2×UUIDv4'ten türetiliyor → gerçek entropi 244 bit (UUID başına 6 bit sürüm/varyant sabiti), yorum "256 bits" diyor | `keystore.rs:110-123` | Kod okuması — yorumdaki "256 bits" iddiasının üretim koduyla karşılaştırılması | Kriptografik olarak fazlasıyla yeterli; yorum düzeltilsin ya da `getrandom` ile 32 ham bayt üretilsin | F6 (yorum) | ⬜ KAYITLI |
| TM-F6 | **BİLGİ** | CSP/API host fallback'i sessiz `http://localhost:8000` — eksik `.env` ile paketlenen build sessiz kırılır; lokal port dinleyen prosese kimlik bilgisi POST'u uç senaryosu | `tauri.mjs:58-67`, `http.ts:32` | Kod okuması — `tauri.mjs` fallback dalının izlenmesi | `build` alt komutunda `VITE_API_URL` yoksa fallback yerine **hata ver** (`dev`'de fallback kalabilir) | F7 (paketleme) | ⬜ KAYITLI |
| TM-F7 | **BİLGİ** | Webview bellek içi token tasarımı: auth köprüsü bağlanınca token webview belleğine girecek; XSS artık riski CSP + `dangerouslySetInnerHTML` yasağına dayanacak | `http.ts:9-23` (bugün `setDeviceToken` çağrılmıyor — yüzey henüz boş) | Tasarım incelemesi (kod yolu o gün henüz boştu) | F4'te köprü bağlanırken bu satır yeniden değerlendirilecek; ek sertleştirme (token'ı hiç webview'a vermemek, tüm HTTP'yi Rust'a taşımak) mimari değişiklik olur — kayda geçirildi | F4'te yeniden ele al | ⬜ KAYITLI |
| TM-F8 | **BİLGİ** | `shell:allow-open` `http://*` kabul ediyor (TLS'siz URL tarayıcıda açılabilir) | `default.json:22-24` | Kod okuması — `capabilities/default.json` URL kısıtı | Kapalı devrede API bile HTTP olabilir; kabul edilebilir. `https://*`'a daraltma ancak dağıtım TLS'e geçince | F7 | ⬜ KAYITLI |

| ~~TM-F9~~ | **KAPANDI 2026-09-01** | Deep link'in **ikinci** savunma hattı kapalı değildi: `ROUTES[entity]` prototip zincirini yürüyor (`constructor` → boxed `String`; `__proto__`/`valueOf`/`hasOwnProperty`/`toLocaleString` → Tauri callback'i içinde `TypeError`), `id` hiç doğrulanmıyor (`../admin` → `/deals/../admin`) | `deeplink-routes.ts` (düzeltme öncesi); altı davranışın **hepsi** tek tek ölçüldü | **Elle ölçüm** — §9/5 fuzz'ı doğru katmana taşınırken TS hattı da koşuldu (defter O89) | `Object.hasOwn(ROUTES, entity)` + `ID_PATTERN = /^[0-9]{1,12}$/` — `deeplink-routes.ts:76,99-102`. Kabul edilen küme daralmadı | F5 ara turu | ✅ KAPALI — `deeplink-routes.test.ts:74,94` (+ `:113` daralmadığın kontrolü); negatif kontrol: TS guard'ları kaldırılınca 2 test kırmızı |
| TM-F10 | **DÜŞÜK** | URL normalizasyonu `id`'yi kaydırabiliyor: `syncra://deal/1/../2` → deal **2**; `deal/9999999999999/../42` → deal 42. Linkte görünen id, açılan id değil | `deep_link.rs:170-190`; sınır `normalisation_can_change_the_id_but_never_the_entity` (`:809`) | **Birim test (fuzz, 83 örnek)** — korpus `Url::parse → as_str()` katmanından besleniyor; `the_fuzz_corpus_reaches_no_third_outcome` (`:675`) | **Düzeltilmez (karar).** Kabuğun kendi normalizasyonunu yazması `url` crate'ini ikinci kez tahmin etmektir (parser-differential, daha kötü sınıf); entity url'in **host**'u olduğu için allowlist sağlam; kayma yalnız `SyncScope`'un zaten filtrelediği aynaya ulaşır. §4.6/b | — | ⬜ KABUL EDİLDİ — testle çivilendi; karar `SYNCDESKTOP.md` §9/5'te |
| ~~TM-F11~~ | **KAPANDI 2026-09-01** | `attachment_is_image` pull satırında `str_starts_with($mime,'image/')` ile türetiliyordu; `image/svg+xml` için **`true`**, oysa inline allowlist onu bilerek dışarıda bırakıyor (SVG inline render'ı XSS vektörü). Docblock aynı anda *"K7: one definition of is_image"* diye **uyum iddia ediyordu** | `SyncPullService.php:434-465` (docblock) vs `:503` (kod); allowlist `config/chat.php:90-95`; niyet `config/chat.php:19-22` | **Kod okuması** — A29 kablolanırken iki sunucu tanımının karşılaştırılması ("yalan söyleyen docblock" sınıfı) | `SyncPullService.php:503` → `$attachment->isInlineEligibleImage()`; satır `DB::table()` yerine Eloquent'ten çekiliyor ki tanım kaynağından okunabilsin | F5 ara turu | ✅ KAPALI — `SyncPullMessageAttachmentTest.php:142` (SVG), `:183` (dört allowlist türü), `:255` **parite testi** (pull satırı ↔ `AttachmentResource`) |
| TM-F12 | **BİLGİ** | Sunucuda **hâlâ iki** `is_image` tanımı var: `ChatAttachmentResource::payload():48` gevşek prefix'i kullanmaya devam ediyor ve **web sohbet balonunun tanımı budur** (`Chat/MessageResource.php:114`). K7'nin "tek tanım" şartı sağlanmıyor; ayna ile web yüzeyi bir SVG eki için zıt cevap verir | `ChatAttachmentResource.php:48` · `AttachmentResource.php:30` · `SyncPullService.php:503` | **Kod okuması** — TM-F11 düzeltmesi doğrulanırken gevşek tanımın yerinde durduğu görüldü | Gevşek tanımı `isInlineEligibleImage()`'a taşımak (web davranışını değiştirir → onay gerekir, §0.5). Ayrıca `SYNCDESKTOP.md` §4.4 A29 *"`ChatAttachmentResource::payload()`'dan düzleştirilir"* diyor, kod artık `AttachmentResource`'un tanımını kullanıyor → şartname metni kodla uyumsuz (§7) | Onay gerektirir | ⬜ KAYITLI — bugün üç bağımsız katman erişimi kapatıyor (SVG yüklenemiyor · sunucu inline servis etmiyor · mapper `is_image`'ı `false` düşürüyor); §4.7 |
| TM-F13 | **ORTA** | F5'in cache blob'ları (teklif PDF'leri, drag-drop/screenshot kuyruğu) **SQLCipher dışında, düz metin** yazılıyor. T1/I3'ün "çalınan laptop" garantisi bu dosyaları kapsamıyor | `commands/files.rs:14-16,79,89` (kökler), `:662-686` (`std::fs::write` + `rename`) | **Kod okuması** (bu tur). Canlı dizin dökümü ve ACL kontrolü **ÖLÇÜLMEDİ** | Seçenekler §4.9'da (blob şifreleme · oturum sonu düşürme · sınırı karara bağlayıp kabul etme). **Hiçbiri seçilmedi; karar verilmemiştir** — §0.5 gereği kendiliğinden uygulanmaz | Onay gerektirir | 🔴 AÇIK — K9'un "veri şifreli iner" ifadesiyle kısmen çelişiyor; §4.9 |
| TM-F14 | **BİLGİ** (sızıntı yok) | A29'un *"`AttachmentPolicy` ile birebir aynı"* gerekçesi **doğrulanmadı**: Policy iki dallı (`attachable_id` NULL → yalnız yükleyen; `Message` → konuşma üyeleri) ve üretim yolunda `attachable_*` **hiç yazılmıyor**, yani daima birinci dal çalışıyor. Metadata pariteği aslında `MessageResource`/`ChatAttachmentResource` iledir | `AttachmentPolicy.php:43-79` · `AttachmentUploadService::store()` (`Attachment::create` `attachable_*` yazmıyor) · `MessageService.php:156-163` · `AttachmentApiTest.php:100` | **Kod okuması** (bu turun A29 sızıntı değerlendirmesi) | Sızıntı yönünden düzeltme **gerekmiyor** — Policy `SyncScope`'tan dar. Düzeltilecek olan A29'un gerekçe cümlesi (§7) ve `attachable_*`'ın hiç yazılmaması (backend şeridi, aşağıdaki uyarı) | Şartname metni: teknik lider | ⬜ KAYITLI — §4.8/2 |
| ~~TM-F15~~ | **KAPANDI 2026-09-01** | Gizlilik: `wipe` cache blob'larını silmiyordu — A kullanıcısının teklif PDF'leri B login olduğunda diskte kalıyordu (defter O67) | `sync/mod.rs` `wipe_local` (düzeltme öncesi yalnız `db::wipe`) | **Kod okuması** (defter O67 denetimi) | `wipe_local()` → `SELECT DISTINCT path` → `remove_cached_blob` → `db::wipe` (`sync/mod.rs:183-200`); **üç** çağrı noktası da dönüştürüldü: farklı-kullanıcı login (`:311`), logout (`:397`), A25 403 (`:1412`) | F5 | ✅ KAPALI — üç wipe testi blob assert'iyle genişletildi; **negatif kontrol:** `wipe_local` `db::wipe`'a indirgenince üçü de kendi panik mesajıyla kırmızı |
| ⚠️ (numarasız) | **veri kaybı** (kapsam dışı) | `attachable_id` hiç yazılmadığı için `attachments:prune-orphans` **her mesaj ekini** aday sayar; komut `routes/console.php:59`'da `--force` ile günlük 03:47'ye **zamanlıdır**, kendi docblock'u ise hâlâ *"zamanlayıcıya KAYITLI DEĞİL"* diyor | `PruneOrphanAttachments.php:12-17` (docblock) vs `:120` (sorgu) vs `routes/console.php:59` (zamanlama); `Attachment.php:74-77` (`scopeUnattached`) | **Kod okuması** — TM-F14 izlenirken bulundu. **Çalıştırılarak doğrulanmadı** | Bu belgenin sahipliğinde değil; **düzeltilmiş sayılmaz**. Teknik lider'e raporlandı (§7) | Backend şeridi | 🔴 AÇIK — masaüstü etkisi: ayna kartı baytları gitmiş dosyaya işaret eder |

Ayrıca kayıt: §4.5 (FK cascade) bulgu değil **kilitlenmiş risk** statüsündedir (testli);
§4.2 (A22) bulgu değil **onaylı tasarım kararıdır** — ikisi de burada izlenir, numara almaz.

**Statü dağılımı — 16 satır (15 numaralı + 1 numarasız):**
· ✅ **kapandı 6:** TM-F1, F2, F3, F9, F11, F15
· ⬜ **kabul edilmiş / kayıtlı 8:** TM-F4, F5, F6, F7, F8, F10, F12, F14
· 🔴 **açık 2:** TM-F13 (şifresiz cache) ve numarasız prune uyarısı.

Kullanıcı onayına bağlı olanlar (§0.5 — kendiliğinden uygulanmaz): TM-F4, TM-F6, TM-F12,
TM-F13. Karara bağlanmış ve **bilinçle düzeltilmeyen** tek madde TM-F10'dur.

---

## 6. F6 Kapanışı — §9 Maddelerinin Faz Eşlemesi

Bu tablo `SYNCDESKTOP.md` §9 durum sütunuyla **eşitlenmiştir** (2026-09-01, HEAD `86f6388`).
Bir madde ancak **Karar + Kod + Test** üçü de sağlandığında kapanır.

| §9 maddesi | Durum | Kanıt / kapanış |
|---|---|---|
| 1. Device token taşımayan istemci (cookie oturumu dahil) → 403 | ✅ KAPALI | §3/1. Route zinciri + `EnsureDeviceToken`'ın `instanceof PersonalAccessToken` şartı; `DeviceTokenTest.php:247,263` |
| 2. 403 `USER_DEACTIVATED` → wipe; genel 401 → outbox korunur | ✅ KAPALI (2026-08-31, A25) | §3/2-A25. `AuthService.php:355` + `sync/mod.rs` A25 dalı; `wire_contract.rs` 3 test (**2'si negatif kontrol**) |
| 3. DB düz `sqlite3` ile açılamıyor | ✅ KAPALI | §3/3. `tests/encryption.rs:17-48,53-62` + 2026-08-31 canlı `od -c` okuması |
| 4. Keychain'de anahtar; app data'da anahtar/token dosyası yok | ✅ KAPALI (sırlar için) | §3/4 (2026-08-31 canlı dizin dökümü). **Kapsam daraldı:** F5'in şifresiz *içerik* blob'ları ayrı madde → TM-F13 / §4.9 |
| 5. Deep link reddi (fuzz 83 örnek) | ✅ KAPALI (**bu turda döndü**) | §3/5. `deep_link.rs` üç kural + doğru katmanda 83 örnek fuzz; ikinci hat TM-F9 ile kapatıldı; id kayması TM-F10 olarak **karara bağlandı** |
| 6. Clipboard içeriği log/diske yazılmıyor | 🔶 **Kod + test var, kanıt bu turda yazıldı** | §3/6 — dört kontrol + kaynak seviyeli assert (`clipboard.rs:378`). **`SYNCDESKTOP.md` §9 satırı hâlâ `⬜ DEĞERLENDİRİLEMEZ — F5-6` diyor**; şartname bu belgenin sahipliğinde değil → §7/1 |
| 7. CSP ve capabilities dar; `shell` yalnız `open` | ✅ KAPALI (bugünkü yüzey için) | §3/7, F5 sonrası yeniden okundu. Kalıntı: `updater:default` plugin'siz duruyor (S4) |
| 8. Updater imza doğrulaması; imzasız manifest reddi | ⬜ DEĞERLENDİRİLEMEZ — F7 | §3/8. Bugün fail-closed: `plugins.updater` bloğu yok, plugin yalnız release'de kayıtlı, `pubkey` zorunlu |
| 9. Log PII filtresi (email/phone masked) | ✅ KAPALI | §3/9. `logging.rs` — `level_for_build()` + `mask_pii()`, aynı dispatch; 4 test + negatif kontrol |
| 10. Bu doküman (STRIDE + PHASE-AUDIT formatı) | ✅ VAR — canlı | Sürüm 2 bu revizyondur. §5'te **16 satırlık bulgu/düzeltme tablosu** |

**Sayım: 10 maddenin 8'i ✅ KAPALI.** Kalan ikisi — madde 6 (kanıtı bu belgede yazıldı,
şartname satırı teknik liderin güncellemesini bekliyor) ve madde 8 (F7).

**F6'nın bu dokümandan doğan iş listesi — 2026-09-01:**
~~TM-F1 (A25)~~ ✅ · ~~TM-F2 (`owner_key`)~~ ✅ · ~~TM-F3 (log seviye + PII maskesi)~~ ✅ ·
~~TM-F9 (deep link ikinci hattı)~~ ✅ · ~~TM-F11 (`is_image` pull satırı)~~ ✅ ·
~~TM-F15 (O67 wipe/blob)~~ ✅
**Kalan:** TM-F13 (şifresiz cache — karar gerekiyor) · TM-F12 (ikinci `is_image` tanımı —
onay gerekiyor) · TM-F5 (yorum düzeltmesi) · §7'deki şartname senkronizasyonları.

> **F6 şeridine dikkat:** yukarıdaki tabloda ✅ görünen bir maddeyi **yeniden uygulamaya
> kalkma** — önce `docs/DESKTOP-OPEN-ITEMS.md`'ye bak, kapanma kanıtı (dosya:satır + test
> adı) orada. TM-F4, TM-F6, TM-F12 ve TM-F13 kullanıcı onayına bağlı önerilerdir (§0.5
> gereği kendiliğinden uygulanmaz).

---

## 7. `SYNCDESKTOP.md`'ye Taşınması Gereken Düzeltmeler

Bu belge şartnameyi **değiştirmez** (sahiplik teknik liderdedir). Bu turda kodla
karşılaştırıldığında uyumsuz bulunan üç madde:

1. **§9 madde 6 durum sütunu** hâlâ `⬜ DEĞERLENDİRİLEMEZ — F5-6` diyor. F5-6 yazıldı,
   ölçüldü ve kanıtı bu belgenin §3/6'sındadır. Satırın güncellenmesi gerekiyor —
   ya da kalan bir eksiklik varsa **ne olduğu** yazılmalı.
2. **§4.4 KARAR A29, cümle 2:** *"Alanlar `ChatAttachmentResource::payload()`'dan
   düzleştirilir"*. Kod artık `Attachment::isInlineEligibleImage()` (yani
   `AttachmentResource`'un tanımı) kullanıyor ve bu **kasıtlıdır** (TM-F11). Cümle olduğu
   gibi bırakılırsa şartname yanlış kaynağa atıf yapar; K7 "mevcut sözleşme kazanır" der,
   ama burada iki sözleşme birbiriyle çelişiyor. Ayrıca `ChatAttachmentResource::payload()`
   gevşek tanımı korumaya devam ediyor (TM-F12) — yani "tek tanım" iddiası bugün **yanlış**.
3. **§4.4 KARAR A29, son cümle:** *"Satır kapsamı `SyncScope`'un üyelik filtresinden geçtiği
   için `AttachmentPolicy` ile birebir aynıdır — yeni sızıntı yüzeyi yoktur."* **Sonuç
   doğru, gerekçe yanlış** (§4.8/2): `AttachmentPolicy` üretim yolunda üyelik dalına hiç
   girmiyor (`attachable_*` yazılmıyor), fiilen `SyncScope`'tan **dar**dır. Metadata
   pariteği `MessageResource`/`ChatAttachmentResource` iledir. Gerekçe cümlesi
   düzeltilmeli — yanlış gerekçe, bir sonraki denetimin yanlış yere bakmasına yol açar.

Ayrıca **kapsam dışı, backend şeridine ait bir uyarı zinciri** §4.8'in sonunda ve §4.5'te
kayıtlıdır — üç halka birbirine bağlı:

4. `attachable_id` üretim yolunda **hiç yazılmıyor** → `attachments:prune-orphans` her mesaj
   ekini aday sayıyor → komut `routes/console.php:59`'da `--force` ile **günlük zamanlı** →
   `messages.attachment_id` FK'sı `SET NULL` olduğu için silme, **kapsam içindeki** bir
   satırı `sync_version` bump'ı olmadan değiştiriyor (§2.8 probe D3 sınıfı) → ayna, olmayan
   bir eke ait kartı süresiz taşıyor. Buna bağlı olarak `SyncSchemaTest`'in katman 2
   muafiyet gerekçesi (*"`attachments` is outside the sync scope entirely"*) **A29'dan sonra
   bayatlamıştır**.

Bu belge bunların hiçbirini düzeltmez ve düzeltilmiş saymaz; **çalıştırılarak da
doğrulanmadılar** — kanıt statik okumadır.
