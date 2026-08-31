# AÇIK İŞLER DEFTERİ

> **Bu defterin varlık sebebi bir hata.** KARAR A25 (`docs/DESKTOP-ARCHITECTURE.md` EK 4) verildi, tutanağa geçti, ve `docs/DESKTOP-THREAT-MODEL.md`'nin §9/2 maddesi "kapandı" sayıldı — ama **kodda hiçbir karşılığı yoktu**. Bağımsız bir denetim (RISK-1) bunu yakaladı.
>
> Kök neden: AÇIK maddeler üç belgeye dağılmıştı ve hiçbirinde **"kodda var mı?"** sütunu yoktu. Karar yazmak, iş bitirmekle karıştırıldı.
>
> **Kural:** bir madde ancak **üç sütunu da ✅ olduğunda** kapanır. Karar ✅ + kod ❌ = **AÇIK**.

Son güncelleme: 2026-08-31 · W2 dalgası kapandı (O2/O7/O8/O9/O35/O37) · CI yeşil

---

## Sütunların anlamı

| Sütun | ✅ ne demek |
|---|---|
| **Karar** | Bağlayıcı bir kararla çözülmüş; ne yapılacağı belirsiz değil |
| **Kod** | Kararın karşılığı üretim kodunda var, `dosya:satır` ile gösterilebilir |
| **Test** | Davranışı kilitleyen bir test/kontrol var; bozulursa kırmızı verir |

---

## 1. AÇIK — kararı var, kodu yok

| # | Madde | Karar | Kod | Test | Kanıt / not |
|---|---|---|---|---|---|
| ~~**O1**~~ | **A25 — 403 `USER_DEACTIVATED` → wipe** — **KAPANDI 2026-08-31** | ✅ EK 4 | ✅ `sync/mod.rs:1072` (`status==403 && code==USER_DEACTIVATED`), `protocol.rs:372`, `error.rs` yapısal `code` | ✅ `tests/wire_contract.rs` 3 test — biri pozitif, **ikisi negatif kontrol** (`a_plain_403_does_not_wipe_anything`, `a_bare_401_still_keeps_the_outbox`) | `transport.rs:137` 403'ü ayrıştırmadan `SyncError::Protocol`'e katlıyor; `USER_DEACTIVATED` desktop kodunda **hiç geçmiyor** (grep 0). `handle_auth_lost` yalnız 401'de. **Bugünkü davranış karardan da kötü:** deaktive kullanıcı oturumu düşmeden "protocol error" görüyor. §9/2 AÇIK, F6 kapatılamaz. |
| ~~**O2**~~ | **A26 SLA alanları — KAPANDI 2026-08-31, uçtan uca** | ✅ EK 4 | ✅ üç halka birden: sunucu (`attachTicketSla()`), ayna (`0002` migration, 4 kolon), istemci (`mapTicket()` — `null`=SLA yok, `0`=süre doldu ayrımı korunuyor) | ✅ Sunucu: `SyncPullTicketSlaTest` 4 senaryo. Taşıma: `ticket_sla_fields_survive_the_upsert_round_trip` + `a_null_sla_remaining_seconds_survives_as_sql_null_not_zero`. Mapper: 7 test, 2'si negatif kontrol. |
| ~~**O3**~~ | **TM-F2 — `sync_deletions` sahip kapsamı** — **KAPANDI 2026-08-31** | ✅ | ✅ `SyncDeletionObserver.php:51`, `SyncPullService.php:542` (`owner_key` MATCH, "başkasınınki değil" değil), migration `2026_09_01_100010` | ✅ | `owner_key` repoda hiç yok (grep 0). Bir kullanıcı başkasının silinmiş bildirim uuid'lerini görebiliyor. F6 buna bloklu. |
| **O4** | **R4 — `tauri.conf.json` hardcoded localhost CSP** | ❌ | ❌ | ❌ | `tauri.conf.json:28` `http://localhost:8000` taşıyor; doğru CSP yalnız `scripts/tauri.mjs` sarmalayıcısından geçince üretiliyor. `npx tauri build` doğrudan çağrılırsa **sessizce localhost-CSP'li paket** çıkar. |
| ~~**O5**~~ | **komut adı `stats` vs `storage_stats` — KAPANDI 2026-08-31** | ✅ sözleşme adı kazandı | ✅ `storage.rs:19`, `lib.rs:106`, `commands.ts:150` üçü de `storage_stats` | ✅ `npm run check:commands` — dört kaynağı (TS `invoke` · Rust fn · `generate_handler!` · §6.2) karşılaştırır. Teknik lider bozup ölçtü: **bozukken exit 1, düzeltilince exit 0**. |

