# AÇIK İŞLER DEFTERİ

> **Bu defterin varlık sebebi bir hata.** KARAR A25 (`docs/DESKTOP-ARCHITECTURE.md` EK 4) verildi, tutanağa geçti, ve `docs/DESKTOP-THREAT-MODEL.md`'nin §9/2 maddesi "kapandı" sayıldı — ama **kodda hiçbir karşılığı yoktu**. Bağımsız bir denetim (RISK-1) bunu yakaladı.
>
> Kök neden: AÇIK maddeler üç belgeye dağılmıştı ve hiçbirinde **"kodda var mı?"** sütunu yoktu. Karar yazmak, iş bitirmekle karıştırıldı.
>
> **Kural:** bir madde ancak **üç sütunu da ✅ olduğunda** kapanır. Karar ✅ + kod ❌ = **AÇIK**.

Son güncelleme: 2026-09-01 · kurtarma tamam · D4 onaylandı · Dalga 2 sırada

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

| ~~**O36**~~ | **Pano lookup'ları → DataSource — KAPANDI 2026-08-31** | ✅ `deals.stages()` + `deals.ownerOptions()` (126 → **128** metot) | ✅ `boardApi.ts`'te doğrudan veri çeken axios çağrısı kalmadı; masaüstünde aşamalar `pipeline_stages`, sahip/firma `user_list`/`company_list` ile aynadan; `board()` ve `stages()` aynı yardımcıyı paylaşıyor | ✅ `check-data-wiring` 128/128, EXIT=0. `isForbidden` ve `retry: false` iki hook'ta da değiştirilmeden korundu (`boardApi.ts:173/175`, `:195/197`). |
| ~~**O37**~~ | **`pipeline_stages.name_key` — KAPANDI 2026-08-31** | ✅ | ✅ aynı `0002` migration + `mapPipelineStage` artık satırdan okuyor | ✅ `pipeline_stage_name_key_survives_the_upsert_round_trip` + null varyantı. Masaüstünde aşama başlıkları yine `enums:pipelineStage.<name_key>` ile çevriliyor. |
| **O38** | **Kontrolörlerin satır-sonu kırılganlığı** | ✅ | ✅ düzeltildi | ⚠️ | `check-data-wiring.mjs`'in manifest regex'i `\n}\n`'e sabitliydi ve CRLF ağaçta **çöküyordu** (`DATA_METHOD_MANIFEST not found`). <br><br>**Bu bir şerit hatası değildi, gizli bir kusurdu:** `core.autocrlf=true` ve index **%100 LF** (189 dosya, sıfır CRLF) — yani git Windows'ta çalışma ağacına **kasten** CRLF veriyor. Kontrolör hiçbir zaman temiz bir Windows clone'unda güvenilir değildi; bugüne kadar yalnız o dosyaya dokunulmadığı için ortaya çıkmamıştı. Regex `\r?\n` toleranslı yapıldı. Diğer dört kontrolör ampirik olarak CRLF ağaçta yeşil. <br><br>**Ders:** bir şeridin "kontrolör yeşil" raporu, o kontrolörü teknik lider yeniden koşmadan kanıt değildir — bu turda tam olarak bu oldu, rapor yeşildi, tekrarlanabilir değildi. |

| ~~**O39**~~ | **Migration atomikliği — KAPANDI 2026-08-31** | ✅ | ✅ `migrate_with()` her migration için `unchecked_transaction()` açıyor; `execute_batch(sql)` **ve** `pragma_update(user_version)` aynı işlemde, sonra `commit()`. Hata olursa `?` erken döner, `tx` düşerken rollback atar. Aynı dosyadaki `wipe()` deseniyle tutarlı. | ✅ `a_failing_migration_rolls_back_and_can_be_retried_after_the_fix` — teknik lider adıyla koşturdu: yarıda kırılan migration hiçbir iz bırakmıyor, `user_version` ilerlemiyor, düzeltilince temiz yükseliyor. `migration_is_idempotent` ve `existing_v1_database_...` de yeşil. |

| **O40** | **Teknik lider yanlış sözleşme kararı verdi — kayda geçiyor** | — | — | — | O36'ya "owner açılırları yeni fiil gerektirmez, `users.list()` kullan" dedim. **Yanlıştı:** `manifest.ts:249` `users.list` → `kind:'http', reason:'spec-8'` ve `SYNCDESKTOP.md:617` `users.*`'ı çevrimiçi-zorunlu ilan ediyor. Oraya bağlansaydı sahip filtresi çevrimdışı **boş kalırdı** — turun çözmek için açıldığı hatanın aynısı, üstelik `tsc` ve `check-data-wiring` yeşil kalacağı için **sessizce**. <br><br>Şerit ölçtü, §8'in talimatı ezdiğini tespit etti, sessizce uygulamak yerine raporladı. Konulan "127 metot" tavanı da hatalıydı: manifest'te dört domain (`contacts`/`companies`/`leads`/`tasks`) **zaten kendi** owner-options fiiline sahip, hepsi aynı `user_list`'e bağlı — yani `deals`'ın kendi fiiline sahip olması desene uyar, ödünç alması anomalidir. Tavan kaldırıldı, `deals.ownerOptions()` eklendi. <br><br>**Ders:** sözleşme kararı verirken "bu fiil zaten var" yetmez — o fiilin masaüstünde **hangi `kind` ile bağlı olduğu** kontrol edilmeli. §8 çevrimiçi-zorunlu bir fiil, çevrimdışı bir ihtiyaca cevap veremez. |

