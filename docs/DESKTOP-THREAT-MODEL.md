# DESKTOP-THREAT-MODEL — Faz 6: Syncra Desktop STRIDE Tehdit Modeli

> **Statü: F6 GÜVENLİK ÇIKTISI (canlı doküman).** `SYNCDESKTOP.md` §9'un son maddesinin
> ("`docs/DESKTOP-THREAT-MODEL.md` — STRIDE tablosu, `PHASE-AUDIT.md` formatı") teslimatıdır.
> Biçim ve şiddet skalası `docs/PHASE-AUDIT.md`'den alınmıştır (YÜKSEK / ORTA / DÜŞÜK / BİLGİ;
> bulgu numaralandırması TM-F*). İçerik kopyalanmamıştır — o doküman tamamlanmış **web**
> ürününün denetimidir, bu doküman **masaüstü istemci + sync API** yüzeyini modeller.
>
> İlgili sözleşmeler: `SYNCDESKTOP.md` (§4.3, §6.3, §9, K9, K10),
> `docs/DESKTOP-SYNC-PROTOCOL.md` (§2.7, §2.8, §3, §8), `docs/DESKTOP-ARCHITECTURE.md`
> (§5, EK 1/2/3, kararlar A20–A24), `docs/AUTH-FLOWS.md`. Tarih: 2026-08-31.
>
> **Doğrulama yöntemi (PHASE-AUDIT geleneği):** Her iddia `dosya:satır` ile desteklenir.
> Referans noktası HEAD `35da69b` + o günkü çalışma ağacı (F6 dalgasının iki paralel şeridi
> `SyncPullService.php`, `SyncPullTest.php`, `SyncPushTest.php`, `commands/sync.rs`, `lib.rs`
> üzerinde çalışıyordu; satır numaraları bu çalışma ağacından okunmuştur ve o dosyalarda
> commit sonrası kayabilir). Statik okumaya ek olarak **canlı kanıt** toplandı: gerçek
> `$APPDATA` dizin dökümü, DB dosyasının ilk 16 baytının ham okuması ve çalışan uygulamanın
> ürettiği log dosyasının içeriği (§3 madde 3-4-9). Doğrulanamayan hiçbir şey "sağlanıyor"
> olarak yazılmadı; ölçülemeyenler açıkça **DOĞRULANMADI** etiketi taşır.

---

## 0. Kapsam Kuralı — Bugünkü Gerçek Hâl

Bu model sistemin **2026-08-31 itibarıyla var olan** hâlini analiz eder: F1 (backend sync +
device auth), F2 (`syncra-sync` crate), F3 (Tauri kabuğu + veri katmanı) tamamlanmış;
**F4 (offline UX) ve F5 (OS özellikleri) yazılmamıştır.**

**F5 yüzeyleri — tray, global hotkey, deep link yönlendirme, drag-drop işleyici, clipboard
yakalama, screenshot→ticket — bugün kod olarak yoktur.** Bu yüzeyler aşağıda
**DEĞERLENDİRİLEMEZ-F5** olarak işaretlenir ve var gibi analiz edilmez; F5'in her mini-raporu
sonrasında bu doküman güncellenmelidir. Aynı ilke ters yönde de uygulanır: var olmayan bir
koruma (ör. henüz yazılmamış PII maskeleme) "var" sayılmaz.

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

### 1.2 Güven sınırları

```
[İnternet/LAN] ──TLS(prod)── [Laravel API + Reverb]          ← SINIR 1: ağ / sunucu
                                    ▲ bearer
[Rust çekirdeği: syncra-sync + Tauri komutları]              ← SINIR 2: webview / native
   │ rusqlite (proses içi)      ▲ invoke() = ipc://localhost
[SQLCipher DB dosyası]      [WebView2 (React UI)]
   ▲ PRAGMA key                     │ CSP: tauri.conf.json:28
[OS keychain]                       └ origin: http://tauri.localhost
                                                              ← SINIR 3: uygulama / OS
[Diğer lokal prosesler, diğer OS kullanıcıları, disk hırsızlığı]
```

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
kabul edilmemiş, iş gerekiyor · **DEĞERLENDİRİLEMEZ-F5/F7** = yüzey henüz yok, o fazda
yeniden ele alınacak.