| ~~**O25**~~ | **5xx artık `Offline` değil — KAPANDI 2026-08-31** | ✅ | ✅ `transport.rs` 5xx → `SyncError::Server`; `sync/mod.rs::is_server_5xx()` geri çekilmeyi **koruyor** (5xx hâlâ yeniden deneniyor, 4xx girmiyor); `commands/mod.rs` kodsuz 5xx'i `SERVER_ERROR`'a düşürüyor, sunucunun kendi kodu varsa koruyor | ✅ `tests/server_errors.rs` 6 test + `commands/mod.rs` 4 birim testi. Negatif kontroller: gerçekten erişilemez sunucu hâlâ `Offline`; 422 ve 401 online bayrağını düşürmüyor |
| **O26** | **`wiped_local_only` için özel sözlük anahtarı yok** | ⚠️ | kısmen | ❌ | Cümle `desktop:devices.revokeError` + `desktop:devices.subtitle` birleşiminden kuruluyor — dört dilde ve doğru, ama ödünç. Önerilen: `desktop.logout.wipedLocalOnly`. |
| **O27** | **`SYNCRA_API_URL` — ilk yarısı kapandı** | ✅ | ✅ `scripts/tauri.mjs` CSP ile **aynı** kaynaktan (`frontend/.env` → `VITE_API_URL`) türetip cargo ortamına enjekte ediyor; açık `SYNCRA_API_URL` hâlâ kazanıyor. `build.rs`'e `cargo:rerun-if-env-changed=SYNCRA_API_URL` eklendi — **`option_env!` tek başına yeniden derlemeyi tetiklemiyordu**, yani `.env` değişince eski URL gömülü kalırdı | ⏳ | Gerçek çıktıyla doğrulandı: varsayılan → `http://localhost:8000/api/`, override → `https://crm.example.com/api/`. **Kalan (F7):** release workflow'una "URL localhost/`::1` ise FAIL" assert'i (O4 ile birlikte). |
| ~~**O28**~~ | **Tehdit modeli defterle eşitlendi — KAPANDI 2026-08-31** | 14 blok güncellendi: §3/2 (A25 çözümü), §3/9 (`logging.rs` iki adım), STRIDE I2/I5/E3 satırları, TM-F1/F2/F3 ✅, §6 özet satırı 9, F6 iş listesi. **TM-F1 keşif turunda "açık kalsın" denmişti; kodda doğruladım ve kapattım** — sunucu `AuthService.php:355` gerçekten `403 USER_DEACTIVATED` dönüyor, istemci `sync/mod.rs:1072` o sinyalde wipe ediyor, yani TM-F1'in (b) seçeneği uygulanmış. Ayrıca tabloda **kaçırılmamış bir boru işareti** bulundu (`reason: deactivated\|deleted`) — satır markdown'da bir kolon kaymış hâlde duruyordu, düzeltildi. |

| ~~**O29**~~ | **`data::get` ölü komutu — KAPANDI 2026-08-31 (silindi)** | ✅ | ✅ komut + `generate_handler!` + kontrolör `CONTRACT`'ı birlikte silindi; §6.2'den de düşürüldü | ✅ `check:commands` → `dead commands: 0`. Silme öncesi tüketici yokluğu grep'le kanıtlandı (`rowById` yerel `query` yolundan gidiyor) |
| ~~**O30**~~ | **`deals:form.companyLabel` — KAPANDI 2026-08-31 (silindi)** | ✅ | ✅ dört dilden birden silindi | ✅ `i18n:dead-keys` 96 → **95**; `BoardFilters.tsx`'in kullandığı `board.filters.companyLabel` korundu |

| ~~**O32**~~ | **CI Linux: `libappindicator3-dev` çakışması — KAPANDI 2026-08-31, CI ile DOĞRULANDI** | ✅ | ✅ 3 yerden kaldırıldı | ✅ `rust workspace (ubuntu-24.04)` işi **success**. Önceki koşumda apt exit 100 ile ölüyordu; şimdi derleyip test ediyor. |
| ~~**O33**~~ | **CI backend: Redis servisi — KAPANDI 2026-08-31, CI ile DOĞRULANDI** | ✅ | ✅ `redis:7-alpine` servisi + `REDIS_*` env | ✅ `backend (php artisan test)` işi **success** (önceki koşumda 15 kırmızı, hepsi `Connection refused [tcp://127.0.0.1:6379]`). Sürücüleri `sync`/`array`'e çevirme yolu reddedildi — iş yeşile dönerdi ama üretimden farklı bir yürütme modelini test ederdi. |