| ~~**O41**~~ | **Kanban kartı rozeti — KAPANDI 2026-08-31** | ✅ | ✅ `crm.ts:237` `boardCard(): WithSyncState<DealCard>`; `DealBoardCard.tsx:59` `<SyncStateBadge compact />`, liste sayfalarıyla **aynı desen** | ✅ Web'de `sync_state` hiç üretilmiyor (`dealsApi.ts` 0, `web.ts` 0) → rozet erken `null` döner; platform dallanması yok. tsc (frontend+desktop), i18n, iki build, `check-data-wiring` yeşil. |
| **O42** | **`dealsShared.ts` ve `ticketsShared.ts` hâlâ inline axios** | ❌ | ❌ | ❌ | `fetchDealTags`, `fetchDealCustomFields`, `fetchDealContactOptions`, `fetchDealCompanyOptionsSearch` ve `useTicketCompanyOptions` doğrudan axios kullanıyor; sözleşmede karşılıkları var (`contacts.tags`, `contacts.customFields`, `contacts.list`, `companies.list`). Deal formu masaüstünde çevrimdışı etiket/özel alan/kişi açılırlarını dolduramıyor — O36'nın panoda çözdüğü sorunun form karşılığı. |

| ~~**O43**~~ | **§7.2/8 birleşik arama — KAPANDI 2026-08-31** | ✅ | ✅ `comms.ts` iki yarı paralel; tekilleştirme `type:id` üzerinden ve **yerel sürüm kazanıyor** (push edilmemiş düzenleme ekranda kalsın); sunucu patlarsa yerel sonuçlar dönüyor, hata yutulmuyor; etiket `CommandPalette.tsx:334` | ✅ 6 test. Manifest'e **`kind: "hybrid"`** eklendi — hem yerel okuma hem `http` **zorunlu**, `mutate` **yasak**. §8 kontrolü bilerek `!== "http"` kaldı: hybrid'in yerel yarısı, sunucunun vermediği bir başarıyı bildirebilir. Negatif kontrol teknik liderce koşuldu: `http.get` kaldırılınca **EXIT=1**. |
| ~~**O44**~~ | **Quick-capture çelişkisi çözüldü — F5'e devredildi, 2026-08-31** | Kullanıcı onayıyla `SYNCDESKTOP.md` §7.2'ye dipnot eklendi (`#k-quickcapture-f5`) + §13 günlük satırı. **F5 madde 3 kazandı:** quick-capture'ın tetikleyicisi global hotkey'dir ve hotkey tartışmasız F5-3'tür; hotkey'siz bir pencere açılış yolu olmayan güdük bir pencere olurdu. **F4 kabulü bu maddeden etkilenmiyor** — "§7.2 tamamı" bundan böyle "quick-capture hariç yedi madde" diye okunur. |

| ~~**O45**~~ | **B1 action beyaz listesi — KAPANDI 2026-08-31** | ✅ sunucu değişti (sözleşme ve istemci doğruydu) | ✅ `MutationApplier.php:473` birleşik `entity.action` anahtarı; `:465` noktalı `action` **açık redde** — tek lehçe, Postel bilinçle reddedildi | ✅ Teknik lider koşumu: **1430 passed** (taban 1427 + 3 yeni). 11 fixture çıplağa çevrildi. **Kırmızı kanıtı:** eski davranışa geçici dönüşte 3 test düştü, md5 ile geri yükleme doğrulandı. |
| ~~**O46**~~ | **B2 otomatik toparlanma — KAPANDI 2026-08-31** | ✅ döngü F4'e alındı (§5.5 tetikleyicisi "open") | ✅ `lib.rs:139` `setup()`'ta başlıyor, handle `AppState.scheduler`'da; `probe_online()` → `load_manifest(true)` — **`force` olmadan** 10 dk'lık önbellek ağa hiç dokunmadan "online" derdi; ramp 1/2/4/8/16, tavan **30 sn** (30 + 4.8 ≪ 60 sn garantisi) | ✅ 4 test, crate **138** (+4). Negatif kontrol kalıcı **503** ile: soket bağlanıyor, sunucu cevap veriyor — naif bir probe'un "online" diyeceği en keskin yanlış-pozitif vaka. Throttle kaynaktan doğrulandı (`throttle:30,1,sync`, ~6 istek/dk). |
| ~~**O47**~~ | **B3 `task.complete` payload'ı — KAPANDI 2026-08-31** | ✅ | ✅ `work.ts:163` → `runAction('task', id, 'complete', { completed: true })`; sunucu kuralı `CompleteTaskRequest.php:26` (`required, boolean`) kaynağından alındı, tahmin edilmedi | ✅ `MutationApplier.php:478` doğruluyor: REST ve offline-action yolu **aynı** FormRequest'i paylaşıyor |
| ~~**O48**~~ | **B4 red gösterimi — KAPANDI 2026-08-31 (iki turda)** | ✅ | ✅ `ConflictInbox.tsx` kayıt adı veya çevrilmiş varlık tipi gösteriyor; `errors.INVALID_MUTATION` dört dilde | ✅ **İlk teslim yarımdı (bulgu B7):** anahtar dört sözlüğe girdi ama `errors.ts` `KNOWN_ERROR_CODES` setine girmedi, `errorMessage()` `errors.unknown`'a düşüyordu — Çakışma Kutusu her sunucu reddini *"An unknown error occurred."* diye gösteriyordu. İkinci senaryo koşumu kasıtlı bir red probe'uyla yakaladı. **Kök neden teknik liderin dosya bölümlemesi:** şeride `desktop.json` + `ConflictInbox.tsx` verildi, `errors.ts` verilmedi. Teknik lider tek satırla düzeltti; simetri denetimi artık **19/19**. |
| **O49** | **B5 — bootstrap sayısı ile aynadaki satır sayısı tutmuyor** | ❌ | ❌ | ❌ | Bootstrap ekranı "Company: 22 records" diyor, `retention_days=30` penceresi sonrası aynada 1 firma kalıyor (sunucuda da son 30 günde güncellenmiş 1 firma var). Gösterim tutarsızlığı, **veri kaybı değil** — ama kullanıcıya yanlış sayı gösteriyor. |

