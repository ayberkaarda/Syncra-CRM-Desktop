# Masaüstü Çevrimdışı Kabul Senaryosu — F4

`SYNCDESKTOP.md` §10, F4 kabul kapısı:

> Ağ kes → **20 işlem (5 create, 6 update, 3 move, 3 message, 2 task complete, 1 delete)** →
> ağ aç → **≤60 sn'de sunucuda, sıra doğru, kasıtlı 2 çakışma inbox'ta, çözüm sonrası
> tutarlı**.

Bu belge senaryoyu **tekrarlanabilir** biçimde tarif eder ve **2026-08-31 tarihli gerçek
koşumun** çıktılarını içerir. Koşum sonucu tek cümleyle: **kabul kapısı GEÇMEDİ** — 20
işlemin 15'i sunucuya indi, 2 kasıtlı çakışma doğru üretildi ve doğru çözüldü, ancak
(a) çevrimdışı `op=action` mutasyonlarının **hiçbiri** sunucuda uygulanmıyor ve
(b) ağ geri geldiğinde uygulama **kendiliğinden çevrimiçi olamıyor**. Ayrıntı §7'de.

---

## 1. Ön koşullar

| Gereksinim | Bu koşumdaki değer |
|---|---|
| MySQL | `syncra_crm`, 127.0.0.1:3306 |
| PHP | 8.2.12 |
| Node | 26.7.0 |
| Rust/cargo | `%USERPROFILE%\.cargo\bin` (PATH'te olmayabilir — açıkça eklenir) |
| Kullanıcı | `deniz.aksoy@syncra.local` / `Demo!2026Syncra` (rol: Admin, `must_change_password=false`) |
| Yerel ayna | `%APPDATA%\com.syncra.desktop\syncra\syncra.db` (SQLCipher, anahtar OS keychain'de) |

> **Not.** Şifre `database/seeders/DemoDataSeeder.php` → `const PASSWORD` içindedir.
> Super Admin (`admin@syncra.local`) ilk girişte şifre değiştirmeye zorlandığı için
> senaryoda kullanılmaz.

### 1.1 Port ayrımı — neden üç port?

Geliştiricinin kendi backend'i `:8000`'de çalışırken ona **dokunulmaz**. Senaryo kendi
izole örneklerini kurar:

| Port | Rol |
|---|---|
| `:8000` | Geliştiricinin kendi örneği. Senaryo boyunca hiç kullanılmaz. |
| `:8010` | **Motorun gördüğü sunucu.** "Ağı kesmek" = bu portu durdurmak. |
| `:8011` | **İkinci istemcinin sunucu erişimi.** Motor çevrimdışıyken sunucu tarafı düzenlemeleri (kasıtlı çakışmalar) buradan yapılır. Aynı Laravel uygulaması, aynı MySQL veritabanı. |

**"Ağ kesme" neden `:8010`'u durdurmakla yapılıyor?** Senkron motoru Rust tarafındadır ve
kendi HTTP istemcisini kullanır. CDP'nin `Network.emulateNetworkConditions offline` çağrısı
yalnızca webview'i etkiler, motoru **etkilemez**. Motorun gerçekten ağsız kalması için
hedef sunucunun erişilemez olması gerekir.

### 1.2 Kurulum komutları

```bash
# 1) Motorun sunucusu
cd backend && php artisan serve --host=127.0.0.1 --port=8010

# 2) İkinci istemcinin sunucu erişimi (çakışma üretmek için)
cd backend && php artisan serve --host=127.0.0.1 --port=8011

# 3) Masaüstü — webview VE Rust motoru AYNI hedefi görecek şekilde
cd desktop
export PATH="$HOME/.cargo/bin:$PATH"
VITE_API_URL=http://127.0.0.1:8010 \
SYNCRA_API_URL=http://127.0.0.1:8010/api/ \
WEBVIEW2_ADDITIONAL_BROWSER_ARGUMENTS=--remote-debugging-port=9222 \
node scripts/tauri.mjs dev
```

Neden ikisi birden:

* `scripts/tauri.mjs` `VITE_*` değişkenlerini `frontend/.env` üzerine bindirir ve **CSP'yi
  aynı değerden üretir** (`connect-src ... http://127.0.0.1:8010`).
* `SYNCRA_API_URL` Rust tarafında `option_env!` ile okunur (`src-tauri/src/state.rs`), yani
  **derleme zamanı sabitidir**. Değeri değiştirmek `syncra-desktop` crate'inin yeniden
  derlenmesini tetikler (bu koşumda 44.90 s).
* `frontend/.env` dosyasına **dokunulmaz**.

Doğrulama — üretilen CSP:

```
$ cat desktop/.tauri/tauri.conf.generated.json
"csp": "default-src 'self'; connect-src 'self' ipc: http://ipc.localhost http://127.0.0.1:8010 ws://localhost:8080; ..."
```

> **Tuzak.** `vite.desktop.config.ts` `strictPort: true` + `port: 1420` kullanır ve
> `tauri.conf.json` → `devUrl` sabit `http://localhost:1420`'dir. 1420'de başka bir vite
> süreci varsa `tauri dev` "beforeDevCommand terminated" ile ölür; önce o süreci durdurun.

### 1.3 Uygulamayı sürme (CDP)

`WEBVIEW2_ADDITIONAL_BROWSER_ARGUMENTS` WebView2'yi 9222'de DevTools protokolüyle açar.
`scratchpad/cdp.mjs` bağımlılıksız bir sürücüdür (Node'un global `WebSocket`'i):

```bash
curl -s http://127.0.0.1:9222/json         # sayfa hedefini listeler
node ops.mjs                               # senaryoyu koşar
```

İki ayrı sürüş yolu kullanılır:

1. **Gerçek UI** — giriş formu, Çakışma Kutusu düğmeleri, bağlantı çubuğunun senkron
   düğmesi. Ekran görüntülerinin kaynağı budur.
2. **Uygulamanın kendi modülleri** — 20 mutasyon `import('/src/platform/desktop.ts')` ve
   `import('/@fs/…/frontend/src/features/deals/api/boardApi.ts')` ile, yani `DataSource`
   sözleşmesinin ve `boardApi`'nin **kendisi** çağrılarak yapılır.

   Bu bir kısayol değil, bilinçli bir seçimdir: 20 işlemi modal/form/sürükle-bırak
   üzerinden sürmek kırılgandır (özellikle Kanban sürüklemesi), ve test edilmek istenen
   katman React render'ı değil `DataSource → outbox → wire` yoludur. Vite dev sunucusu
   modül kimliğini koruduğu için içe aktarılan modüller **uygulamanın çalışan
   örnekleridir**; bu doğrulanmıştır:

   ```
   getPlatform().data === desktopPlatform.data  →  true
   ```

   Yani `boardApi.moveDealRequest` gerçekten masaüstü platformuna, oradan outbox'a gider.

---

## 2. Senaryo öncesi hazırlık

1. Uygulamada giriş yapılır (gerçek form). Giriş sonrası bootstrap penceresi açılır,
   "Continue" ile kapatılır.
2. Bir temel senkron turu koşulur — çevrimiçi yolun çalıştığı kanıtlanır.
3. **Silinecek kayıt çevrimiçi oluşturulur:** `F4TEST-SEED DeleteMe` adlı lead. Senaryodaki
   tek `delete` bu kayıt üzerinde yapılacaktır (kendi oluşturduğumuz kayıt).
4. Çakışma hedeflerinin sunucudaki taban değerleri not edilir.

Gerçek çıktı:

```
created local: {"localId":-11,"name":"F4TEST-SEED DeleteMe"}
sync: {"pushed":1,"applied":1,"duplicates":0,"conflicts":0,"rejected":0,"deferred":0,
       "pulled_rows":1,"deletions":0,"tables_changed":["lead"]}
after sync leads: ["41:F4TEST-SEED DeleteMe"]      # sunucu id = 41
```

Çakışma hedeflerinin tabanı:

```
company13 sync_version=13   notes="Kurumsal müşteri adayı, yıllık sözleşme potansiyeli yüksek."
ticket12  sync_version=387  subject="Yeni kullanıcı tanımlanamıyor"
```

> **Yerel aynanın kapsamı hakkında.** `retention_days = 30` olduğu için ayna yalnızca son
> 30 günde güncellenmiş satırları tutar. Sunucuda 25 firma vardır ama yalnızca 1'i son 30
> günde güncellenmiştir; aynada da 1 firma vardır (tutarlı). Bootstrap ekranı buna karşın
> "Company: 22 records" der — indirilen sayfa satırı ile saklanan satır aynı sayı değildir
> (§7, B5).

---

## 3. Ağın kesilmesi

```powershell
Get-NetTCPConnection -LocalPort 8010 -State Listen | Stop-Process -Id $_.OwningProcess -Force
```

Motorun bunu görmesi için bir senkron denemesi gerekir (aşağıdaki `sync_now` başarısız
olur ve `online` bayrağını düşürür):

```
$ curl -m 3 http://127.0.0.1:8010/api/me            → exit 7 (bağlanamadı)
sync_now  → ERR {"code":"OFFLINE","message":"offline"}
status    → {"online":false,"syncing":false,"pending":0,"conflicts":0,...}
```

Ekran görüntüsü: `f4-shots/03-offline-bar.png` — bağlantı çubuğu "Offline".

---

## 4. 20 işlem (hepsi çevrimdışı)

Dağılım şartnamedeki gibidir: **5 create · 6 update · 3 move · 3 message · 2 task complete
· 1 delete**. Tamamı `scratchpad/ops.mjs` ile, 396 ms içinde koşuldu.

| # | Tip | Kayıt / çağrı | Beklenen | Yerel sonuç (gerçek) | Sunucu sonucu (gerçek) |
|---|---|---|---|---|---|
| 1 | create | `companies.create` → `F4TEST-Company-A` | outbox'a `company/create` | yerel id `-2` | **applied**, server id 26 |
| 2 | create | `contacts.create` → `F4TEST Contact-B` | outbox'a `contact/create` | yerel id `-19` | **applied**, server id 61 |
| 3 | create | `leads.create` → `F4TEST Lead-C` | outbox'a `lead/create` | yerel id `-12` | **applied**, server id 42 |
| 4 | create | `tasks.create` → `F4TEST-Task-D` | outbox'a `task/create` | yerel id `-49` | **rejected** `INVALID_MUTATION` — "Seçilen öncelik geçersiz." ⚠️ testçi hatası, bkz. §7 T1 |
| 5 | create | `tickets.create` → `F4TEST-Ticket-E` | outbox'a `ticket/create` | yerel id `-17` | **applied**, server id 31 |
| 6 | update | `contacts.update(39, {position:'F4TEST-pos-v1'})` | outbox'a `contact/update` | ok | (7 ile birleşti) |
| 7 | update | `contacts.update(39, {position:'F4TEST-pos-v2'})` | **6 ile aynı outbox satırına katlanmalı** | ok | **applied** — sunucuda `"F4TEST-pos-v2"` |
| 8 | update | `companies.update(13, {notes:…})` | **ÇAKIŞMA HEDEFİ 1** | ok | **conflict** `FIELD_CONFLICT` `["notes"]` |
| 9 | update | `tickets.update(12, {subject:…})` | **ÇAKIŞMA HEDEFİ 2** | ok | **conflict** `FIELD_CONFLICT` `["subject"]` |
| 10 | update | `leads.update(37, {notes:'F4TEST-lead37-notes'})` | uygulanmalı | ok | **applied** |
| 11 | update | `tasks.update(45, {title:'F4TEST-task45-renamed'})` | uygulanmalı | ok | **applied** |
| 12 | move | `boardApi.moveDealRequest(26, {to_stage_id:2,…})` | outbox'a `deal.move` | yerel aşama 1→2 | **rejected** — "Action is not whitelisted: move" ❌ |
| 13 | move | `boardApi.moveDealRequest(32, {to_stage_id:3,…})` | outbox'a `deal.move` | yerel aşama 2→3 | **rejected** ❌ |
| 14 | move | `boardApi.moveDealRequest(48, {to_stage_id:5,…})` | outbox'a `deal.move` | yerel aşama 3→5 | **rejected** ❌ |
| 15 | message | `chat.sendMessage(10, 'F4TEST-offline-message-1')` | outbox'a `message/create` | yerel id `-14` | **applied**, server id 121 |
| 16 | message | `chat.sendMessage(10, 'F4TEST-offline-message-2')` | outbox'a `message/create` | yerel id `-15` | **applied**, server id 122 |
| 17 | message | `chat.sendMessage(9, 'F4TEST-offline-message-3')` | outbox'a `message/create` | yerel id `-16` | **applied**, server id 123 |
| 18 | complete | `tasks.complete(31, true)` | outbox'a `task.complete` | yerel `completed` | **rejected** — "Action is not whitelisted: complete" ❌ |
| 19 | complete | `tasks.complete(1, true)` | outbox'a `task.complete` | yerel `completed` | **rejected** ❌ |
| 20 | delete | `leads.delete(41)` (kendi oluşturduğumuz kayıt) | outbox'a `lead/delete` | tombstone | **applied**, `deleted_at=2026-08-31 18:39:46` |

Gerçek koşum çıktısı (kısaltılmadan `scratchpad/ops-log.json` içinde):

```
outbox_count before: 0
outbox_count after: 19 | wall ms: 402
 1 create   OK   company F4TEST-Company-A -> {"localId":-2,"name":"F4TEST-Company-A"}
 …
20 delete   OK   lead 41 (F4TEST-SEED DeleteMe) delete -> {"id":41,"deleted":true}
```

**20 işlem → 19 outbox satırı.** Aradaki fark coalescing'dir (§5).

### 4.1 Çevrimdışı UI kanıtı

```
bar: "Offline · 19 pending changes · Last synced 7 minutes ago"
     refreshDisabled=true   refreshTitle="No internet connection."
```

Bekleyen kayıtlar paneli 18 satır listeler (19'uncu, silinmiş `lead 41`'dir; tombstone
satırları liste sorgularından düşer):

```
company  F4TEST-Company-A                     Pending
company  Doğu Kimya Sanayi A.Ş.               Pending
contact  f4test-b@example.invalid             Pending
contact  onur.gunes39@firma14.com.tr          Pending      ← 6+7 TEK satır
lead     F4TEST Lead-C                        Pending
lead     ibrahim.cetin3@firma3.com.tr         Pending
deal ×3, task ×4, ticket ×2, message ×3       Pending
```

Ekran görüntüleri: `f4-shots/04-offline-after-20-ops.png`,
`f4-shots/05-offline-pending-panel.png`.

---

## 5. Sıra ve coalescing

İki bağımsız kanıt vardır.

**(a) Outbox katlanması.** 6 ve 7 numaralı işlemler aynı satırın (`contact 39`) aynı
alanını arka arkaya değiştirir. `syncra_sync::outbox` §5.4 kuralı gereği ardışık
`update`'ler tek satıra katlanır (`changed_fields` birleşir, son değer kazanır,
`base_sync_version` **ilk** düzenlemeden korunur). Sonuç: 20 işlem → 19 outbox satırı,
UI'da tek "Pending" rozeti (§7.2 sözleşmesi).

**(b) Wire üzerindeki `seq` sırası.** Push gövdesi `:8010`'a konan kayıt eden bir
ters-vekil ile aynen yakalandı (`scratchpad/proxy.mjs` → `scratchpad/proxy-sync.log`).
19 mutasyonun `seq` alanları 1..19'dur ve **boşluk yoktur** — yani coalescing bir `seq`
yutmamış, iki işlem tek `seq`'e katlanmıştır:

```json
{"seq":6,"op":"update","entity":"contact","server_id":39,"base_sync_version":64,
 "changed_fields":["position"],"occurred_at":"2026-08-31T18:35:11.726Z",
 "payload":{"position":"F4TEST-pos-v2"}}
```

`occurred_at` **ilk** düzenlemenin (op 6) zamanı, `payload` **son** düzenlemenin (op 7)
değeridir. Sunucudaki son değer `"F4TEST-pos-v2"` — sıra doğru.

Mutasyonlar wire'a `SYNCDESKTOP.md` §5.4 topolojik sırasında dizilir (create'ler
kendi entity'lerinin action/update'lerinden önce); `seq` değerleri gövde içinde artan
sırada değil, **topolojik grup** sırasındadır ve sunucu `seq`'e göre değil dizilim
sırasına göre uygular. `deal.move` mutasyonunun gövdesi de burada doğrulanır:

```json
{"seq":11,"op":"action","entity":"deal","server_id":26,"action":"move",
 "payload":{"after_deal_id":null,"before_deal_id":null,
            "pipeline_stage_id":2,"to_stage_id":2,"version":1}}
```

→ `to_stage_id` **var** ve doğru; `boardApi` → `DataSource` → outbox yolu çalışıyor.
Reddedilme sebebi bu payload değil, `action` alanının adıdır (§7, B1).

---

## 6. Kasıtlı iki çakışma

### 6.1 Nasıl üretildi

`app/Services/Sync/ConflictDetector.php` bir `update`'i ancak şu iki koşul birlikte
sağlanınca `FIELD_CONFLICT` sayar:

1. `server.sync_version > base_sync_version`, **ve**
2. istemcinin `occurred_at`'inden **sonra** yazılmış `activity_log` kayıtlarının
   dokunduğu alanlar ile mutasyonun `changed_fields`'i **kesişiyor**.

Bu yüzden çakışma üretmek için, çevrimdışı düzenlenen **aynı alanlar**, yerel
`occurred_at`'ten **sonra** sunucuda da değiştirilir. Motor `:8010`'a erişemezken bu
düzenlemeler `:8011` üzerinden gerçek REST uçlarıyla yapılır:

```bash
curl -X PATCH http://127.0.0.1:8011/api/companies/13 \
  -H "Authorization: Bearer <device-token>" -H 'Content-Type: application/json' \
  -d '{"notes":"F4TEST-SERVER-notes-company13"}'          # → 200

curl -X PATCH http://127.0.0.1:8011/api/tickets/12 \
  -H "Authorization: Bearer <device-token>" -H 'Content-Type: application/json' \
  -d '{"subject":"F4TEST-SERVER-subject-ticket12"}'       # → 200
```