| **O34** | **Release runner'ı `ubuntu-22.04`'te tutuldu** | ⚠️ kullanıcı kararı | — | — | `desktop-ci.yml` keşif turu kararıyla **24.04**'e taşındı, ama `desktop-release.yml` **22.04**'te bırakıldı: release runner'ın glibc'si dağıtılan binary'nin **tabanı** olur (22.04 = 2.35, 24.04 = 2.39) ve yükseltmek eski dağıtımları düşürür. Bu ürün kararıdır, CI temizliği değil. Bedeli: CI artık release ile aynı taban üzerinde derlemiyor, yani 22.04'e özgü bir kırılma önce `desktop-release.yml`'de görünür. F7'de karara bağlanmalı. |

| ~~**O35**~~ | **Yerel şema SLA kolonlarını aynalamıyordu — KAPANDI 2026-08-31** | ✅ | ✅ `migrations/0002_ticket_sla_fields.sql` + **migration zinciri** (`MIGRATIONS: &[(i32, &str)]`, sürüm aralığına göre sırayla uygulama) | ✅ `existing_v1_database_gets_migration_0002_applied_on_next_open` — teknik lider adıyla koşturdu, `ok`. Negatif kontrol `an_unmirrored_key_is_still_silently_dropped` da geçiyor: kolon eklemek `upsert`'in bilinçli sessiz-düşürme güvenliğini bozmadı. |

| **O36** | **Pano filtre açılırları hâlâ doğrudan axios** | ❌ | ❌ | ❌ | `boardApi.ts` içinde üç lookup sözleşmeye girmedi: `GET /api/pipeline-stages`, `/api/users`, `/api/companies`. Pano **kartları** çevrimdışı doluyor ama **filtre açılırları** boş kalır. Yalnız pano değil — `DealsListPage`, `DealFormModal`, `AssignDealOwnerModal` de aynı üç hook'u kullanıyor. O7'nin görev tanımında yoktu; F4'ün "Kanban çevrimdışı çalışır" kabulünü **kısmen** karşılıyor. |
| ~~**O37**~~ | **`pipeline_stages.name_key` — KAPANDI 2026-08-31** | ✅ | ✅ aynı `0002` migration + `mapPipelineStage` artık satırdan okuyor | ✅ `pipeline_stage_name_key_survives_the_upsert_round_trip` + null varyantı. Masaüstünde aşama başlıkları yine `enums:pipelineStage.<name_key>` ile çevriliyor. |
| **O38** | **Kontrolörlerin satır-sonu kırılganlığı** | ✅ | ✅ düzeltildi | ⚠️ | `check-data-wiring.mjs`'in manifest regex'i `\n}\n`'e sabitliydi ve CRLF ağaçta **çöküyordu** (`DATA_METHOD_MANIFEST not found`). <br><br>**Bu bir şerit hatası değildi, gizli bir kusurdu:** `core.autocrlf=true` ve index **%100 LF** (189 dosya, sıfır CRLF) — yani git Windows'ta çalışma ağacına **kasten** CRLF veriyor. Kontrolör hiçbir zaman temiz bir Windows clone'unda güvenilir değildi; bugüne kadar yalnız o dosyaya dokunulmadığı için ortaya çıkmamıştı. Regex `\r?\n` toleranslı yapıldı. Diğer dört kontrolör ampirik olarak CRLF ağaçta yeşil. <br><br>**Ders:** bir şeridin "kontrolör yeşil" raporu, o kontrolörü teknik lider yeniden koşmadan kanıt değildir — bu turda tam olarak bu oldu, rapor yeşildi, tekrarlanabilir değildi. |

| **O39** | **Migration zinciri işlem (transaction) sarmalı taşımıyor** | ⚠️ | ❌ | ❌ | `migrate()` her migration'ı `execute_batch` ile çalıştırıp ardından `user_version`'ı ilerletiyor; `BEGIN`/`COMMIT` yok. `0002` beş `ALTER TABLE` içeriyor: üçüncüsünde bir hata olursa (disk dolu, kilit) ilk ikisi kalıcı olur, `user_version` 1'de kalır, **sonraki açılışta migration baştan koşar ve "duplicate column name" ile kalıcı olarak patlar**. <br><br>Ayna kendi başına atılabilir (bootstrap yeniden kurar) — asıl bedel **outbox**: gönderilmemiş kullanıcı işi orada duruyor. SQLite DDL'i işlemsel olduğu için düzeltme birkaç satır. Mekanizma bu turda kuruldu ve her gelecek şema değişikliği bundan geçecek; sertleştirmek için doğru an şimdi. |