| # | Kategori | Saldırgan / senaryo | Yüzey | Bugünkü kontrol (kanıt) | Artık risk | Durum |
|---|---|---|---|---|---|---|
| S1 | Spoofing | Anonim: `POST /api/auth/device` üzerinden brute-force / hesap numaralandırma | Cihaz login ucu | Web login ile **paylaşılan** keyed lockout (email+IP, 5 deneme, 1→2→…→60 dk eskalasyon) — `DeviceTokenService.php:147-197`; hata mesajları web ile birebir aynı (numaralandırma önlenir, sınıf yorumu satır 42-49); testler `tests/Feature/Sync/DeviceTokenTest.php:133,160,182` | Dağıtık IP'lerden yavaş deneme (web ile aynı kalıntı) | KAPALI |
| S2 | Spoofing | İçeriden: cookie oturumu ile sync uçlarına erişim (TransientToken zaafı, protokol §3.3 K-A) | `/api/sync/*` | `EnsureDeviceToken` gerçek `PersonalAccessToken` sınıfını şart koşar (`backend/app/Http/Middleware/EnsureDeviceToken.php:42-59`), alias kayıtlı (`bootstrap/app.php:191,200`); test `DeviceTokenTest.php:263` (bilinçli `actingAs()` ile zaafı yeniden üretip 403 doğrular) | — | KAPALI |
| S3 | Spoofing | İçeriden: sahte `device_fingerprint` beyanı | Cihaz login ucu | Biçim doğrulaması `size:64` + hex (`Http/Requests/Auth/DeviceTokenRequest.php:38`); eski-token silme **yalnız kendi token'larına** kapsamlı (`DeviceTokenService.php:114-116` — "fingerprint is a device identifier, not an authorisation") | Kendi hesabı için cihaz-başına-tek-token kuralı atlatılır → token bolluğu (bkz. TM-F4). Başka hesabın token'ına dokunamaz | AÇIK (DÜŞÜK) |
| S4 | Spoofing | Ağda: sahte update manifest'i | Updater | Bugün updater fiilen yok: `tauri.conf.json`'da `plugins.updater` bloğu **yok** (dosyada `plugins` anahtarı hiç yok), plugin `#[cfg(not(debug_assertions))]` arkasında (`desktop/src-tauri/src/lib.rs:58-62`); bloksuz release build hiç açılmaz (fail-closed) | İmza doğrulama akışı F7'de kurulacak ve o zaman test edilecek | DEĞERLENDİRİLEMEZ-F7 |
| T1 | Tampering | Fiziksel erişim: DB dosyasını değiştirme/okuma | `syncra.db` | SQLCipher — `PRAGMA key` ilk ifade (`db/mod.rs:38`), yanlış anahtar gürültülü hata (`db/mod.rs:41-43`, test `tests/encryption.rs:53-62`); anahtar keychain'de | Aynı OS hesabındaki kötücül proses keychain'i okuyabilir (SINIR 3, kabul edilmiş) | KAPALI |
| T2 | Tampering | İçeriden: outbox'u/lokal DB'yi elle değiştirip sahte mutasyon push'lamak | Push ucu | Sunucu istemci beyanına güvenmez: her mutasyonda Policy + FormRequest + horizontal boundary (`MutationApplier.php:256,333,407,500,521`); `changed_fields` dışı alan yazılmaz; yasak alanlar 422 (`SyncPushTest.php` matrisi) | Kullanıcı kendi YAPABİLDİĞİ işlemleri farklı bir istemciden yapmış olur — yetki kazanımı yok | KAPALI |
| T3 | Tampering | Şema evrimi: FK `ON DELETE CASCADE` sessiz veri kaybı (protokol §2.8, probe D3: cascade ne trigger ne Eloquent event tetikler) | `sync_deletions` bütünlüğü | İki katmanlı mimari kilit: `DELETE_RULE` envanteri `assertSame` ile sabit (`tests/Feature/Sync/SyncSchemaTest.php:62,104`), cascade ebeveynlerine hard-delete yolu yokluğu assert'li (`SyncSchemaTest.php:146`) | Bu bir **veri kaybı** riskidir, sızıntı değil; bugün yalnız soft delete sayesinde uykuda. `forceDelete`/KVKK purge eklenirse test kırmızıya düşer ve tasarım kararı zorlar | KAPALI (kilitli) |
| T4 | Tampering | Yanlış/eksik `.env` ile üretilen CSP | Build zinciri | CSP `frontend/.env`'den build-time üretilir (`desktop/scripts/tauri.mjs:46-97`), frontend bundle ile aynı kaynak/aynı öncelik; `https://crm.example.com` ile doğrulanmış (ARCHITECTURE EK 2) | `VITE_API_URL` yok/bozuksa sessiz `http://localhost:8000` fallback'i (`tauri.mjs:67`) — bkz. TM-F6 | AÇIK (BİLGİ) |
| R1 | Repudiation | İçeriden: "o işlemi ben yapmadım / masaüstünden yapılmadı" | Audit | Cihaz login'i `session_logs`'a `channel='desktop'` + cihaz adı + IP yazar (`DeviceTokenService.php` `logDeviceLogin`, test `DeviceTokenTest.php:79`); her applied mutasyon `activity_log`'a `causer` = token sahibi, `properties.channel='desktop'`, `batch_id` damgalar (`app/Sync/SyncActivityContext.php:10`, `SyncPushService.php:113-142`) | Audit satırı yazılamazsa login yine başarılı (bilinçli takas, `DeviceTokenService.php` yorum) — web listener'larıyla aynı | KAPALI |
| I1 | Info disclosure | İçeriden: izinsiz modülün verisini pull'lamak | Pull ucu | Modül `.view` yoksa tablo manifest'te ve pull'da **hiç yok** (`SyncScope.php:45-59`, `GlobalSearchService` ilkesi); satır kapsamlı 4 tablo: notifications sahibe, conversations/messages üyeliğe, saved_views sahibi+paylaşım, settings yalnız `is_public` (`SyncScope.php:70-91` — "stolen laptop should not carry" yorumu) | `sync_deletions` istisnası → I2 | KAPALI |
| I2 | Info disclosure | İçeriden: **başkasının silinmiş bildirim uuid'lerini görmek** | Pull `deletions` dizisi | YOK — `deletionsFor()` yalnız `conversation_user` için kapsam uygular (`SyncPullService.php:304-347`, kapsam 330-334); `notifications` tombstone'ları tabloya erişimi olan (izin `notifications.view`, `SyncableRegistry.php:118-121`) **her kullanıcıya** döner; şema sahip kolonu taşımaz (`migrations/2026_09_01_100002_...:26-36`), satır silindiği için sahibi geriye dönük çözülemez | Sızan şey yalnız **varlık** (uuid + sync_version) — içerik, tip, atıf yok. Yine de kapsam ilkesinin (I1) deliğidir | AÇIK → TM-F2 |
| I3 | Info disclosure | Fiziksel erişim: disk imajından CRM verisi | `syncra.db`, WAL | Şifreli dosya; başlıkta `SQLite format 3` yok — **canlı doğrulandı** (§3/3) ve regresyon testi düz metin satır sızıntısını da tarar (`tests/encryption.rs:17-48`) | WAL/SHM de SQLCipher kapsamında (aynı dosya ailesi); `wipe()` DELETE tabanlıdır ama boşalan sayfalar da şifrelidir | KAPALI |
| I4 | Info disclosure | Fiziksel erişim: diskte düz token/anahtar dosyası | `$APPDATA`, `$LOCALAPPDATA` | Dizin dökümü (§3/4): yalnız `syncra.db(-wal/-shm)`; token/anahtar dosyası yok; sırlar Credential Manager'da | Log dosyası keyring **girdi adlarını** yazıyor (değerlerini değil) → TM-F3'ün parçası | KAPALI |
| I5 | Info disclosure | Webview XSS: bellek içi token'ı sızdırmak | `desktop/src/platform/http.ts` | Token webview'da **yalnız bellekte** tutulacak şekilde tasarlı (`http.ts:9-23`), kalıcı kopya keychain'de; CSP `connect-src` yalnız kendi API/Reverb origin'i (`tauri.conf.json:28`) → sızdırma kanalı dar; kalite çizgisi `dangerouslySetInnerHTML` yasağı (PHASE-AUDIT F5 kararı) | Bugün `setDeviceToken()` hiçbir yerden çağrılmıyor (auth köprüsü bağlanmadı) — yüzey fiilen boş; F4'te dolacak → TM-F7 | AÇIK (BİLGİ, izlenecek) |
| I6 | Info disclosure | `sync_idempotency.result_json` içinde sunucu satırları | Sunucu DB | Kullanıcı bazlı anahtar; `logs:prune` 7 günde budar (`app/Console/Commands/PruneLogs.php:184-190`); tabloya API yüzeyi yok | — | KAPALI (BİLGİ) |
| D1 | DoS | İçeriden: sync uçlarını döngüye sokmak | Sync API | `throttle:30,1,sync` (manifest+pull), `throttle:20,1,sync-push` (`routes/api.php:127-135`); push batch ≤200 mutasyon ≤2 MB, pull yanıtı 5 MB kesme (şartname §4.4, `SyncPushTest`/`SyncPullTest` matrisi) | `sync_counter` küresel mutex'i (K-B) altında saldırgan yazma dalgası diğer yazarları kilit beklemesine sokabilir; P4a retry + throttle bunu sınırlar — ölçülmüş bir sorun değil | KAPALI |
| D2 | DoS | Kendi kendine: lokal disk şişmesi | Lokal DB | K8 tavanları + `retention_maintenance()` + `WriteBlocked` (crate, `SYNCDESKTOP.md` §5.6; `SyncRetentionTest.php` sunucu tarafı) | — | KAPALI |
| E1 | Elevation | İçeriden: `can.*` istemci bayraklarını `true` yapıp yetkisiz yazma | Lokal veri katmanı | Bayraklar **zaten** permissive `true` (KARAR A22, `desktop/src/platform/data/mappers.ts:30-33,190,330,388,406,496`) çünkü otorite istemci değil: push'ta sunucu Policy'leri reddeder (A14 3. katman, `MutationApplier.php` Gate çağrıları). İstemci tarafı izin **hiçbir zaman güvenlik kontrolü değildir** | Bedel güvenlik değil UX: red, push anında görünür; Conflict Inbox'un bunu anlaşılır göstermesi F4 yükümlülüğü (EK 3 A22) | KAPALI (tasarım gereği) |
| E2 | Elevation | İçeriden/webview: Tauri capability'leri üzerinden OS'e taşma | IPC / plugin yüzeyi | Dar capability seti (`capabilities/default.json:7-29`): `shell` yalnız `allow-open` + URL kısıtı (22-24), `fs` yalnız iki kök scope (26-28) — üstelik hiçbir `fs:allow-*` işlem izni verilmemiş (scope tek başına işlem açmaz, şartnameden bile dar); `clipboard-manager:allow-read-text` **verilmemiş** (K10; açıklama satır 4); `core:window` yalnız set-focus/show/hide (8-10) | Runtime clipboard izni mekanizması F5-6'da tasarlanacak — o tasarım bu tabloyu günceller | KAPALI (bugünkü hâliyle) |
| E3 | Elevation | Token yaşam döngüsü: deaktive/silinen kullanıcının token'ı çalışmaya devam eder mi | Sunucu | Anında iptal: `toggleActive` / `delete` / **`resetPassword`** içinde `tokens()->delete()` (`app/Services/Users/UserService.php:203,230,251` — reset-password, protokolün D7 düzeltmesi); şifre değişiminde SPA'dan → tümü, masaüstünden → kendisi hariç tümü (`app/Services/Auth/AuthService.php:281-283`, TransientToken tuzağı çözülmüş); testler `DeviceTokenTest.php:203,225`, `DevicePasswordChangeTest.php` | İstemci tarafı temizlik eksik → §3/2, TM-F1 | KAPALI (sunucu) / AÇIK (istemci) |
| — | (tümü) | Tray, global hotkey, deep link yönlendirme, drag-drop, clipboard yakalama, screenshot | F5 yüzeyleri | Kod yok. Mevcut ilgili kalıntılar: deep-link/global-shortcut/clipboard plugin'leri **kayıtlı ama bağlantısız** (`lib.rs:64-78`; deep link handler'ı yok — `lib.rs:34-43` yorumu; `tauri.conf.json`'da `plugins.deep-link` şema kaydı da yok → `syncra://` OS'e kayıtlı değil); ana pencerede `dragDropEnabled: true` (`tauri.conf.json:24`) ama dinleyici yok | Her madde F5'in kendi mini-raporunda analiz edilip bu tabloya işlenecek | DEĞERLENDİRİLEMEZ-F5 |

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