Bearer belirteci `POST /api/auth/device` ile alınır (`device_fingerprint` **64 karakterlik
hex** olmalıdır; kısa değer 422 döner).

Doğrulama — sürüm ilerledi ve doğru alanlar denetim kaydına düştü:

```
company13 sync_version=1017  notes=F4TEST-SERVER-notes-company13
ticket12  sync_version=1018  subject=F4TEST-SERVER-subject-ticket12
AL 91 2026-08-31 18:36:56 {"attributes":{"notes":"F4TEST-SERVER-notes-company13"},…}
AL 92 2026-08-31 18:36:56 {"attributes":{"subject":"F4TEST-SERVER-subject-ticket12"},…}
```

Yerel `occurred_at` 18:35:11 < sunucu düzenlemesi 18:36:56 → koşul sağlandı.

### 6.2 Inbox'ta göründü mü

Evet. Push yanıtı (gerçek):

```json
{"seq":7,"status":"conflict","sync_version":1017,"code":"FIELD_CONFLICT",
 "conflicting_fields":["notes"],"server_row":{…"notes":"F4TEST-SERVER-notes-company13"…}}
{"seq":8,"status":"conflict","sync_version":1018,"code":"FIELD_CONFLICT",
 "conflicting_fields":["subject"],"server_row":{…"subject":"F4TEST-SERVER-subject-ticket12"…}}
```