## 2. AÇIK — bilinçli ertelenmiş

| # | Madde | Neden ertelendi | Ne zaman |
|---|---|---|---|
| **O6** | F4 kayıt rozetlerinin **kayıt satırlarına** inmesi | `frontend/src/features/*/pages/*` dokunuşu + liste DTO'larına `sync_state` gerekiyor | F4 devamı |
| ~~**O7**~~ | **`boardApi` → `DataSource` — KAPANDI 2026-08-31** | ✅ `DealsSource`'a `board`/`move` (124 → **126** metot) | ✅ Web uçları artık yalnız `platform/web.ts`'te (birebir taşındı); masaüstünde `board()` aynadan `BoardResponse` kuruyor, `move()` HTTP atmadan outbox'a `deal.move` + `to_stage_id` yazıyor | ✅ `check-data-wiring` 126/126, EXIT=0 |
| ~~**O8**~~ | **`storage::settings` getter — KAPANDI 2026-08-31** | ✅ | ✅ `storage_settings` komutu dört kaynakta (`storage.rs:38`, `lib.rs:107`, `commands.ts:160`, kontrolör `CONTRACT`); `StorageSettings.tsx` `localStorage` aynasını bıraktı, motordan okuyor | ✅ `settings_reflects_a_write_without_a_restart` — mevcut `settings_are_persisted` yalnız restart-sonrası kalıcılığı kanıtlıyordu, aynı-oturum simetriyi değil |
| ~~**O9**~~ | **Üç hook refcount desenine geçti — KAPANDI 2026-08-31** | ✅ | ✅ `useDashboardSocket`, `useActivityStream`, `usePresence` artık `acquireChannel`/`releaseChannel` kullanıyor; `DashboardPage.tsx` ve `LiveStreamTab.tsx`'in bayat `echo.leave()` yorumları düzeltildi | ✅ 4 senaryoluk refcount probu: paylaşılan kanalda ilk release **bırakmıyor**, son release bırakıyor; presence aynı; bağımsız kanallar birbirini etkilemiyor; Echo yokken `null` dönüp çökmüyor |
| **O10** | `docs/DESKTOP-OFFLINE-TEST.md` **yok** | F4 kabul kriteri; senaryo hiç koşulmadı | F4 kapanışı |
| **O11** | §9/5 (deep link) ve §9/6 (clipboard) değerlendirilemiyor | F5 kodu yok — tutarlı, fail-closed | F5 |
| **O12** | Updater `plugins.updater` bloğu yok → release binary panikler | Sahte pubkey commit edilmedi (doğru karar) | F7'nin 1. maddesi |
| ~~**O13**~~ | ~~Linux/WebKitGTK **sıfır ölçüm** (D-4 dahil)~~ | **KAPANDI 2026-08-31** — WSL2 Ubuntu 26.04 + rustc 1.98.0 + WebKitGTK 2.52.3 kuruldu, D-4 Linux'ta ölçüldü (§4 D-4 satırı). Kalan Linux borcu artık O13 değil, **O23** (CI ubuntu-22.04 ↔ yerel 26.04 sürüm farkı). |
| ~~**O14**~~ | **CI ilk kez koştu ve YEŞİLE DÖNDÜ — 2026-08-31** | Beş işin beşi de success: `backend (php artisan test)`, `rust workspace (ubuntu-24.04 / macos-latest)`, `frontend`, `cargo audit`. (`windows-latest` vendored OpenSSL derlemesi nedeniyle uzun sürüyor, ayrı izleniyor.) İlk koşum iki gerçek hata çıkardı (O32, O33) — ikisi de düzeltildi ve **aynı SHA üzerinde** doğrulandı. |

## 3. BELGE BORCU

> **Bu defterin iki kaydı yanlıştı (2026-08-31, W2-D ortaya çıkardı).** İkisi de teknik liderin
> yazdığı satırlardı ve **şerit prompt'larına aynen kopyalanmıştı**, yani yanlış bilgi iş
> tanımına dönüşmüştü:
>
> - **O8** "crate'te settings getter yok" diyordu. Yanlış — `SyncEngine::settings()`
>   (`sync/mod.rs:866`) önceki bir fazda eklenmiş ve test edilmişti; hiçbir Tauri komutuna
>   bağlanmamıştı sadece. `StorageSettings.tsx`'in başındaki yorum da aynı bayat iddiayı
>   taşıyordu — muhtemelen defter oradan yazılmıştı.
> - **O9** "3 hook'ta ham `echo.leave()` **+ 5 sn watchdog** birlikte yaşıyor" diyordu.
>   Watchdog kısmı kodda **hiç yoktu**; şerit aramaya gönderildi ve bulamadı.
>
> **Ders:** bu defter kanıt belgesi olduğunu iddia ediyor (`dosya:satır` sütunu var), ama bu
> iki satır kanıtsız yazılmıştı. Bir madde yazılırken iddiası **o an** grep'le doğrulanmalı;
> "muhtemelen böyledir" bir sonraki şeridin görev tanımı hâline geliyor.