2. **Deaktive/silinen kullanıcı → 401 → lokal DB + keychain tamamen wipe (test).**
   **KISMEN — sunucu yarısı KAPALI, istemci yarısı AÇIK (TM-F1).**
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

5. **Deep link regex reddi (fuzz 50 örnek).** **DEĞERLENDİRİLEMEZ-F5.** Handler yok
   (`lib.rs:34-43` — "deep-link plugin is registered below but not wired"), `syncra://`
   şeması `tauri.conf.json`'a kayıtlı değil (`plugins` anahtarı yok), regex hiçbir yerde
   uygulanmıyor. Bugün URL teslim edilebilecek bir kod yolu bulunmadığı için fuzz anlamsız;
   F5-4 tesliminde `^[a-z]+/[0-9]{1,12}$` reddi + 50 örnek fuzz bu maddeye eklenecek.

6. **Clipboard içeriği log/diske yazılmıyor.** **DEĞERLENDİRİLEMEZ-F5** — özellik yok.
   Bugünkü olumlu ön koşul: `clipboard-manager:allow-read-text` capability'de **verilmiyor**
   (`default.json:4` açıklaması bunu açıkça kayda geçirir; 7-29 izin listesinde yok), yani
   plugin kayıtlı olsa da (`lib.rs:72`) webview'dan pano okuma isteği capability katmanında
   reddedilir. F5-6, runtime izin mekanizmasıyla birlikte bu maddeyi yeniden açacak.