`ConflictInbox.tsx` bunları **gerçek çakışma** başlığı altında, tek yönlü redlerden ayırarak
gösterir (`docs/DESKTOP-ARCHITECTURE.md` EK 3 / A22):

```
● 2   This record has changes that conflict with the server.
      company  93eabcef-…   Merge field by field | Keep mine | Take server's
      ticket   2c8718e8-…   Merge field by field | Keep mine | Take server's
● 6   An unknown error occurred.
      task, deal, deal, deal, task, task   (INVALID_MUTATION redleri)
```

Ekran görüntüsü: `f4-shots/09-conflict-inbox.png`.

### 6.3 Çözüm ve çözüm sonrası tutarlılık

Çözüm **gerçek UI düğmeleriyle** yapıldı:

* `company 13` → **Take server's**
* `ticket 12` → **Keep mine**

`Keep mine` mutasyonu yeniden kuyruğa alır (`pending: 0 → 1`); bir senkron turu daha
gerekir:

```
sync: {"pushed":1,"applied":1,"conflicts":0,"rejected":0,"pulled_rows":1,
       "tables_changed":["ticket"]}   (543 ms)
```

Sonuç — yerel ayna ve sunucu **aynı şeyi söylüyor**:

| Kayıt | Çözüm | Yerel ayna | Sunucu (`:8011` REST) |
|---|---|---|---|
| company 13 `notes` | Take server's | `F4TEST-SERVER-notes-company13` | `F4TEST-SERVER-notes-company13` |
| ticket 12 `subject` | Keep mine | `F4TEST-local-subject-ticket12` | `F4TEST-local-subject-ticket12` |