| # | Madde | Not |
|---|---|---|
| ~~**O15**~~ | **`SYNCDESKTOP.md` revize edildi — KAPANDI 2026-08-31** | SPEC-1 turu: 390 ekleme / 44 silme, yerinde cerrahi düzeltme + **§13 REVİZYON GÜNLÜĞÜ** (26 çapa). D1–D13, P1–P20, S1–S10, A19/A25–A28 işlendi; §6.2'ye `handle_realtime`, §6.3'e CSP, §4.1'e RO pencere muafiyeti eklendi. Kodda karşılığı olmayanlar şartnamede 🔴 **AÇIK** işaretli. |
| **O16** | `DESKTOP-ARCHITECTURE.md` append-only büyüdü (1384 satır, 5 EK) | Gövde §4.2 hâlâ yanlış `watch.ignored` değerini taşıyor (dipnotlu), §3.7 "8 dosya" derken E.5.1 "6+26+3" diyor. Konsolidasyon ucuzken yapılmalı. |
| ~~**O17**~~ | **`docs/PROGRESS.md` güncellendi — KAPANDI** | Başına desktop durum bloğu eklendi; oturum başı okuma artık doğru bağlamla başlıyor. |

## 4. ÖLÇÜM BORCU

| # | Madde | Not |
|---|---|---|
| **O18** | P4a'nın iki uzun transaction ölçümü | `PipelineStageService::deactivate` ve `ChatReadState::fanOutNewMessage` — protokol §2.4 istiyor, hiçbir belgede iz yok. `sync_counter` küresel mutex'inin en riskli müşterisi ölçülmemiş. *(F1 raporu `fanOutNewMessage` için 2.6–4.0 ms verdi; `deactivate` için 7.5 ms/deal. Bu satır o ölçümlerin protokol belgesine işlenmediğini kaydediyor.)* |
| ~~**O23**~~ | **CI Linux hedefi — KAPANDI 2026-08-31** | `desktop-ci.yml` 5 yerde `ubuntu-22.04` → **`ubuntu-24.04`** (keşif turu kararı: 22.04'ün webkit2gtk-4.1'i D-4 ölçüm ortamından iki nesil geride ve imaj emekliliğe yaklaşıyor; `ubuntu-26.04` runner'ı GA değil). Yeşil koşumla doğrulandı. **`desktop-release.yml` bilinçli olarak 22.04'te tutuldu** — bkz. O34. |
| ~~**O24**~~ | **`identifier` kalıcı depolama anahtarı — KAPANDI 2026-08-31** | ✅ | ✅ `desktop/scripts/check-identifier.mjs` `com.syncra.desktop`'a sabitliyor | ✅ negatif kontrol: değer bozulunca exit 1, geri alınınca exit 0 (`tail` değil, exit koduyla ölçüldü) |
| **O19** | **Uçtan uca doğrulama** | Tüm testler mock-simetrik (wiremock ↔ PHPUnit). İki mock'un aynı wire gerçeğini tarif ettiği hiç kanıtlanmadı. INT-1 şeridi bunu ilk kez deniyor. |
| **O31** | **Ölü i18n anahtarı tabanı = 96** | `npm run i18n:dead-keys` (2026-08-31, O20 sonrası): `desktop.json` 95 + `deals:form.companyLabel` 1. `desktop.json` dağılımı `fields.*` 47 · `onlineOnly.*` 17 · `tray.*` 10 · `sync.*` 4 · `window.closeToTray.*` 3 · `conflicts.*` 4 · `recordBadge.*` 2. Çoğu F4/F5 için önceden yazıldı — **ama O20 tam olarak böyle başlamıştı.** Bu sayı F4/F5 kapanışında düşmezse anahtarlar gerçekten ölüdür. Kontrol kapıya **eklenmedi** (sezgisel; yanlış pozitif kapıya olan güveni öldürür), `--strict` ile elle koşulur. |

---

## KAPANMIŞLAR (üç sütun da ✅)

| Madde | Kod | Test |
|---|---|---|
| A11 realtime köprüsü | `bridge/realtime.ts`, `commands/sync.rs`, `lib.rs` | `check-realtime-wiring` + 4 negatif senaryo |
| A19 `DataSource` fiil-bazlı | `platform/data/*` (124 metot) | `check-data-wiring` + negatif test |
| A26 sunucu tarafı | `SyncPullService::attachTicketSla()` | `SyncPullTicketSlaTest` |
| D-6 açılış kapısı desktop'ı kapsıyor | `check-i18n-bootstrap.mjs` 1h bölümü | negatif test (boz→kırmızı, düzelt→yeşil) |
| §9/9 log PII maskeleme | `src-tauri/src/logging.rs` | 4 test + negatif test |
| `Echo.leave()` refcount (6 hook) | `lib/channelRegistry.ts` | refcount probu + negatif kontrol |
| P19 `price_list_items` tombstone | `SyncDeletionObserver` + registry | 2 test |
| P20 `deal.move` → `to_stage_id` | `tests/push_flow.rs:81` | crate testi |
| O5 komut adı üçlü tutarlılığı | `storage_stats` üç tarafta | `check-command-wiring.mjs` + iki yönlü negatif kontrol |
| A25 403 `USER_DEACTIVATED` → wipe | `sync/mod.rs:1072`, `protocol.rs:372` | `wire_contract.rs` 3 test (2 negatif kontrol) |
| TM-F2 `sync_deletions.owner_key` | `SyncDeletionObserver`, `SyncPullService:542` | BE-K2 hedefli testler |
| O20 `desktop.logout.*` ödünç anahtarlar | `LogoutConfirm.tsx:42` | `i18n:check` — 4 dilde `title/description/discardWarning_{one,other}/force` |
| U2 device token webview'e akıyor | `platform/auth.ts:191` `setDeviceToken(options.token)` | WIRE-1 CDP yakalaması: `/api/deals/board` → `Bearer` ile 200 |
| D-4 `localStorage` kalıcılığı (**Windows + Linux**) | ölçüldü, karar: 3 dosya dokunulmuyor, `Platform.storage` eklenmiyor | Windows: WebView2. Linux 2026-08-31: WSLg + WebKitGTK 2.52.3, 4 koşumluk prob — süreç yeniden başlatmada **ve** tüm süreç ağacının `SIGKILL`'inden sonra değer korundu. Depo: `~/.local/share/com.syncra.d4probe/localstorage/tauri_localhost_0.localstorage` (SQLite+WAL). Origin dizesi platforma göre değişiyor (Linux `tauri://localhost`, Windows `http://tauri.localhost`) → kova paylaşılmaz, beklenen davranış. |

---

# 5. AUTH-1 UYUŞMAZLIK LİSTESİ (ilk gerçek uçtan uca koşum, 2026-08-31)

> Bu 16 madde, masaüstünün gerçek backend'e karşı ilk kez çalıştırılmasıyla ortaya çıktı. **Hiçbiri birim testleriyle görünmüyordu** — wiremock (crate) ile PHPUnit (backend) birbirine karşı yeşildi, gerçek wire'a karşı değil. RISK-1 denetiminin R1 maddesi tam olarak bunu öngörmüştü.

## 5.1 Yapısal blokörler

| # | Madde | Karar | Kod | Test | Kanıt |
|---|---|---|---|---|---|
| **U1** | `device_fingerprint` biçimi — masaüstü **hiçbir koşulda giriş yapamıyor** | ✅ | ⏳ FIX-RUST | ❌ | `src-tauri/src/commands/auth.rs:140` `Uuid::new_v4()` = 36 karakter tireli; `DeviceTokenRequest.php:38` `size:64` + `^[0-9a-f]{64}$` istiyor → 422. Backend dosyasında **neden** 64 hex olduğunu açıklayan yorum var. |
| **U3** | `window_days: 0` — **her senkron turunun pull yarısı 422** | ✅ | ⏳ FIX-RUST | ❌ | `crates/.../sync/mod.rs:337` `pull_until_drained(…, 0, None)`; sunucu `min:1`. §4.4 zaten "delta'da filtre yok" diyor — alan hiç gitmemeliydi. Canlı: `window_days:0`→422, `30`→200. |

## 5.2 İşlevi kıran uyuşmazlıklar

| # | Madde | Durum | Etki |
|---|---|---|---|
| **U2** | Cihaz token'ı webview'e hiç ulaşmıyor — `Session`'da `token` yok, hiçbir komut vermiyor | ⏳ FIX-RUST | §8 online-only uçları, `/api/broadcasting/auth`, `/api/password/change` kimliksiz gidip **401** alıyor. AUTH-1 `installUnauthorizedGuard()` ile 401'i yutmak zorunda kaldı — **bu bir yama, çözüm değil**; U2 kapanınca gözden geçirilmeli. |
| **U4** | `list_devices` `{data:[...]}` zarfını ayrıştırmıyor | ⏳ FIX-RUST | **Cihazlar ekranı hiç açılmıyor**; "Bu cihaz" rozeti kabul kriteri karşılanamadı. Sunucu `is_current:true`'yu doğru üretiyor. |
| **U5** | `restore` çevrimdışı çalışmıyor (`load_manifest(true)` her seferinde ağ) | ⏳ FIX-RUST | AUTH-1 webview'de `localStorage`'da kimlik kopyası tutmak zorunda kaldı — **düz metin PII** (token değil). U5 kapanınca silinmeli. |
| **U6** | `logout` sunucudaki token'ı iptal etmiyor | ⏳ FIX-RUST | Çıkıştan sonra token DB'de canlı kalıyor (`id=5`, `last_used_at` ile doğrulandı). |
| **U15** | `bootstrap` komutu `generate_handler!`'da yok | ⏳ FIX-RUST | `BootstrapProgress` yayan tek yol erişilemez; AUTH-1 ilerlemeyi `download_archive(0)` + 400 ms'lik sayım anketiyle **taklit etmek** zorunda kaldı. |
| **U11** | Hata kodları mesaj string'ine gömülüyor; `retry_after` kayboluyor | ⏳ FIX-RUST | `transport.rs:78` `SyncError::Validation(kod)`; `auth.ts` kodu regex'le çıkarıyor — kırılgan. `LOCKED_OUT`'un geri sayımı masaüstünde **çalışamaz**. |
| **U12** | 422 doğrulama hataları `PROTOCOL_ERROR` oluyor | ⏳ FIX-RUST | `device_login` yalnız 401/403/423 ayrıştırıyor. U1'in teşhisini zorlaştıran şey buydu. |

## 5.3 Veri kapsamı ve tüketim

| # | Madde | Durum | Etki |
|---|---|---|---|
| **U7** | Kanban tamamen boş — `boardApi` hâlâ HTTP | ❌ | **O7 canlıda doğrulandı.** Token olmadığı için 401 → 5 boş kolon. Aynı ekranın liste görünümü 18 fırsatı yerel aynadan basıyor. U2 kapanınca 401 kalkar ama board yine **online-only** kalır; F4'ün Kanban offline move maddesi buna bloklu. |
| **U8** | SLA sütunu `—` — `mappers.ts` A26'yı tüketmiyor | ❌ | **O2 canlıda doğrulandı.** Sunucu dört alanı gönderiyor; istemci hâlâ A23'ün `null`/`0` davranışında. Başlıkta "SLA breaches: 15" derken her satır boş. |
| **U9** | Pull penceresi ilişkili kayıtları kapsamıyor | ⏳ FIX-BE | 18 fırsat geldi ama **1 firma** → Fırsatlar'da **18/18** satırın "Company"si `—`. Sözleşme boşluğu: §4.4 bootstrap penceresini tanımlıyor, ilişkisel bütünlükten söz etmiyor. |
| **U10** | `tags` aynası 0 satır | ⏳ FIX-BE | Fırsat satırları `tags:[9]` taşıyor ama tablo boş → her ekranda "Tags: —". Bootstrap %93'te duruyor (15 tablodan 14'ü) — sayaç dürüst, veri eksik. |