| **O50** | **TS nullability ile sunucu FormRequest nullability'si ayrışıyor** | ⚠️ | kısmen | ❌ | O47 denetiminin asıl bulgusu. Üç fiil üç farklı durumda: <br><br>• **`ticket.assign` DOĞRU** — `AssignTicketRequest.php:34` `present, nullable` ve gerekçesi dosyada yazılı ("ticket havuza geri bırakılır"); `AssignTicketModal` açık bir `UNASSIGNED_VALUE` taşıyor. Bilinçli ürün kararı. <br>• **`task.assign` RİSKLİ** — `AssignTaskRequest.php:24` `required, integer` (nullable **değil**), ama `AssignTaskModal.tsx:34` boş seçimde açıkça `null` gönderiyor (`assignedTo ? Number(assignedTo) : null`). Modal boş seçim üretebiliyorsa atama kaldırma **hem web'de hem masaüstünde** reddedilir. <br>• **`deal.assign` GİZLİ** — `AssignDealRequest.php:24` `required`, TS imzası `number | null`, ama `AssignDealOwnerModal.tsx:33` her zaman `Number(ownerId)` gönderiyor. Bugün ulaşılamaz; imza fazla geniş. <br><br>**Genellenebilir kural:** `DataSource` imzasındaki nullability, karşılık gelen FormRequest'in nullability'siyle **eşleşmeli**. Bu statik olarak kontrol edilebilir — `check-data-wiring` deseninde bir kontrolör konusu. Offline'a özgü değil, paylaşılan web+masaüstü tip gevşekliği. |

| **O51** | **`wire-fixtures/` — RISK-1'in beşincisini engelleyecek mekanizma** | ✅ karar verildi | ❌ | ❌ | **Dört vakanın ortak deseni:** her takım kendi mock'unu test ediyor, iki mock'un aynı bayt dizisini tarif ettiğini hiçbir şey doğrulamıyor. (AUTH-1'de 16 uyuşmazlık · `ApiErrorBody` · SLA kolonlarının `upsert`'te sessizce düşmesi · B1 action lehçesi.) <br><br>**Yapı:** repo kökünde versiyonlanan kanonik JSON seti — `push/` (her op + her beyaz-liste action'ı), `pull/` (entity başına satır), `errors/` (`ApiErrorBody` şekilleri). **Üç tüketici, kopya yasak:** (1) crate `to_wire()` çıktısını fixture'a eşitler · (2) PHPUnit fixture'ı gerçek endpoint'e basıp `applied` bekler · (3) TS composer (vitest) outbox'a giden gövdeyi fixture'a eşitler. <br><br>Kilit özellik: bir taraf wire'ı **tek başına** değiştiremez — fixture'a dokunmadan kendi tarafını değiştirirse kendi testi kırmızı, fixture'ı değiştirirse karşı tarafınki. **O47'yi (crate doğru serileştiriyor, TS boş payload veriyor) yalnız 3. tüketici yakalayabilirdi.** <br><br>**Maliyet:** ilk kurulum 1.5–2.5 gün, CI +<1 dk. Kalıcı: her wire değişikliği üç yerde yeşillenmek zorunda — bu sürtünme kusur değil, mekanizmanın kendisi. <br><br>**Sıra: Dalga 2** — O45'in çıktısını kilitleyeceği için ondan sonra. docs/ENGINEERING-RULES.md'ye eklenecek kural: *"Fixture'ın kapsadığı bir şekil için hiçbir test kendi inline JSON'unu kanıt sayamaz."* |