Ekran görüntüsü: `f4-shots/10-after-resolution.png`.

---

## 7. Ağın açılması ve ölçüm

### 7.1 Ölçüm yöntemi

* **Ağ açma anı**: `:8010`'a kayıt eden ters-vekil (`scratchpad/proxy.mjs`, `:8010 → :8011`)
  dinlemeye başladığı an. Vekil aynı zamanda **her `/api/sync/*` isteğinin gövdesini** de
  yazar, böylece "sunucuya ne gitti" iddiası kanıta bağlanır.
* **"Sunucuda" doğrulaması**: (a) push yanıtındaki `status` alanları, (b) bağımsız olarak
  `php artisan tinker` ile salt-okunur model sorguları, (c) `:8011` üzerinden REST GET.
* **Süre**: Node tarafında `Date.now()` ile; `status.pending === 0` olana kadar 250 ms
  aralıkla yoklama.

### 7.2 ÖLÇÜM 1 — kendiliğinden toparlanma: **YOK**

Ağ 18:37:48.863Z'de geri geldi. Sonraki **79 saniye** boyunca 5 saniyede bir yoklandı:

```
t+ 19s online=false pending=19 | Offline · 19 pending changes | refreshDisabled=true
t+ 24s online=false pending=19 | … refreshDisabled=true
…
t+ 79s online=false pending=19 | Offline · 19 pending changes | refreshDisabled=true
```

Vekilin gördüğü senkron isteği sayısı: **0**. `sync_now` elle çağrıldığında da reddediyor:

```
sync_now → ERR {"code":"OFFLINE","message":"offline"}
```

Ekran görüntüsü: `f4-shots/11-bar-offline-refresh-disabled.png` — sunucu ayakta, çubuk
hâlâ "Offline", yenile düğmesi soluk.

Sebebi §7.5, B2'de. **Bu hâliyle "≤60 sn'de sunucuda" ölçütü sağlanamaz.**

### 7.3 ÖLÇÜM 2 — kullanıcıya açık tek toparlanma yolu: uygulamayı yeniden başlatmak

Uygulama açılışında `main.desktop.tsx` → `restoreDesktopSession()` → `auth::restore` →
`SyncEngine::restore_session()` çalışır ve bu **`set_online_flag(true)`** yapar. Webview'i
yeniden yüklemek bu açılış yolunu birebir tekrar çalıştırır (Rust süreci ve motor durumu
aynı kalır), yani uygulamayı kapatıp açmakla aynı etkidedir.

```
T0  webview reload (= uygulama yeniden başlatma yolu)   18:39:41.598Z
T1  motor tekrar çevrimiçi, pending=19                  T0 + 4.0 s
    bar: "Online · 19 pending changes"  refreshDisabled=false
T2  bağlantı çubuğundaki senkron düğmesine TIKLANDI     18:39:45.666Z
T3  pending = 0                                         T0 + 4.8 s   (tıklamadan 0.78 s sonra)
```

Yani **toparlanma eylemi verildiği anda 19 mutasyonun tamamı 4.8 saniyede sunucuya
ulaştı** — 60 saniyelik bütçenin çok altında. Sorun sürede değil, **eylemin UI'da mevcut
olmamasında**.

Ekran görüntüleri: `f4-shots/07-online-again-before-sync.png`,
`f4-shots/08-after-sync.png`.