## 5.4 UX ve sözlük

| # | Madde | Durum |
|---|---|---|
| **U13** | Restore edilen oturum `/login`'de kilitleniyor — `LoginPage`'de "zaten girişli" muhafızı yok | ⚠️ Masaüstü tarafında `leaveLoginRoute()` ile kapatıldı; kalıcı çözüm `frontend/**` |
| **U14** | Eksik `desktop.*` anahtarları | ✅ FIX-I18N kapattı — 199 → **206**, dört dil birebir |
| **U16 / O1** | A25 (403 `USER_DEACTIVATED` → wipe) | ⏳ FIX-RUST — U11 ile birlikte kapanıyor |

## 5.5 YENİ AÇIK — anahtar var, tüketen yok

**O20.** FIX-I18N'in 7 girdisi (`desktop.logout.*`, `errors.INVALID_CREDENTIALS`, `errors.PENDING_MUTATIONS`) eklendi ama **hiçbiri kullanılmıyor** — AUTH-1'in `LogoutConfirm` bileşeni hâlâ `common:layout.logout` + `desktop:sync.pendingChanges` + `desktop:storage.clearLocal.description` ödünç alıyor. Sonuncusu logout'un yerel DB'yi wipe etmesi bakımından kısmen doğru ama **"gönderilmemiş iş kaybolacak" demiyor** — `discardWarning` tam bunun için yazıldı.