| **O52** | **`mappers.ts::syncState` export edilmemiş** | — | — | — | `mappers.ts:84` `function syncState(row): SyncState | undefined` — komşuları (`nameRef`:92, `fullNameRef`:98, `titleRef`:104, `fullName`:110) export'lu, bu değil. O41 şeridi `crm.ts`'ten ona erişemedi ve **kendi doğrulayıcısını yazmak yerine** zaten paylaşılan `components/shared/recordSyncState`'i kullandı — doğru karar, ama ayna tarafında aynı ihtiyaç bir daha çıkarsa aynı ikilem tekrarlanır. Tek satırlık `export` + `crm.ts`'in ona yönlendirilmesi; `mappers.ts` sahibi olan bir sonraki tur halledebilir. |

| **O53** | **`comms.test.ts` / `mappers.test.ts` regresyon kapısına bağlı değil** | ⚠️ | ✅ dosyalar var | ⚠️ elle koşuluyor | İki TS test dosyası (13 test) repoda, ama projede TS test runner`ı (vitest/jest) kurulu değil; şeritler scratchpad`teki bir loader shim`i ile koşturdu. **Yazılmış ama kapıya bağlı olmayan test, bozulduğunda kimseye haber vermez.** `vitest` eklemek `package.json` değişikliği ister — küçük ama bilinçli bir karar. O51 (wire-fixtures) üçüncü tüketicisi olarak zaten bir TS koşucusu gerektirecek; ikisi birlikte yapılmalı. |
| **O54** | **`check-data-wiring` sessiz geçiş açığı kapatıldı — kayda geçiyor** | ✅ | ✅ | ✅ | O43 turunda bulundu: `helperBody()` bilinmeyen bir helper adı için **boş string** dönüyordu, yani `SHARED_HELPERS``ta yanlış yazılmış bir ad üyeyi kendi tek satırıyla sınıflandırtıp **sahte yeşil** üretirdi. Artık `fail()` veriyor. **CRLF olayıyla (O38) aynı sınıf:** kontrolörün kendisi sessizce yanlış cevap veriyordu. Kapanmış madde, tekrar açılmasın diye kayıtta. |