### 7.4 Push sonucu (19 mutasyon, tek batch, HTTP 200)

| Sonuç | Adet | Hangi işlemler |
|---|---|---|
| `applied` | 11 | 1, 2, 3, 5, 6+7, 10, 11, 15, 16, 17, 20 |
| `conflict` (`FIELD_CONFLICT`) | 2 | 8, 9 — **kasıtlı olanlar** |
| `rejected` (`INVALID_MUTATION`) | 6 | 4 (testçi hatası) + 12, 13, 14, 18, 19 (**ürün hatası**) |

Motorun `status.conflicts` sayacı 8 gösterir: 2 gerçek çakışma + 6 red. Çakışma Kutusu
ikisini ayrı başlıklar altında listeler.

Sunucu tarafı bağımsız doğrulama (`php artisan tinker`, salt okunur):

```
--- creates ---
company 26 => F4TEST-Company-A
contact 61 => F4TEST Contact-B
lead 42    => F4TEST Lead-C
ticket 31  => F4TEST-Ticket-E
task titled F4TEST-Task-D => ABSENT
--- updates ---
contact39.position = "F4TEST-pos-v2"          ← sıra doğru
company13.notes    = "F4TEST-SERVER-notes-company13"
ticket12.subject   = "F4TEST-local-subject-ticket12"
lead37.notes       = "F4TEST-lead37-notes"
task45.title       = "F4TEST-task45-renamed"
--- moves ---
deal 26 stage=1 version=1                     ← DEĞİŞMEDİ (red)
deal 32 stage=2 version=1                     ← DEĞİŞMEDİ (red)
deal 48 stage=3 version=1                     ← DEĞİŞMEDİ (red)
--- messages ---
msg 121 conv10 F4TEST-offline-message-1
msg 122 conv10 F4TEST-offline-message-2
msg 123 conv9  F4TEST-offline-message-3
--- task completes ---
task 31 status=pending completed_at=null      ← DEĞİŞMEDİ (red)
task 1  status=pending completed_at=null      ← DEĞİŞMEDİ (red)
--- delete ---
lead41 deleted_at="2026-08-31 18:39:46"
```

### 7.5 Bulgular

#### B1 — `op=action` mutasyonlarının hiçbiri sunucuda uygulanamıyor (KRİTİK)

Rust istemcisi `entity` ve `action` alanlarını **ayrı** gönderir:

```json
{"op":"action","entity":"deal","action":"move", …}
{"op":"action","entity":"task","action":"complete", …}
```

Sunucu ise `MutationApplier::applyAction()` içinde **çıplak** `$mutation->action` değerini
**noktalı** bir listeyle karşılaştırır:

```php
public const ALLOWED_ACTIONS = ['deal.move', 'deal.assign', 'task.complete', …];
if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
    return MutationResult::rejected($mutation->seq, 'INVALID_MUTATION',
        'Action is not whitelisted: '.$action);
}
```

`"move" !== "deal.move"` → **her çevrimdışı action reddedilir.** Bu, tek bir fiili değil
`ACTION_WHITELIST`'in tamamını (12 fiil) kapsar.

Kök neden, aynı kayıt (kendi oluşturduğumuz `task 81`) üzerinde iki push isteğiyle
kanıtlandı:

```
action="complete"       → rejected  INVALID_MUTATION  "Action is not whitelisted: complete"
action="task.complete"  → rejected  INVALID_MUTATION  "tamamlanma durumu alanı zorunludur."
```

İkinci istek **beyaz listeyi geçti** ve handler'a ulaştı; oradaki hata ikinci bir
uyuşmazlıktır (aşağıda B3). Payload eklenince uygulanıyor:

```
action="task.complete" + payload {"completed":true}  → applied, sync_version 1046
```

Hangi taraf haklı? `docs/DESKTOP-SYNC-PROTOCOL.md` KARAR P10'un kendi wire örneği
**çıplak** action kullanır:

```json
{ "op": "action", "entity": "notification", "action": "read_all", "scope": "user", … }
```

Yani protokol sözleşmesine göre **istemci doğru, sunucunun listesi yanlış**. Backend
testleri (`tests/Feature/Sync/SyncPushTest.php`) mutasyonları `'action' => 'deal.move'`
şeklinde kurduğu için yeşil kalır; Rust crate testleri de kendi tarafında yeşildir. Hata
tam olarak iki yeşil takımın arasında yaşıyor — F4'ün var oluş sebebi budur.

> Not: `notification.read_all` da aynı listede noktalı olduğu için **o da** hiç
> çalışamaz.

#### B2 — Ağ geri geldiğinde uygulama kendiliğinden çevrimiçi olamıyor (KRİTİK)

Üç şey üst üste binince kilit oluşuyor:

1. `SyncEngine::sync_now()` ilk satırında `if !self.status().online { return Err(Offline) }`
   der — yani senkron denemesi **çevrimdışıyken hiç ağa çıkmaz**, dolayısıyla bağlantının
   döndüğünü kendi başına keşfedemez.