Aynı desen I18N-B turunda da olmuştu: `desktop.entities.*` (22) ve `desktop.fields.*` (55) eklendi, `ConflictInbox`/`PendingRecords` hâlâ ham tablo/kolon adı basıyor.

> **Ders:** sözlük şeridi ile tüketici şerit ayrı olduğunda anahtarlar **ölü doğuyor**. Bundan sonra i18n görevleri ya tüketici şeritle aynı şeride verilmeli, ya da tüketim ayrı bir madde olarak deftere yazılmalı. Bu iki grup (O20) şu an ikinci durumda.

## 5.6 Bırakılan durum — kullanıcı onayı bekliyor

AUTH-1, U1 kapanana kadar login'in **tek çalışma koşulu** olarak Windows Credential Manager'a `device-fingerprint.syncra-desktop` adlı 64-hex bir kayıt elle tohumladı.

> **DÜZELTME (RISK-2 denetimi).** Bu madde önce "FIX-RUST U1'i kapatınca gereksizleşecek" diyordu — **yanlıştı.** `auth.rs`'in onarım mantığı yalnız **geçersiz** bir değeri değiştiriyor (`if is_valid_fingerprint(&existing) { return Ok(existing) }`); elle tohumlanan kayıt geçerli 64-hex olduğu için **kalıcı olarak korunur**. Değer şerit transkriptlerinden ve raporlardan geçmiş; fingerprint bir sır değil ama entropisi ve geçmişi bilinmeyen bir cihaz kimliğini kalıcılaştırmanın gereği yok.