7. **CSP ve capabilities dar; `shell` yalnız `open`.** **SAĞLANIYOR.**
   *Capabilities* (`default.json`): `shell:allow-open` yalnız `http(s)://*` URL'leri (22-24;
   komut çalıştırma izni yok); `fs` yalnız `fs:scope` iki kökle (26-28) ve **hiçbir
   `fs:allow-*` işlem izni yok** — şartnamenin istediğinden bile dar; pencere izinleri
   yalnız set-focus/show/hide; clipboard okuma yok (madde 6).
   *CSP* (`tauri.conf.json:28` + build-time overlay `tauri.mjs:66-97`): `default-src 'self'`,
   `connect-src` yalnız IPC + API + Reverb origin'leri, `object-src 'none'`,
   `frame-ancestors 'none'`; `unsafe-inline` yalnız style'da (Tauri nonce düzeltmesi S2,
   script'te değil). Kalıntı notlar: `shell:allow-open`'ın `http://*` kabulü TLS'siz link
   açılmasına izin verir (tarayıcıda açıldığı için düşük — TM-F8) ve `.env` fallback'i
   TM-F6.

8. **Updater imza doğrulaması; imzasız manifest reddi.** **DEĞERLENDİRİLEMEZ-F7 —
   bugünkü durum fail-closed.** `plugins.updater` bloğu `tauri.conf.json`'da yok; plugin
   yalnız release build'de kayıt olur (`lib.rs:58-62`) ve `pubkey` alanı zorunlu/varsayılansız
   olduğu için bloksuz release **hiç açılmaz** (`lib.rs:45-57` yorumu; sahte pubkey commit'i
   bilinçle reddedilmiş — "a fake signing key committed to the repo is the kind of
   placeholder that survives to production"). Yani bugün ne imzalı ne imzasız hiçbir
   güncelleme yolu mevcut değil; doğrulama, gerçek minisign anahtarı üretildiğinde (F7 1.
   madde) yapılacak. "İmzasız manifest reddi" testi F7 kabul kriterine devredildi (§6).

9. **`tracing` PII filtresi (email/phone masked).** **SAĞLANMIYOR — ve F5'i bekleyemez
   (TM-F3).** Log plugin'i F3'ten beri canlı (`lib.rs:78`, `Builder::new().build()` —
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

---

## 5. Bulgu Tablosu

Şiddet skalası ve statü sözlüğü PHASE-AUDIT §4/§4.1 ile aynı (🔴 kapatılmalı /
⬜ kabul edilmiş-kayıtlı). "Faz" = kapanışın ait olduğu faz.

| # | Şiddet | Bulgu | Kanıt | Önerilen düzeltme | Faz | Statü |
|---|---|---|---|---|---|---|
| TM-F1 | **ORTA** | §9/2'nin istemci yarısı sağlanmıyor: 401 sonrası lokal DB cihazda kalıyor (yalnız token siliniyor); şartname kendi içinde çelişik (§5.5 "outbox korunur" ↔ §9/2 "tamamen wipe") | `sync/mod.rs:1001-1011`; wipe yalnız farklı-user login (`:187`) ve logout (`:238-251`) | Kullanıcı kararı: (a) §5.5 davranışı + şifreli-beklet kabul edilip §9/2 metni revize edilir, ya da (b) sunucu 401 gövdesine `reason: deactivated|deleted` ayrımı eklenir ve istemci yalnız o durumda tam wipe yapar (outbox kaybı bilinçli). Karar olmadan F6 kapanmaz | F6 | 🔴 AÇIK — karar bekliyor |
| TM-F2 | **DÜŞÜK** | `notifications` tombstone'ları sahibe kapsamlanmıyor; başka kullanıcının silinmiş bildirim uuid'leri pull'da görünüyor | `SyncPullService.php:304-347` (kapsam yalnız conversation_user), şema `...100002:26-36` | `sync_deletions.owner_key VARCHAR(64) NULL` + observer yazımı + `deletionsFor()` filtresi + kilitleyen pull testi | F6 | 🔴 AÇIK |
| TM-F3 | **DÜŞÜK** | `tracing`/log filtresi yok; log plugin'i varsayılan (DEBUG dahil) seviyede F3'ten beri diske yazıyor; PII maskesi yazılmamış | `lib.rs:78`; canlı `Syncra.log` (keyring DEBUG satırları; bugün sır/PII gözlenmedi) | İki adım: (1) F6'da seviye filtresi (`Builder::level(Info)` + hedef bazlı susturma — keyring DEBUG dahil), (2) email/telefon maskeleme katmanı; clipboard (F5-6) bu filtre kanıtlanmadan açılamaz | F6 (1) + F5 öncesi (2) | 🔴 AÇIK |
| TM-F4 | **DÜŞÜK** | `device_fingerprint` istemci beyanı: kendi hesabı için cihaz-başına-tek-token kuralı atlatılabilir → token bolluğu (çapraz hesap etkisi YOK) | `DeviceTokenRequest.php:38`, `DeviceTokenService.php:110-116`, test `DeviceTokenTest.php:119` | Kullanıcı başına desktop token tavanı (ör. 10; aşımda en eskisi düşer) — şartnamede yok, onay gerektirir | F6 adayı (onaylıysa) | ⬜ KAYITLI |
| TM-F5 | **BİLGİ** | DB anahtarı 2×UUIDv4'ten türetiliyor → gerçek entropi 244 bit (UUID başına 6 bit sürüm/varyant sabiti), yorum "256 bits" diyor | `keystore.rs:110-123` | Kriptografik olarak fazlasıyla yeterli; yorum düzeltilsin ya da `getrandom` ile 32 ham bayt üretilsin | F6 (yorum) | ⬜ KAYITLI |
| TM-F6 | **BİLGİ** | CSP/API host fallback'i sessiz `http://localhost:8000` — eksik `.env` ile paketlenen build sessiz kırılır; lokal port dinleyen prosese kimlik bilgisi POST'u uç senaryosu | `tauri.mjs:58-67`, `http.ts:32` | `build` alt komutunda `VITE_API_URL` yoksa fallback yerine **hata ver** (`dev`'de fallback kalabilir) | F7 (paketleme) | ⬜ KAYITLI |
| TM-F7 | **BİLGİ** | Webview bellek içi token tasarımı: auth köprüsü bağlanınca token webview belleğine girecek; XSS artık riski CSP + `dangerouslySetInnerHTML` yasağına dayanacak | `http.ts:9-23` (bugün `setDeviceToken` çağrılmıyor — yüzey henüz boş) | F4'te köprü bağlanırken bu satır yeniden değerlendirilecek; ek sertleştirme (token'ı hiç webview'a vermemek, tüm HTTP'yi Rust'a taşımak) mimari değişiklik olur — kayda geçirildi | F4'te yeniden ele al | ⬜ KAYITLI |
| TM-F8 | **BİLGİ** | `shell:allow-open` `http://*` kabul ediyor (TLS'siz URL tarayıcıda açılabilir) | `default.json:22-24` | Kapalı devrede API bile HTTP olabilir; kabul edilebilir. `https://*`'a daraltma ancak dağıtım TLS'e geçince | F7 | ⬜ KAYITLI |

Ayrıca kayıt: §4.5 (FK cascade) bulgu değil **kilitlenmiş risk** statüsündedir (testli);
§4.2 (A22) bulgu değil **onaylı tasarım kararıdır** — ikisi de burada izlenir, numara almaz.

---

## 6. F6 Kapanışı — §9 Maddelerinin Faz Eşlemesi

| §9 maddesi | Durum | Kapanış |
|---|---|---|
| 1. ability'siz token → 403 | ✅ KAPALI | Kapandı (F1 testleriyle; D2 metin revizyonu şartnameye işlenmeli) |
| 2. Deaktive → wipe | 🔶 KISMEN | **F6** — TM-F1 kararı + (gerekirse) crate değişikliği + test |
| 3. DB düz açılamıyor | ✅ KAPALI | Kapandı (F2 testi + bu denetimin canlı okuması) |
| 4. Keychain, düz dosya yok | ✅ KAPALI | Kapandı (bu denetimin dizin dökümü; F6 raporuna çıktı eklenmeli) |
| 5. Deep link regex reddi | ⬜ F5-4 | F5-4 mini-raporu + 50 örnek fuzz; bu doküman güncellenir |
| 6. Clipboard sızıntısı | ⬜ F5-6 | Ön koşul TM-F3/2 (maskeleme); F5-6 mini-raporu |
| 7. CSP/capabilities dar | ✅ KAPALI | Kapandı (bugünkü yüzey için); F5'te her yeni izin bu dokümana satır ekler |
| 8. Updater imza | ⬜ F7 | `plugins.updater` + minisign anahtarı + imzasız manifest red testi F7 kabul kriteri |
| 9. tracing PII filtresi | 🔴 AÇIK | **F6** seviye filtresi (TM-F3/1); maskeleme en geç F5 öncesi |
| 10. Bu doküman | ✅ VAR | Canlı tutulur: F4 (TM-F7), F5'in 8 maddesi, F7 (TM-F6/F8, updater) sonrası zorunlu güncelleme |

**F6'nın bu dokümandan doğan iş listesi:** TM-F1 kararı ve uygulaması · TM-F2 (`owner_key`)
· TM-F3/1 (log seviye filtresi) · TM-F5 yorum düzeltmesi · şartname D2 metin revizyonu.
TM-F4 ve TM-F6 kullanıcı onayına bağlı önerilerdir (§0.5 gereği kendiliğinden uygulanmaz).