2. `online` bayrağını `true` yapan tek yollar `login()`, `restore_session()` ve
   `SyncEngine::set_online(true)`'dur. Sonuncusunu çağıran tek şey arka plan döngüsüdür.
3. Arka plan döngüsü **başlatılmıyor**. `src-tauri/src/lib.rs` başlığı bunu açıkça yazar:
   `start_background_sync` F5 kapsamına bırakılmıştır (tepsi / ağ olayı tetikleyicileriyle
   birlikte). Ayrıca `ConnectivityBar`'ın senkron düğmesi `syncDisabled = !status.online`
   ile **çevrimdışıyken devre dışıdır** ve masaüstü UI'daki tek `syncNow()` çağrısı odur.

Sonuç: uygulama bir kez çevrimdışına düştüğünde, kullanıcının elinde uygulamayı yeniden
başlatmaktan başka yol yoktur. 79 saniyelik gözlem ve boş vekil günlüğü bunu kanıtlar.

Bu **F4'ün kabul kriterini doğrudan düşürür**; F5'e bırakılmış olması bir açıklamadır ama
§10'daki kabul cümlesini karşılamaz.

#### B3 — `task.complete` payload'ı eksik gidiyor

`desktop/src/platform/data/work.ts` → `complete()` `runAction('task', id, 'complete')`
çağırır, yani `payload: {}`. Sunucunun `CompleteTaskRequest`'i ise
`'completed' => ['required','boolean']` ister. B1 düzeltilse bile bu mutasyon
`INVALID_MUTATION` alır. Yukarıdaki üç adımlı curl kanıtı bunu ayrı ayrı gösterir.