**Yapılacak (elle, FIX-RUST indikten sonra):**
```
cmdkey /delete:device-fingerprint.syncra-desktop
```
Silinince uygulama bir sonraki girişte CSPRNG'den taze 64-hex üretir. Sunucuda eski fingerprint'e bağlı cihaz satırı öksüz kalır — Cihazlar ekranından iptal edilebilir.

## 5.7 U9/U10'un KAPSAM DIŞI KARDEŞLERİ (FIX-BE raporladı, dokunmadı)

**O21 — RO tabloları pencereleniyor, oysa §4.1 onları referans grubunda sayıyor.**

FIX-BE `tags` için pencere muafiyeti eklerken aynı kök nedenin başka tablolarda da yaşadığını ölçtü:

| Tablo | 30 günlük pencerede | Toplam |
|---|---|---|
| `products` | **0** | 20 |
| `users` | 7 | 10 |
| `tags` (düzeltildi) | 0 → **12** | 12 |

`SYNCDESKTOP.md` §4.1 bu RO tabloları zaman-sıralı ana varlık grubundan (`companies…quotes`) **ayrı**, referans/lookup grubunda listeliyor ve yalnız `exchange_rates` için açık "(son 7 gün)" notu taşıyor. Yani sözleşme bunların pencerelenmesini **öngörmüyor**; kod pencereliyor.

**Somut etkisi:** AUTH-1'in bootstrap'inde `users=7` geldi. Eksik 3 kullanıcıya atanmış kayıtlarda "Atanan: —" görünür — U9'un firma sorununun aynısı, farklı kolonda. `products=0` ise teklif kalemlerinin ürün adlarını boşaltır.

**Ayrıca:** `users` için `assigned_to`/`owner_id` üzerinden U9 tipi bir ilişkisel kapanış da gerekebilir (FIX-BE teyit etmedi, kapsam dışı bıraktı).

Karar ❌ · Kod ❌ · Test ❌ — kapsam §0.5 gereği FIX-BE'nin görevine dahil değildi, ayrı bir tur gerektiriyor.

**O22 — U9 kapanışının bütçe kenar durumu.** Bir pull, 5 referans tablosundan bazılarını 5 MB bütçe dolduğu için hiç işleyemezse, o tablonun kapanış adayları kaçar ve `companies` cursor'ı 0'ın üstüne çıktığı için **bir daha denenmez**. Mevcut veri setinde ulaşılamaz (payload/bütçe oranı %0.28) ama büyük kurumsal veri tabanında gerçek bir sınır. FIX-BE bunu bilinçli kabul edip raporladı.