| ~~**O55**~~ | **Hata kodu simetri kontrolörü — KAPANDI 2026-08-31** | ✅ | ✅ `desktop/scripts/check-error-codes.mjs`; `KNOWN_ERROR_CODES` bulunamazsa **throw** (sessiz boş liste yok), blok içi yorum satırları regex'ten önce eleniyor (yorumlardaki apostrof yanlış eşleşme üretebilirdi), hariç tutma **yapısal kuralla** (SCREAMING_SNAKE deseni), açık listeyle değil | ✅ Bugün **19/19**. Teknik lider iki yönü de ayrı koşturdu: kodda-var/sözlükte-yok → EXIT=1 doğru mesajla; sözlükte-var/kodda-yok (B7'nin sınıfı) → EXIT=1; geri alınca 0; kalıntı 0. **Şerit teknik liderin negatif kontrol tasarımındaki hatayı yakaladı:** verilen iki eylem aynı dalı tetikliyordu, ters yön kanıtlanmamış olacaktı. |
| **O56** | **B6 — reddedilen action`ın iyimser yerel yazması geri alınmıyor** | ❌ | ❌ | ❌ | İkinci koşum buldu: ilk koşumun reddedilen `deal.move``ları `TakeServer` + tam senkron sonrası bile aynada duruyordu (ayna 26@2/32@3/48@5, sunucu 1/2/3). `theirs=null` olduğu için yazacak satır yok; sunucu değişmediği için pull da göndermiyor. Yani reddedilen bir action, yerelde **kalıcı yanlış durum** bırakabiliyor. O45 sonrası red nadir ama sıfır değil (izin hataları, doğrulama). |
| ~~**O57**~~ | **B8 Çakışma Kutusu tazeleme — KAPANDI 2026-08-31** | ✅ | ✅ `ConflictInbox.tsx:161` liste artık `useEngineStatus().conflicts`'e bağlı | ✅ **Kök neden:** liste `useEffect(..., [load])` ile yükleniyordu ve `load` yalnız `t`'ye bağlı olduğu için **fiilen mount-anı** çalışıyordu — hiçbir canlı sinyale abone değildi; rozet ise `StatusChanged` akışındaydı. Rozeti besleyen **aynı** hook'a bağlandı, yeni mekanizma kurulmadı. |
| ~~**O58**~~ | **G1 bayat yorum — KAPANDI 2026-08-31** | `commands/sync.rs` `sync_now` yorumu güncel duruma uyduruldu (döngü `setup()`'ta başlıyor, offline'da probe var, `sync_now` hâlâ elle tetiklenen tek tur). Dosyanın kalanı tarandı, başka bayat ifade yok. |
| **O59** | **G2 — hiç senkronlanmamış yerel kaydı silmek `UNRESOLVED_REFERENCE` üretiyor** | ❌ | ❌ | ❌ | İkinci koşum gözlemi. Sunucunun hiç duymadığı bir kaydın silinmesi, çözülecek referansı olmayan bir mutasyon üretiyor. Beklenen davranış outbox`tan sessizce düşürmek olmalı (create + delete çifti coalesce edilebilir). |

| **O60** | **`onConflictAdded` olayı hiçbir yere bağlı değil — tamamen düşüyor** | ❌ | ❌ | ❌ | O57 turunda yol boyunda bulundu: `bridge/events.ts:154,181` `onConflictAdded` handler`ını tanımlıyor ve çağırıyor, ama `main.desktop.tsx` `subscribeToEngineEvents``a yalnız `onStatusChanged` ve `onAuthLost` veriyor (grep: `onConflictAdded` orada **0** eşleşme). Rust tarafı `EngineEvent::ConflictAdded` yayınlıyor, istemci onu **sessizce atıyor**. <br><br>Bugün zararsız — `status.conflicts` aynı bilgiyi taşıyor ve O57 listeyi ona bağladı. Ama tanımlanmış, çağrılan ve hiçbir yere ulaşmayan bir olay yolu, bir sonraki okuyucuya "bu bağlı" izlenimi verir. Ya bağlanmalı ya kaldırılmalı. |

| ~~**O61**~~ | **Bildirim ham anahtar hatası — KAPANDI 2026-09-01 (iki turda)** | ✅ | ✅ **Mekanizma:** `features/notifications/notificationText.ts` — `title_key`/`body_key` + `params` i18next'ten çözülüyor, öncelik tersine döndü, çözülemeyen anahtar ham basılmıyor (`desktop:entities.notification`'a düşüyor). **İçerik:** 12 tip × 4 dil = 144 anahtar `backend/lang/*/notifications.php`'den taşındı, `:param` → `{{param}}` mekanik dönüşümle. | ✅ 4 mapper testi + **yeni kontrolör** `i18n:notifications`: iki yönlü anahtar karşılaştırması **artı parametre adı** karşılaştırması. Teknik lider negatif kontrolü koşturdu: `{{deal_title}}` → `{{dealTitle}}` **EXIT=1** ve iki yönü birden raporluyor. <br><br>**Teknik lider'in dispatch varsayımı yanlıştı** ("web zaten çözüyor, yardımcıyı paylaş") — web hiç client-side çözmüyor, sunucu `NotificationResource.php:48`'de çözüp gönderiyor. Şerit uydurmak yerine ölçtü ve düzeltti. |
| **O62** | 🟠 **`set_badge` Windows'ta hiçbir şey yapmıyor** | ⚠️ şartname vs platform | ✅ komut yazıldı | ✅ | `tauri 2.11.5/src/window/mod.rs:2265-2268` aynen: *"Windows: Unsupported, use `Window::set_overlay_icon` instead"*; `tauri-runtime-wry` kolu Windows'ta boş derlenip `Ok(())` dönüyor. Şartname §6.4 açıkça `set_badge_count` diyor — yani **birincil platformda bu madde hiçbir şey teslim etmiyor**, üstelik komut başarılı görünüyor. Şerit sözleşmeye sadık kaldı ve Windows'ta no-op olduğunu `tracing::debug!` ile loglattı (sessiz kalmıyor). Windows yolu `set_overlay_icon` + rakam üreten bir görüntü bağımlılığı ister — yeni bağımlılık + tasarım kararı, **kullanıcı kararı**. |
| **O63** | 🟠 **Bildirim tıklaması → `syncra://` bu eklentiyle yapılamıyor** | — | ❌ | ❌ | `tauri-plugin-notification` masaüstü yolu toast'ı `notify-rust``a verip dönüyor; **tıklama callback'i yok** (`desktop.rs:179-222`). §6.4 *"tıklama → `syncra://<entity>/<id>` yönlendirme"* diyor. **F5-4 (deep link) planı buna göre kurulmalı:** deep link'in karşı ucu var, bildirimin tıklama ucu yok. |
| ~~**O64**~~ | **`RunEvent::Exit` — KAPANDI 2026-09-01, ÖLÇÜMLE** | ✅ | ✅ Tray Quit → `app.exit(0)` → `RunEvent::Exit` → `scheduler.stop()` **sonra** `engine.shutdown()`. Sıra motorun kendi dokümanından: önce durdur ki `wal_checkpoint` hâlâ yazan bir turla yarışmasın. | ✅ **Şerit uygulamayı gerçekten çalıştırdı, teknik lider diskte doğruladı:** `.window-state.json` **211 B** (bu makinede ilk kez oluştu) · `syncra.db-wal` **4.239.512 B → yok** · `-shm` **yok**. Üç sonucun üçü de ölçüldü. |
| ~~**O65**~~ | **`StateFlags` `VISIBLE` tuzağı — KAPANDI 2026-09-01** | ✅ | ✅ `lib.rs:83` `StateFlags::all() & !StateFlags::VISIBLE` | ✅ **Kaynak-assert + davranışsal ölçüm birlikte.** Yazılan dosyada `"visible": true` — ve ölçümde pencere çıkışta **gizliydi** (tray'e inmişti). Bayrak dursaydı `false` yazılacak ve uygulama sonraki açılışta **hiç görünmeyecekti**. Kilit: `tray::tests::window_state_does_not_persist_visibility` + `the_exit_callback_is_wired`. |
| **O66** | **Autostart durumu açılışta okunamıyor** | ⚠️ | — | — | §6.2 yalnız dört `os::*` komutu listeliyor, **okuma komutu yok**; `@tauri-apps/plugin-autostart` npm paketi de `desktop/package.json``da değil. `set_autostart` geri-okunan gerçek değeri döndürüyor ama bu ancak **yazdıktan sonra** işe yarar — ayar ekranı açılışta mevcut durumu gösteremez. Beşinci bir komut mu, npm paketi mi: **kullanıcı kararı** (§0.5 gereği şerit kendi eklemedi). |

| ~~**O67**~~ | **GİZLİLİK: wipe cache blob'larını da siliyor — KAPANDI 2026-09-01** | ✅ | ✅ `sync/mod.rs:183` `wipe_local()` — `SELECT DISTINCT path` → `remove_cached_blob` → `db::wipe`; üç çağrı noktası da dönüştü (`:311` farklı-kullanıcı login, `:397` logout, `:1380` A25 403) | ✅ Üç mevcut wipe testi blob assert'iyle genişletildi. **Negatif kontrol:** `wipe_local` `db::wipe`'a geri indirgenince **üçü de kırmızı** — kendi panik mesajlarıyla (*"O67: a different-user login must remove the previous user's cached blobs"*). Artık A kullanıcısının teklif PDF'leri B login olduğunda diskte kalmıyor. |
| ~~**O68**~~ | **Gerçek katalog + gerçek mapper testi — KAPANDI 2026-09-01** | ✅ | ✅ `notification-catalog.test.ts` — **gerçek** dört `notifications.json` gerçek i18next singleton'ına yükleniyor | ✅ **145 test** (4 dil × 36 çift), katalog üzerinde **döngü** — 13. tip eklendiğinde dosyaya dokunmadan kapsanıyor. `params` fixture'ları 12 bildirim sınıfından satır referanslı transkribe edildi. **Teknik lider negatif kontrolü koşturdu:** Almanca çeviri ham anahtarla değiştirilince tam bir test kırmızı, mesajı isabetli (*"resolved to the raw key, not a translation"*); geri alınca 145 yeşil. |
| ~~**O69**~~ | **Cache tavanı anlık uygulanıyor — KAPANDI 2026-09-01** | ✅ | ✅ `record_cached_file` sonunda `trim_cached_files` (`retention.rs:108`) — §5.6/3'ün zaten yazılı invariant'ı, ekleme değil yerine getirme | ✅ Üç mevcut test yeni invariant'a göre yeniden yazıldı (teknik lider, §4 sapması — aşağıya bakın). `THIRD_CEILING` gerektirdi: iki 60 MB kayıtta ikincisi zaten tahliye ediyor, touch'ın söz hakkı kalmıyor. Her iki tavan testine `run_retention → removed == 0` assert'i eklendi. |

| **O70** | **§6.4 "Pencere kapatma → tray'e **(ayar)**" — ayar kısmı yok** | ⚠️ | ❌ | ❌ | Davranış sabit D-8; kullanıcı seçemiyor. `DesktopSettings``e alan (motor API'si) + `desktop/src` toggle'ı gerekiyor. **i18n metni `desktop.window.closeToTray.*` dört dilde zaten hazır bekliyor** — F5-1'in kapsamı dışındaydı. |
| **O71** | **Duraklatılmış durumu yalnız tray biliyor** | ⚠️ | — | — | Pause `SyncScheduler``ın varlığıyla temsil ediliyor (`Some`/`None`) — doğru karar, motorda pause yoktu ve `halted` bayrağına binmek protokol hatasıyla kullanıcı eylemini karıştırırdı. Ama `SyncStatus``ta `paused` alanı olmadığı için **UI bilmiyor**: `ConnectivityBar` senkronun neden durduğunu gösteremiyor. Motor API kararı. |
| **O72** | **Webview dil override'ı tray'e yansımıyor** | ⚠️ | — | — | Tray etiketleri oturumun hesap dilinden geliyor ve bu **ölçüldü**: OS locale `tr-TR` iken menü İngilizce çıktı (hesap dili `en`) — yani ne OS'tan ne sabit metinden. Ama kullanıcı webview'de dili elle değiştirirse (`localStorage`) tray hesap dilinde kalır; Rust `localStorage``ı okuyamaz (WebView2 profili içinde). Çözüm: `set_tray_language` komutu + `languageChanged` aboneliği. |
| ~~**O73**~~ | **`i18n:dead-keys` Rust tüketicisi — KAPANDI 2026-09-01** | ✅ | ✅ Script'e Rust taraması **eklenmedi** (serde alan adları literal anahtar değil, bespoke ayrıştırıcı aşırı mühendislik). Yerine prefix allowlist. | ✅ **Allowlist kör nokta açmıyor, sahipliği devrediyor:** bir `tray.*` anahtarı silinirse `tray.rs::tests::every_language_parses` kırmızı verir. Bu ayrım yorumda belgelendi. |

| ~~**O74**~~ | **`settings()` artık kalıcı satırı okuyor — KAPANDI 2026-09-01** | ✅ | ✅ İki kaynak ayrıldı: `retention_days`/`max_db_size_mb`/`max_outbox` → **`cfg`** (motorun fiilen uyguladığı clamp'li değer; "raporlanan ayar" ile "uygulanan ayar" sessizce ayrışmasın diye), `clipboard_capture`/`close_to_tray` → **kalıcı `SETTING_PREFERENCES` satırı**. Satır yok / motor kapalı / JSON bozuk → üçü de `Default::default()`. Yalan söyleyen doc düzeltildi. | ✅ **Negatif kontrol:** eski hardcode'a geri dönülünce **iki test kırmızı** (*"clipboard_capture must survive a restart"*, *"a write to close_to_tray must be visible to the very next read"*). 4 yeni test: yaz-sonra-oku, satır yok, bozuk JSON, restart kalıcılığı. |
| **O75** | **§4 SAPMASI — teknik lider crate test kodu yazdı** | — | — | — | Bir şerit işi yarıda bırakıp ağacı kırık bıraktı (crate 155 → 129). Teknik lider üç testi yeni invariant`a göre yeniden yazdı; normalde docs/ENGINEERING-RULES.md §4 gereği onun işi değil. **Gerekçe:** kırmızı ağaç §0.4 kapısını ve sonraki her şeridin doğrulama zeminini bloke eder — kırmızı üstüne açılan şerit kendi kırmızısını ayırt edemez. **Bedel ödendi:** negatif kontrol koşuldu (LRU testinde touch devre dışı → kırmızı → geri al). Danışman bağımsız okuyup aritmetiği doğruladı ve *"testler amaç kaybetmemiş, güçlenmiş"* dedi. <br><br>**Emsal değil, istisna.** Kalıcı kural: kırık-ağaç kurtarma protokolü — (1) kapsamı `git diff` + test çıktısıyla ölç, (2) üretim değişikliği **tam ve karara dayanıyorsa** testler hizalanabilir, her biri için negatif kontrol **zorunlu**, test silmek yasak, (3) üretim yarımsa geri alma seçeneği tam dosya listesiyle kullanıcıya sunulur, (4) müdahale faz raporunda beyan edilir. |

| ~~**D4**~~ | **`attachments:prune-orphans` zamanlandı — KULLANICI ONAYI ALINDI 2026-09-01** | ✅ | ✅ `routes/console.php` → `Schedule::command('attachments:prune-orphans --force')->dailyAt('03:47')`; komutun **kendi** 24 saatlik eşiği ezilmedi | ✅ `ConsoleScheduleTest` — kayıt sessizce düşerse test kırmızı. <br><br>**§8 ONAY KAYDI.** Kullanıcıya sunulan kanıt: salt okunur sayım (SELECT, DELETE yok) → **7 satır silinecek**, en eski 2026-07-20, **en yeni 2026-08-24 (8 gün)**, eşik altında korunacak 0. Komut hem DB satırını hem **disk dosyasını** kalıcı siliyor (`PruneOrphanAttachments.php:101-102`, soft-delete yok). Riski düşüren yapı: `StoreMessageRequest.php:91-97` eki **gönderim anında** doğruluyor — budanmış bir ek sarkık referans değil, temiz doğrulama hatası üretir. <br><br>**Kalan şart (prosedürel):** ilk zamanlanmış koşumdan sonra sayım bir kez daha alınıp faz raporuna girsin. |

## 2. AÇIK — bilinçli ertelenmiş

| # | Madde | Neden ertelendi | Ne zaman |
|---|---|---|---|
| ~~**O6**~~ | **Kayıt satırı rozetleri — KAPANDI 2026-08-31** | ✅ Rozet `desktop/src/ui`'dan `frontend/src/components/shared/`'a taşındı; `sync_state` tanımsız/`synced` ise `null` döner, yani web'de platform dallanması olmadan görünmez | ✅ 8 liste sayfası (deal, contact, company, lead, task, ticket, quote, activity); kapsam manifest'teki `kind:'mutate'` fiillerinden türetildi, `mutate`'i olmayan varlığa rozet konmadı | ✅ Web izolasyonu üç halkalı: `web.ts` 0 eşleşme, backend Resource 0 eşleşme, bileşen erken `return null`. `i18n:dead-keys` 95 → **93** (`recordBadge.*` canlandı). **Not:** statik/tip düzeyinde doğrulandı, uygulama koşturularak değil — o F4 senaryosunun işi. |
| ~~**O7**~~ | **`boardApi` → `DataSource` — KAPANDI 2026-08-31** | ✅ `DealsSource`'a `board`/`move` (124 → **126** metot) | ✅ Web uçları artık yalnız `platform/web.ts`'te (birebir taşındı); masaüstünde `board()` aynadan `BoardResponse` kuruyor, `move()` HTTP atmadan outbox'a `deal.move` + `to_stage_id` yazıyor | ✅ `check-data-wiring` 126/126, EXIT=0 |
| ~~**O8**~~ | **`storage::settings` getter — KAPANDI 2026-08-31** | ✅ | ✅ `storage_settings` komutu dört kaynakta (`storage.rs:38`, `lib.rs:107`, `commands.ts:160`, kontrolör `CONTRACT`); `StorageSettings.tsx` `localStorage` aynasını bıraktı, motordan okuyor | ✅ `settings_reflects_a_write_without_a_restart` — mevcut `settings_are_persisted` yalnız restart-sonrası kalıcılığı kanıtlıyordu, aynı-oturum simetriyi değil |
| ~~**O9**~~ | **Üç hook refcount desenine geçti — KAPANDI 2026-08-31** | ✅ | ✅ `useDashboardSocket`, `useActivityStream`, `usePresence` artık `acquireChannel`/`releaseChannel` kullanıyor; `DashboardPage.tsx` ve `LiveStreamTab.tsx`'in bayat `echo.leave()` yorumları düzeltildi | ✅ 4 senaryoluk refcount probu: paylaşılan kanalda ilk release **bırakmıyor**, son release bırakıyor; presence aynı; bağımsız kanallar birbirini etkilemiyor; Echo yokken `null` dönüp çökmüyor |
| ~~**O10**~~ | **`docs/DESKTOP-OFFLINE-TEST.md` yazıldı ve senaryo KOŞULDU — 2026-08-31** | Belge tekrarlanabilir hâlde: üç portun gerekçesi, kurulum komutları, CDP sürüş yöntemi ve **sınırı**, 20 işlemin tablosu, çakışmaların nasıl üretildiği, ölçüm yöntemi, 5 bulgu + kök neden kanıtları. **Senaryo F4 kabulünü GEÇMEDİ** — bkz. O45, O46. |
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
| ~~**O16**~~ | **Mimari belge konsolidasyonu — KAPANDI 2026-08-31** | Belge yeniden yazılmadı (EK'ler karar tutanağıdır, silmek tarihi silmek olurdu). Yerine: **§0.1 "Gövde Bayatlık Haritası"** — bağlayıcı, çakışmada hangi tarafın kazandığını söylüyor; üç bayat iddia **yerinde** düzeltildi (§4.2 `watch.ignored` tek glob → üç glob, gövde koddan da sapmıştı; §3.7 "8 dosya" → 6+26+3; §11'in sekiz "açık kararı" → sekizi de kapalı, kanıtlarıyla). Kalıcı kural yazıldı: *"gövdeye yeni iddia eklenirken onu geçersiz kılan bir EK varsa **gövde düzeltilir**"*. 1389 → 1435 satır, tablo uyuşmazlığı 0. |
| ~~**O17**~~ | **`docs/PROGRESS.md` güncellendi — KAPANDI** | Başına desktop durum bloğu eklendi; oturum başı okuma artık doğru bağlamla başlıyor. |

## 4. ÖLÇÜM BORCU

| # | Madde | Not |
|---|---|---|
| **O18** | P4a'nın iki uzun transaction ölçümü | `PipelineStageService::deactivate` ve `ChatReadState::fanOutNewMessage` — protokol §2.4 istiyor, hiçbir belgede iz yok. `sync_counter` küresel mutex'inin en riskli müşterisi ölçülmemiş. *(F1 raporu `fanOutNewMessage` için 2.6–4.0 ms verdi; `deactivate` için 7.5 ms/deal. Bu satır o ölçümlerin protokol belgesine işlenmediğini kaydediyor.)* |
| ~~**O23**~~ | **CI Linux hedefi — KAPANDI 2026-08-31** | `desktop-ci.yml` 5 yerde `ubuntu-22.04` → **`ubuntu-24.04`** (keşif turu kararı: 22.04'ün webkit2gtk-4.1'i D-4 ölçüm ortamından iki nesil geride ve imaj emekliliğe yaklaşıyor; `ubuntu-26.04` runner'ı GA değil). Yeşil koşumla doğrulandı. **`desktop-release.yml` bilinçli olarak 22.04'te tutuldu** — bkz. O34. |
| ~~**O24**~~ | **`identifier` kalıcı depolama anahtarı — KAPANDI 2026-08-31** | ✅ | ✅ `desktop/scripts/check-identifier.mjs` `com.syncra.desktop`'a sabitliyor | ✅ negatif kontrol: değer bozulunca exit 1, geri alınınca exit 0 (`tail` değil, exit koduyla ölçüldü) |
| **O19** | **Uçtan uca doğrulama** | Tüm testler mock-simetrik (wiremock ↔ PHPUnit). İki mock'un aynı wire gerçeğini tarif ettiği hiç kanıtlanmadı. INT-1 şeridi bunu ilk kez deniyor. |
| ~~**O31**~~ | **Ölü anahtar tabanı 129 → 83 — YANLIŞ POZİTİFLER TEMİZLENDİ 2026-09-01** | ✅ | ✅ **2a:** `notificationText.ts` anahtarı şablon literaliyle kuruyor → kontrolörün önek dalı (`check-i18n-dead-keys.mjs:207`) yakalıyor, **36** düştü. **2b:** `EXTERNAL_CONSUMER_PREFIXES` allowlist'ine `desktop:tray.` → **10** düştü | ✅ **Kalan 83'ün tamamı `desktop.json`** ve gerçek F4/F5 envanteri: `fields.*` 55 · `onlineOnly.*` 17 · `sync.*` 4 · `conflicts.*` 4 · `window.closeToTray.*` 3. F5 kapanışında bu sayı düşmeli. |

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