`deal.move` için aynı sorun **yok**: istemcinin gönderdiği
`{to_stage_id, before_deal_id, after_deal_id, version, pipeline_stage_id}` gövdesi
`MoveDealRequest` kurallarını karşılar (`position` gönderilmiyor; fazladan
`pipeline_stage_id` `validated()`'da düşer).

#### B4 — `INVALID_MUTATION` için i18n anahtarı yok

Çakışma Kutusu 6 reddi **"An unknown error occurred."** başlığıyla gösteriyor; kullanıcı
hangi işlemin neden reddedildiğini göremiyor. Ayrıca satırlar kayıt adını değil `client_id`
UUID'sini yazıyor.

#### B5 — Bootstrap ilerleme sayısı ile saklanan satır sayısı tutmuyor

Bootstrap ekranı "Company: 22 records" der; `retention_days=30` sonrası aynada 1 firma
kalır (sunucuda son 30 günde güncellenmiş firma sayısı da 1). Sayı yanıltıcı; veri kaybı
değil, gösterim sorunu.

#### T1 — (testçi hatası, ürün hatası değil) geçersiz `priority`

4 numaralı işlemde `priority: 'medium'` gönderildi. Hem sunucu
(`Rule::in(['low','normal','high','urgent'])`) hem de frontend `TASK_PRIORITIES` bu değeri
tanımaz. İkinci bir çevrimdışı turda `priority: 'normal'` ile tekrarlandı ve **uygulandı**:

```
sync: {"pushed":2,"applied":1,"rejected":1,…}
push req : {"seq":19,"op":"create","entity":"task","payload":{"priority":"normal",…}}
push resp: {"seq":19,"status":"applied","server_id":81,"sync_version":1045}
           {"seq":20,"status":"rejected","code":"INVALID_MUTATION",
            "message":"Action is not whitelisted: complete"}
```

Yani **5/5 create çevrimdışı çalışıyor**; senaryo tablosundaki 4 numaralı satırın redi
girdi hatasıdır. Aynı turda `task.complete`'in yine reddedilmesi B1'i ikinci kez
doğrular.

### 7.6 Kabul kriterleri

| Kriter | Sonuç |
|---|---|
| 20 işlem çevrimdışı yapılabiliyor | ✅ 20/20 yerelde başarılı, outbox 19 satır |
| ≤60 sn içinde sunucuda | ❌ **kendiliğinden hiç olmuyor** (B2). Toparlanma eylemi verildiğinde 4.8 s. |
| Tüm mutasyonlar sunucuda | ❌ 19'un 11'i uygulandı, 2'si (kasıtlı) çakıştı, 6'sı reddedildi — 5'i B1 yüzünden |
| Sıra doğru / coalescing beklendiği gibi | ✅ 6+7 tek satıra katlandı, sunucudaki son değer `pos-v2`, UI'da tek rozet |
| 2 kasıtlı çakışma inbox'ta | ✅ ikisi de `FIELD_CONFLICT`, doğru `conflicting_fields` |
| Çözüm sonrası tutarlı | ✅ TakeServer ve KeepMine sonrası yerel ayna = sunucu |

---

## 8. Ekran görüntüleri

Kök: `scratchpad/f4-shots/` (bu koşumun çıktıları; CI'da yol değişir).

| Dosya | Ne gösteriyor |
|---|---|
| `00-initial.png` | Giriş ekranı, çubuk "Offline · Not synced yet" |
| `01-after-login.png` | Giriş sonrası dashboard |
| `02-online-board.png` | Çevrimiçi Kanban panosu (taşımalardan önce) |
| `03-offline-bar.png` | `:8010` durdurulduktan sonra "Offline" |
| `04-offline-after-20-ops.png` | 20 işlem sonrası uygulama |
| `05-offline-pending-panel.png` | "19 pending changes" paneli, kayıt kayıt |
| `06-network-back-still-offline.png` | Ağ döndükten sonra hâlâ 19 pending |
| `07-online-again-before-sync.png` | Yeniden başlatma sonrası "Online · 19 pending changes" |
| `08-after-sync.png` | Senkron sonrası "Conflict detected" |
| `09-conflict-inbox.png` | Çakışma Kutusu: 2 gerçek çakışma + 6 red, ayrı başlıklarda |
| `10-after-resolution.png` | İki çakışma çözüldükten sonra |
| `11-bar-offline-refresh-disabled.png` | **B2 kanıtı** — sunucu ayakta, çubuk "Offline", yenile düğmesi devre dışı |
| `12-final-state.png` | Koşum sonu |

Ham kanıtlar: `scratchpad/ops-log.json` (20 işlem, zaman damgalı),
`scratchpad/proxy-sync.log` (her `/api/sync/*` isteği ve yanıtı, JSONL).

---

## 9. Başarısızlık durumunda nereye bakılır

| Belirti | Bakılacak yer |
|---|---|
| `tauri dev` "beforeDevCommand terminated" | 1420 portunda başka bir vite var (`strictPort`) |
| Uygulama açılıyor ama her `invoke()` patlıyor | CSP'de `ipc: http://ipc.localhost` eksik → `scripts/tauri.mjs` |
| Motor doğru sunucuya gitmiyor | `SYNCRA_API_URL` **derleme zamanı** (`option_env!`); crate yeniden derlendi mi? Log'da `Compiling syncra-desktop` görünmeli |
| Webview API'ye çıkamıyor | `VITE_API_URL` ile CSP `connect-src` aynı origin'i göstermeli — `.tauri/tauri.conf.generated.json` |
| `sync_now` → `OFFLINE` ama sunucu ayakta | B2. `status.online` yalnızca `login`/`restore_session` ile `true` olur |
| Push 422 `device fingerprint` | `POST /api/auth/device` `device_fingerprint` **64 hex karakter** ister |
| Push 422 `batch id alanı zorunludur` | Elle push atarken gövdeye `batch_id` (UUID) eklenmeli |
| Beklenen çakışma oluşmuyor | Sunucu düzenlemesi istemcinin `occurred_at`'inden **sonra** ve **aynı alanda** olmalı; `activity_log` tutmayan varlıklarda (conversation/message/notification) kayıt seviyesine düşer |
| `INVALID_MUTATION: Action is not whitelisted` | B1 — istemci çıplak, sunucu noktalı action adı bekliyor |
| Aynada beklenen satır yok | `retention_days` (varsayılan 30) penceresi dışında kalmış olabilir; `storage_settings` ile bakılır |

---

## 10. Temizlik

```powershell
# :8010 / :8011 ve varsa vekil
Get-NetTCPConnection -LocalPort 8010,8011 -State Listen |
  ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
# tauri dev + cargo watcher + vite (1420)
```

Geliştiricinin `:8000` örneğine dokunulmaz.

Bu koşumun sunucuda bıraktığı kayıtlar (silme kararı teknik lidere aittir):

| Varlık | id | Ad / durum |
|---|---|---|
| company | 26 | `F4TEST-Company-A` |
| contact | 61 | `F4TEST Contact-B` |
| lead | 42 | `F4TEST Lead-C` |
| lead | 41 | `F4TEST-SEED DeleteMe` — **soft-deleted** (senaryonun `delete` işlemi) |
| ticket | 31 | `F4TEST-Ticket-E` |
| task | 81 | `F4TEST-Task-D2` — `completed` |
| message | 121, 122, 123 | `F4TEST-offline-message-1..3` (conv 10, 10, 9) |
| personal_access_token | 16 | `F4TEST-probe` (curl için cihaz belirteci) |

Mevcut kayıtlarda bırakılan değişiklikler (geri alınmadı):

| Kayıt | Alan | Yeni değer |
|---|---|---|
| company 13 | `notes` | `F4TEST-SERVER-notes-company13` |
| contact 39 | `position` | `F4TEST-pos-v2` |
| lead 37 | `notes` | `F4TEST-lead37-notes` |
| task 45 | `title` | `F4TEST-task45-renamed` |
| ticket 12 | `subject` | `F4TEST-local-subject-ticket12` |

Yerel aynada (`%APPDATA%\com.syncra.desktop\syncra\syncra.db`) 7 adet çözülmemiş
`INVALID_MUTATION` reddi Çakışma Kutusu'nda durmaktadır; B1 düzeltilmeden çözülmeleri
anlamlı değildir.
