# Masaüstü Çevrimdışı Kabul Senaryosu — F4

`SYNCDESKTOP.md` §10, F4 kabul kapısı:

> Ağ kes → **20 işlem (5 create, 6 update, 3 move, 3 message, 2 task complete, 1 delete)** →
> ağ aç → **≤60 sn'de sunucuda, sıra doğru, kasıtlı 2 çakışma inbox'ta, çözüm sonrası
> tutarlı**.

Bu belge senaryoyu **tekrarlanabilir** biçimde tarif eder ve **iki gerçek koşumun** çıktılarını
içerir. Belgenin en değerli kısmı bu ikilik: aynı senaryo bir kez **kırmızı**, bir kez **yeşil**
koştu ve aradaki fark, senaryonun bulduğu iki üretim hatasıdır.

| | Koşum 1 | Koşum 2 |
|---|---|---|
| Tarih | 2026-08-31 18:35–18:45 UTC | 2026-08-31 19:37–19:45 UTC |
| Sonuç | ❌ **GEÇMEDİ** | ✅ **GEÇTİ** |
| Sunucuya inen | 19 mutasyonun 11'i | 19 mutasyonun **17'si** (+2 kasıtlı çakışma) |
| Reddedilen | **6** (5'i ürün hatası) | **0** |
| Kendiliğinden toparlanma | **YOK** — 79 sn boyunca fark etmedi, yeniden başlatma gerekti | **VAR** — ağ döndükten **15.1 sn** sonra probe keşfetti, **16.3 sn**'de kuyruk boşaldı, uygulamaya hiç dokunulmadı |

Koşum 1'in bulduğu iki kritik hata (§8, B1 ve B2) düzeltildi; koşum 2 düzeltmelerin gerçekten
çalıştığını ölçtü. Koşum 2 ayrıca **yeni bir hata** buldu (§8, B7).

---

## 1. Ön koşullar

| Gereksinim | Bu koşumlardaki değer |
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
  derlenmesini tetikler (koşum 1'de 44.90 s; koşum 2'de yalnızca `syncra-desktop` yeniden
  derlendi, 18.03 s).
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

1. **Gerçek UI** — giriş formu, Çakışma Kutusu düğmeleri, bağlantı çubuğu, komut paleti,
   Kanban panosu. Ekran görüntülerinin kaynağı budur.
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

### 1.4 Ölçüm yöntemi (koşum 2'de sertleştirildi)

* **Ağ açma anı (T0)**: `:8010`'a kayıt eden ters-vekilin (`scratchpad/run2/measure.mjs`,
  `:8010 → :8011`) `listen` geri çağrımının koştuğu an. Vekil aynı zamanda **her
  `/api/sync/*` isteğinin gövdesini** de yazar, böylece "sunucuya ne gitti" iddiası kanıta
  bağlanır. Koşum 2'de vekil ve yoklayıcı **tek Node sürecidir** — T0, "online" ve
  "pending==0" anları aynı saatten okunur, saat farkı tartışması kalmaz.
* **Uygulamaya dokunmama**: yoklama yalnızca `status` komutuyla yapılır. `commands/sync.rs`
  bu komutu "cheap, synchronous snapshot — safe to poll" diye tanımlar; hiçbir senkron
  tetiklemez. Vekil günlüğündeki ilk isteğin **motorun kendi probe'u** olması bunun bağımsız
  kanıtıdır.
* **"Sunucuda" doğrulaması**: (a) vekilin yakaladığı push yanıtındaki `status` alanları,
  (b) bağımsız olarak `php artisan tinker` ile salt-okunur model sorguları, (c) `:8011`
  üzerinden REST GET.

---

## 2. Senaryo öncesi hazırlık

1. Uygulamada oturum açık olmalı (koşum 2'de OS keychain'deki cihaz belirteci ile
   `restore_session` kendiliğinden çalıştı; koşum 1'de gerçek giriş formu kullanıldı).
2. **Temiz taban.** `status` → `online=true, pending=0, conflicts=0` ve `storage_stats`
   → `outbox_count=0` olmalı. Koşum 2'ye başlarken koşum 1'den kalan 7 çözülmemiş
   `INVALID_MUTATION` reddi vardı; hepsi `resolve_conflict(take_server)` ile atıldı.

   > `resolve_conflict`'in `choice` argümanı **adjacently tagged** bir enum'dur:
   > `{ "kind": "take_server" }`. Düz `"take_server"` göndermek
   > `invalid type: string ... expected adjacently tagged enum Resolution` verir.
3. Bir temel senkron turu koşulur — çevrimiçi yolun çalıştığı kanıtlanır.
4. **Silinecek kayıt çevrimiçi oluşturulur.** Senaryodaki tek `delete` bu kayıt üzerinde
   yapılır; var olan hiçbir kayıt silinmez.
5. Çakışma hedeflerinin sunucudaki taban değerleri not edilir.

Koşum 2 gerçek çıktısı:

```
baseline sync: {"pushed":0,"applied":0,...,"tables_changed":[]}
created local: {"localId":-13,"name":"F4RUN2-SEED DeleteMe"}
sync: {"pushed":1,"applied":1,"duplicates":0,"conflicts":0,"rejected":0,"deferred":0,
       "pulled_rows":1,"deletions":0,"tables_changed":["lead"]}
after: seedLeads ["43:F4RUN2-SEED DeleteMe"]          # sunucu id = 43
       company13notes "F4TEST-SERVER-notes-company13"
       ticket12subject "F4TEST-local-subject-ticket12"
       contact39pos   "F4TEST-pos-v2"
```

> **Kayıt öneki.** Koşum 1 `F4TEST-`, koşum 2 `F4RUN2-` öneki kullanır. Önek koşumları
> birbirinden ayırır ve her koşumun **yalnızca kendi ürettiği** kayıtlara dokunmasını
> denetlenebilir kılar.

> **Yerel aynanın kapsamı hakkında.** `retention_days = 30` olduğu için ayna yalnızca son
> 30 günde güncellenmiş satırları tutar. Sunucuda 25 firma vardır ama yalnızca 1'i son 30
> günde güncellenmiştir; aynada da 1 firma vardır (tutarlı). Bootstrap ekranı buna karşın
> "Company: 22 records" der — indirilen sayfa satırı ile saklanan satır aynı sayı değildir
> (§8, B5).

---

## 3. Ağın kesilmesi

```powershell
Get-NetTCPConnection -LocalPort 8010 -State Listen |
  ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
```

**Koşum 1'de** motorun bunu görmesi için elle bir senkron denemesi gerekiyordu. **Koşum 2'de
gerekmedi** — arka plan döngüsü (O46) kendi 60 sn'lik turunda keşfetti:

```
19:37:22.810Z  :8010 durduruldu
19:37:32       arka plan turu :8010'a bağlanmayı denedi ve başarısız oldu → online=false
19:37:35       ilk connectivity probe (başarısız)
19:37:37/40/44/50, 19:38:00/18/50, 19:39:22/54, 19:40:26/58   → ramp
```

Probe aralıkları **1, 2, 4, 8, 16, 30, 30 …** sn tasarımına (`offline_probe_delay`) birebir
uyar; günlükteki fark 3, 4, 6, 10, 18, 32, 32 sn'dir — aradaki ~2 sn her probe'un kendi
bağlantı zaman aşımıdır.

Elle doğrulama:

```
$ curl -m 3 http://127.0.0.1:8010/api/me            → exit 7 (bağlanamadı)
sync_now  → ERR {"code":"OFFLINE","message":"offline"}   (2 ms — hiç ağa çıkmadan)
status    → {"online":false,"syncing":false,"pending":0,"conflicts":0,...}
bar       → "Offline · · · Last synced 1 minute ago"  refreshDisabled=true
             refreshTitle="No internet connection."
```

Ekran görüntüsü: `f4-shots-run2/02-offline-bar.png`.

---

## 4. 20 işlem (hepsi çevrimdışı)

Dağılım şartnamedeki gibidir: **5 create · 6 update · 3 move · 3 message · 2 task complete
· 1 delete**. Koşum 2'de tamamı `scratchpad/run2/ops.mjs` ile, **205 ms** içinde koşuldu
(`19:38:28.570Z` – `19:38:28.770Z`), 20/20 yerelde başarılı.

### 4.1 Koşum 2 — tablo

| # | Tip | Kayıt / çağrı | Yerel sonuç (gerçek) | Sunucu sonucu (gerçek) |
|---|---|---|---|---|
| 1 | create | `companies.create` → `F4RUN2-Company-A` | yerel id `-3` | **applied**, server id 27 |
| 2 | create | `contacts.create` → `F4RUN2 Contact-B` | yerel id `-20` | **applied**, server id 62 |
| 3 | create | `leads.create` → `F4RUN2 Lead-C` | yerel id `-14` | **applied**, server id 44 |
| 4 | create | `tasks.create` → `F4RUN2-Task-D` (`priority: 'normal'`) | yerel id `-51` | **applied**, server id 82 |
| 5 | create | `tickets.create` → `F4RUN2-Ticket-E` | yerel id `-18` | **applied**, server id 32 |
| 6 | update | `contacts.update(39, {position:'F4RUN2-pos-v1'})` | ok | (7 ile birleşti) |
| 7 | update | `contacts.update(39, {position:'F4RUN2-pos-v2'})` | ok | **applied** — sunucuda `"F4RUN2-pos-v2"` |
| 8 | update | `companies.update(13, {notes:…})` | ok | **conflict** `FIELD_CONFLICT` `["notes"]` ← kasıtlı |
| 9 | update | `tickets.update(12, {subject:…})` | ok | **conflict** `FIELD_CONFLICT` `["subject"]` ← kasıtlı |
| 10 | update | `leads.update(37, {notes:'F4RUN2-lead37-notes'})` | ok | **applied** |
| 11 | update | `tasks.update(45, {title:'F4RUN2-task45-renamed'})` | ok | **applied** |
| 12 | move | `boardApi.moveDealRequest(26, {to_stage_id:4,…})` | yerel aşama → 4 | **applied**, sunucu `deal 26 stage=4 version=2` |
| 13 | move | `boardApi.moveDealRequest(32, {to_stage_id:5,…})` | yerel aşama → 5 | **applied**, sunucu `deal 32 stage=5 version=2` |
| 14 | move | `boardApi.moveDealRequest(48, {to_stage_id:2,…})` | yerel aşama → 2 | **applied**, sunucu `deal 48 stage=2 version=2` |
| 15 | message | `chat.sendMessage(10, 'F4RUN2-offline-message-1')` | yerel id `-17` | **applied**, server id 124 |
| 16 | message | `chat.sendMessage(10, 'F4RUN2-offline-message-2')` | yerel id `-18` | **applied**, server id 125 |
| 17 | message | `chat.sendMessage(9, 'F4RUN2-offline-message-3')` | yerel id `-19` | **applied**, server id 126 |
| 18 | complete | `tasks.complete(31, true)` | yerel `completed` | **applied**, `status=completed completed_at=2026-08-31 19:41:29` |
| 19 | complete | `tasks.complete(1, true)` | yerel `completed` | **applied**, `status=completed completed_at=2026-08-31 19:41:29` |
| 20 | delete | `leads.delete(43)` (kendi oluşturduğumuz kayıt) | tombstone | **applied**, `deleted_at=2026-08-31 19:41:29` |

**20 işlem → 19 outbox satırı.** Aradaki fark coalescing'dir (§5).

**Hedef aşamalar neden koşum 1'den farklı?** Koşum 1'de taşımalar reddedilmişti ama yerel
ayna iyimser yazmayı **geri almamıştı** (§8, B6). Koşum 2'nin taşıma hedefleri hem sunucudaki
hem aynadaki mevcut aşamadan farklı seçildi, böylece "uygulandı" iddiası her iki tarafta da
belirsizliğe yer bırakmadan doğrulanabildi.

### 4.2 Koşum 1 — tablo (tarihçe)

Koşum 1 aynı 20 işlemi `F4TEST-` önekiyle koştu (396 ms). Yerelde 20/20 başarılı, outbox 19
satır. Sunucu sonuçları:

| Sonuç | Adet | Hangi işlemler |
|---|---|---|
| `applied` | 11 | 1, 2, 3, 5, 6+7, 10, 11, 15, 16, 17, 20 |
| `conflict` (`FIELD_CONFLICT`) | 2 | 8, 9 — **kasıtlı olanlar** |
| `rejected` (`INVALID_MUTATION`) | 6 | 4 (testçi hatası, §8 T1) + 12, 13, 14, 18, 19 (**ürün hatası**, §8 B1) |

Koşum 1'in 4 numaralı işlemi `priority: 'medium'` göndermişti; ne sunucu
(`Rule::in(['low','normal','high','urgent'])`) ne de frontend `TASK_PRIORITIES` bu değeri
tanır. Koşum 2 aynı işlemi `priority: 'normal'` ile koştu ve **uygulandı**.

### 4.3 Çevrimdışı UI kanıtı (koşum 2)

```
bar: "Offline · 19 pending changes · Last synced 3 minutes ago"
     refreshDisabled=true   refreshTitle="No internet connection."
```

Bekleyen kayıtlar paneli 18 satır listeler (19'uncu, silinmiş `lead 43`'tür; tombstone
satırları liste sorgularından düşer):

```
company  F4RUN2-Company-A                     Pending
company  Doğu Kimya Sanayi A.Ş.               Pending
contact  f4run2-b@example.invalid             Pending
contact  onur.gunes39@firma14.com.tr          Pending      ← 6+7 TEK satır
lead     F4RUN2 Lead-C                        Pending
lead     ibrahim.cetin3@firma3.com.tr         Pending
deal     Yazılım Geliştirme Projesi — Anadolu Pending
deal     Eğitim Paketi — Safir                Pending
deal     Yıllık Lisans Yenileme — Trakya      Pending
task ×4, ticket ×2, message ×3                Pending
```

Ekran görüntüleri: `f4-shots-run2/03-offline-after-20-ops.png`,
`f4-shots-run2/05-offline-pending-panel.png`.

### 4.4 O41 — Kanban kartındaki `pending` rozeti

Çevrimdışı taşınan üç kart, sunucuya ulaşana kadar başlıklarının yanında sarı bir nokta
rozeti taşır. DOM'dan okunan hâli (rozet `compact`, yani metin yerine `aria-label`):

```
[{"ariaLabel":"Pending","title":"Pending","near":"Eğitim Paketi — Safir"},
 {"ariaLabel":"Pending","title":"Pending","near":"Yazılım Geliştirme Projesi — Anadolu"},
 {"ariaLabel":"Pending","title":"Pending","near":"Yıllık Lisans Yenileme — Trakya"}]
```

`deals.board({})` yanıtındaki `sync_state` alanı da aynı üçünü işaretler, kalan 15 kart
`synced`'dir:

```
["48:pending@stage2","26:pending@stage4","32:pending@stage5",
 "29:synced@stage4","25:synced@stage5", … ]
```

Senkron sonrası rozetler **tamamen kaybolur** (`badges left on board: []`).

Ekran görüntüleri: `f4-shots-run2/04-offline-kanban-pending-badge.png` (rozetli),
`f4-shots-run2/10-final-state.png` (rozetsiz).

### 4.5 O43 — birleşik arama, kaynak etiketli

Komut paletinde `F4TEST` araması iki dizinden gelen sonuçları **tek listede** ve kaynak
etiketiyle gösterir:

```
LEADS      F4TEST Lead-C                     LOCAL
CONTACTS   F4TEST Contact-B                  LOCAL
           Onur Güneş / Yıldız Perakende…    SERVER
COMPANIES  F4TEST-Company-A                  LOCAL
TICKETS    F4TEST-Ticket-E                   LOCAL
           F4TEST-local-subject-ticket12     LOCAL
```

Ekran görüntüsü: `f4-shots-run2/S1-search-sources-online.png`.

---

## 5. Sıra ve coalescing

İki bağımsız kanıt vardır.

**(a) Outbox katlanması.** 6 ve 7 numaralı işlemler aynı satırın (`contact 39`) aynı
alanını arka arkaya değiştirir. `syncra_sync::outbox` §5.4 kuralı gereği ardışık
`update`'ler tek satıra katlanır (`changed_fields` birleşir, son değer kazanır,
`base_sync_version` **ilk** düzenlemeden korunur). Sonuç: 20 işlem → 19 outbox satırı,
UI'da tek "Pending" rozeti (§7.2 sözleşmesi).

**(b) Wire üzerindeki `seq` sırası.** Push gövdesi `:8010`'a konan kayıt eden ters-vekil ile
aynen yakalandı (`scratchpad/run2/proxy-sync.log`). Koşum 2'de 19 mutasyonun `seq` alanları
1..19'dur ve **boşluk yoktur** — yani coalescing bir `seq` yutmamış, iki işlem tek `seq`'e
katlanmıştır:

```json
{"seq":6,"op":"update","entity":"contact","server_id":39,
 "changed_fields":["position"],"occurred_at":"2026-08-31T19:38:28.608Z"}
```

`occurred_at` **ilk** düzenlemenin (op 6, `.608`) zamanıdır; op 7 hemen ardından koşmuştu.
Sunucudaki son değer `"F4RUN2-pos-v2"` — yani payload son düzenlemeden, zaman ilkinden.
Sıra doğru.

Mutasyonlar wire'a `SYNCDESKTOP.md` §5.4 topolojik sırasında dizilir (create'ler
kendi entity'lerinin action/update'lerinden önce); `seq` değerleri gövde içinde artan
sırada değil, **topolojik grup** sırasındadır ve sunucu `seq`'e göre değil dizilim
sırasına göre uygular. Koşum 2'nin gövde sırası:

```
seq  1 create company | seq  7 update company#13 | seq  2 create contact
seq  3 create lead    | seq  6 update contact#39 | seq  9 update lead#37
seq 19 delete lead#43 | seq  4 create task       | seq  5 create ticket
seq 14,15,16 create message | seq 8 update ticket#12 | seq 10 update task#45
seq 11,12,13 action deal move | seq 17,18 action task complete
```

`deal.move` mutasyonunun gövdesi de burada doğrulanır:

```json
{"seq":11,"op":"action","entity":"deal","server_id":26,"action":"move",
 "payload":{"after_deal_id":null,"before_deal_id":null,
            "pipeline_stage_id":4,"to_stage_id":4,"version":1}}
```

→ `action` **çıplak fiildir** (`"move"`, `"deal.move"` değil) ve koşum 2'de **uygulandı**.
Koşum 1'de aynı gövde reddediliyordu; sebebi payload değil, sunucunun beyaz listesiydi
(§8, B1).

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
  -d '{"notes":"F4RUN2-SERVER-notes-company13"}'          # → 200

curl -X PATCH http://127.0.0.1:8011/api/tickets/12 \
  -H "Authorization: Bearer <device-token>" -H 'Content-Type: application/json' \
  -d '{"subject":"F4RUN2-SERVER-subject-ticket12"}'       # → 200
```

Bearer belirteci `POST /api/auth/device` ile alınır. Gövde **beş** alan ister:
`email`, `password`, `device_fingerprint` (**64 karakterlik hex**, kısa değer 422),
`platform` ve `app_version`. Son ikisi unutulursa yanıt:
`{"fields":{"platform":["platform alanı zorunludur."],"app_version":[…]}}`.

Doğrulama — sürüm ilerledi ve doğru alanlar denetim kaydına düştü:

```
company13 sync_version=1049  notes=F4RUN2-SERVER-notes-company13
ticket12  sync_version=1050  subject=F4RUN2-SERVER-subject-ticket12
AL 105 2026-08-31 19:40:23 Company#13 {"attributes":{"notes":"F4RUN2-SERVER-notes-company13"},…}
AL 106 2026-08-31 19:40:23 Ticket#12  {"attributes":{"subject":"F4RUN2-SERVER-subject-ticket12"},…}
```

Yerel `occurred_at` 19:38:28 < sunucu düzenlemesi 19:40:23 → koşul sağlandı.

### 6.2 Inbox'ta göründü mü

Evet, ve koşum 2'de **yalnızca bu ikisi** — tek yönlü red yok. Push yanıtı (gerçek):

```json
{"seq":7,"status":"conflict","code":"FIELD_CONFLICT","conflicting_fields":["notes"],
 "server_row":{…"notes":"F4RUN2-SERVER-notes-company13","sync_version":1049…}}
{"seq":8,"status":"conflict","code":"FIELD_CONFLICT","conflicting_fields":["subject"],
 "server_row":{…"subject":"F4RUN2-SERVER-subject-ticket12","sync_version":1050…}}
```

Çakışma Kutusu (`ConflictInbox.tsx`, `docs/DESKTOP-ARCHITECTURE.md` EK 3 / A22):

```
● 2   This record has changes that conflict with the server.
      Doğu Kimya Sanayi A.Ş.          Company · 93eabcef-…   Merge | Keep mine | Take server's
      F4RUN2-local-subject-ticket12   Ticket  · 2c8718e8-…   Merge | Keep mine | Take server's
```

O48'in **kayıt adı** yarısı burada görünür: satırlar artık çıplak `client_id` UUID'si değil,
kaydın adını ve altında `<Varlık> · <uuid>` çiftini yazıyor. (O48'in **metin** yarısı
çalışmıyor — §8, B7.)

Ekran görüntüsü: `f4-shots-run2/07-conflict-inbox.png`.

### 6.3 Çözüm ve çözüm sonrası tutarlılık

Çözüm **gerçek UI düğmeleriyle** yapıldı:

* `company 13` → **Take server's**
* `ticket 12` → **Keep mine**

`Keep mine` mutasyonu yeniden kuyruğa alır (`pending: 0 → 1`); bir senkron turu daha
gerekir. Koşum 2'de bu tur da **kendiliğinden** koştu (§7.3).

Sonuç — yerel ayna ve sunucu **aynı şeyi söylüyor**:

| Kayıt | Çözüm | Yerel ayna | Sunucu (`:8011` REST) |
|---|---|---|---|
| company 13 `notes` | Take server's | `F4RUN2-SERVER-notes-company13` | `F4RUN2-SERVER-notes-company13` |
| ticket 12 `subject` | Keep mine | `F4RUN2-local-subject-ticket12` | `F4RUN2-local-subject-ticket12` |

Her iki kaydın `sync_state`'i de `synced`. Kanban panosu da hizalandı: aynadaki aşamalar
(`26@4, 32@5, 48@2`) sunucudakilerle birebir aynı ve hiçbir kartta rozet kalmadı.

Ekran görüntüsü: `f4-shots-run2/08-after-resolution.png`.

---

## 7. Ağın açılması ve ölçüm

### 7.1 KOŞUM 2 — kendiliğinden toparlanma: **VAR** ✅

Ağ 19:41:13.709Z'de geri geldi (vekil `:8010`'da dinlemeye başladı). **Uygulamaya
dokunulmadı, yeniden başlatılmadı, hiçbir düğmeye basılmadı.**

| An | t+ | Ne oldu |
|---|---|---|
| 19:41:13.709Z | 0.000 s | Ağ döndü (vekil dinlemede) |
| 19:41:28.786Z | **15.077 s** | **Motorun kendi probe'u ağı keşfetti** — `GET /api/sync/manifest` (vekil günlüğündeki İLK istek) |
| 19:41:29.063Z | 15.354 s | `POST /api/sync/push` — 19 mutasyon, tek batch, HTTP 200 |
| 19:41:29.257Z | 15.548 s | `status.online` → `true` |
| 19:41:29.563Z | 15.854 s | `POST /api/sync/pull` |
| 19:41:30.018Z | **16.309 s** | **`status.pending` → 0** |

**≤60 sn kriteri: 16.3 sn ile karşılandı.** Vekil günlüğündeki ilk istek motorun probe'u
olduğu için, ölçümün kendisinin senkronu tetiklemediği de kanıtlanmış olur.

**15 saniye nereden geliyor?** Probe ramp'inin tavanından. Ağ döndüğünde ramp çoktan
30 sn'lik tavana oturmuştu; son başarısız probe 19:40:58'de koşmuştu, sıradaki 19:41:28'de
koşacaktı. Ağ o iki probe'un **arasında** döndüğü için motor kalan 15 sn'yi bekledi. Yani
kötü durum = **30 sn tavan + ~1.2 sn tur ≈ 31 sn**, 60 sn bütçesinin yarısı. Ölçülen 15.1 sn
bu aralıktan rastgele bir örnektir.

### 7.2 KOŞUM 2 — push sonucu: 19 mutasyon, tek batch, HTTP 200

| Sonuç | Adet | Hangi işlemler |
|---|---|---|
| `applied` | **17** | 1, 2, 3, 4, 5, 6+7, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 |
| `conflict` (`FIELD_CONFLICT`) | 2 | 8, 9 — **kasıtlı olanlar** |
| `rejected` | **0** | — |

Sunucu tarafı bağımsız doğrulama (`php artisan tinker`, salt okunur):

```
--- creates ---
company 27 => F4RUN2-Company-A
contact 62 => F4RUN2 Contact-B
lead 44    => F4RUN2 Lead-C
task 82    => F4RUN2-Task-D  priority=normal
ticket 32  => F4RUN2-Ticket-E
--- updates ---
contact39.position = F4RUN2-pos-v2               ← sıra doğru
company13.notes    = F4RUN2-SERVER-notes-company13
ticket12.subject   = F4RUN2-local-subject-ticket12
lead37.notes       = F4RUN2-lead37-notes
task45.title       = F4RUN2-task45-renamed
--- moves ---
deal 26 stage=4 version=2                        ← UYGULANDI (koşum 1'de reddediliyordu)
deal 32 stage=5 version=2                        ← UYGULANDI
deal 48 stage=2 version=2                        ← UYGULANDI
--- messages ---
msg 124 conv10 F4RUN2-offline-message-1
msg 125 conv10 F4RUN2-offline-message-2
msg 126 conv9  F4RUN2-offline-message-3
--- task completes ---
task 31 status=completed completed_at='2026-08-31 19:41:29'   ← UYGULANDI
task 1  status=completed completed_at='2026-08-31 19:41:29'   ← UYGULANDI
--- delete ---
lead43 F4RUN2-SEED deleted_at='2026-08-31 19:41:29'
```

### 7.3 KOŞUM 2 — ikinci, bağımsız toparlanma gözlemi

`Keep mine` çözümü 1 mutasyonu yeniden kuyruğa aldığında vekil çoktan kapanmıştı, yani
motor tekrar çevrimdışıydı ve ramp baştan başlamıştı (günlükte 19:42:35/38/42/48/58). Vekil
`19:43:15.595Z`'de geri geldi; motor `19:43:16.68`'de **hem çevrimiçi hem kuyruğu boş**
durumdaydı — **~1.1 sn**. Ramp o noktada henüz düşük aralıktaydı, bu yüzden keşif neredeyse
anında oldu. İki gözlem birlikte ramp'in her iki ucunu da kapsar.

### 7.4 KOŞUM 1 (tarihçe) — kendiliğinden toparlanma: **YOKTU**

Ağ 18:37:48.863Z'de geri gelmişti. Sonraki **79 saniye** boyunca 5 saniyede bir yoklandı:

```
t+ 19s online=false pending=19 | Offline · 19 pending changes | refreshDisabled=true
…
t+ 79s online=false pending=19 | Offline · 19 pending changes | refreshDisabled=true
```

Vekilin gördüğü senkron isteği sayısı: **0**. `sync_now` elle çağrıldığında da reddediyordu.
Tek çıkış yolu uygulamayı yeniden başlatmaktı; başlatıldığında 19 mutasyon 4.8 saniyede
sunucuya ulaşıyordu — yani sorun sürede değil, **eylemin var olmamasındaydı**. Sebebi §8,
B2'de.

---

## 8. Bulgular

### 8.1 Koşum 1'in bulguları ve kapanışları

#### B1 — `op=action` mutasyonlarının hiçbiri sunucuda uygulanamıyor (KRİTİK) — ✅ KAPANDI (O45)

**Bulgu (koşum 1).** Rust istemcisi `entity` ve `action` alanlarını **ayrı** gönderir
(`{"op":"action","entity":"deal","action":"move"}`), sunucu ise
`MutationApplier::applyAction()` içinde **çıplak** `$mutation->action` değerini **noktalı**
bir listeyle karşılaştırıyordu:

```php
public const ALLOWED_ACTIONS = ['deal.move', 'deal.assign', 'task.complete', …];
if (! in_array($action, self::ALLOWED_ACTIONS, true)) { … 'Action is not whitelisted: '.$action }
```

`"move" !== "deal.move"` → **her çevrimdışı action reddediliyordu.** Tek bir fiil değil,
`ACTION_WHITELIST`'in tamamı (12 fiil, `notification.read_all` dahil).

Kök neden aynı kayıt üzerinde iki push isteğiyle kanıtlanmıştı:

```
action="complete"       → rejected  INVALID_MUTATION  "Action is not whitelisted: complete"
action="task.complete"  → rejected  INVALID_MUTATION  "tamamlanma durumu alanı zorunludur."
```

Hangi taraf haklıydı? `docs/DESKTOP-SYNC-PROTOCOL.md` KARAR P10'un kendi wire örneği
**çıplak** action kullanır (`{"entity":"notification","action":"read_all"}`). Yani protokol
sözleşmesine göre istemci doğru, sunucunun listesi yanlıştı. Backend testleri mutasyonları
`'action' => 'deal.move'` diye kurduğu için yeşil kalıyordu; Rust crate testleri de kendi
tarafında yeşildi. **Hata tam olarak iki yeşil takımın arasında yaşıyordu — F4'ün var oluş
sebebi budur.**

**Kapanış.** `MutationApplier::applyAction()` artık `entity.action` birleşik anahtarını
**kendisi kuruyor**; çıplak fiil kabul, entity ile nitelenmiş fiil ise açıkça reddediliyor
("action must be the bare verb, not entity-qualified"). Beyaz listeler okunabilirlik için
`entity.action` çiftleri olarak kalıyor. **Koşum 2 doğrulaması:** `deal.move` ×3 ve
`task.complete` ×2 mutasyonlarının **tamamı applied**; wire'daki `action` alanı `"move"` /
`"complete"` (§5).

#### B2 — Ağ geri geldiğinde uygulama kendiliğinden çevrimiçi olamıyor (KRİTİK) — ✅ KAPANDI (O46)

**Bulgu (koşum 1).** Üç şey üst üste biniyordu:

1. `SyncEngine::sync_now()` ilk satırında `if !self.status().online { return Err(Offline) }`
   der — senkron denemesi **çevrimdışıyken hiç ağa çıkmaz**, dolayısıyla bağlantının
   döndüğünü kendi başına keşfedemez.
2. `online` bayrağını `true` yapan tek yollar `login()`, `restore_session()` ve
   `SyncEngine::set_online(true)`'du. Sonuncusunu çağıran tek şey arka plan döngüsüydü.
3. Arka plan döngüsü **hiç başlatılmıyordu** — `start_background_sync` F5 kapsamına
   bırakılmıştı. `ConnectivityBar`'ın senkron düğmesi de `syncDisabled = !status.online`
   ile çevrimdışıyken devre dışıydı.

Sonuç: uygulama bir kez çevrimdışına düştüğünde kullanıcının elinde **yeniden başlatmaktan
başka yol yoktu**. 79 saniyelik gözlem ve boş vekil günlüğü bunu kanıtlıyordu.

**Kapanış.** İki parça:

* `start_background_sync` artık `lib.rs` `.setup()` içinde başlıyor ve handle managed
  state'te (`AppState::scheduler`) tutuluyor — `SYNCDESKTOP.md` §5.5'in tetikleyici
  listesi zaten **"open"** ile başlıyordu.
* Döngünün çevrimdışı dalında artık gerçek bir `probe_online()` var:
  `GET /api/sync/manifest`, `force = true` (on dakikalık manifest önbelleği aksi hâlde
  ağsız makinede "çevrimiçi" derdi), ramp `1, 2, 4, 8, 16, 30, 30 …` sn, tavan **30 sn**
  (`backoff::MAX_SECS` olan 300 sn değil — tavan burada keşif gecikmesidir ve 60 sn
  bütçesinin içinde kalmak zorundadır).

**Koşum 2 doğrulaması:** ağ döndükten 15.077 sn sonra probe keşfetti, 16.309 sn'de kuyruk
boşaldı; ne yeniden başlatma ne de tek bir tıklama gerekti (§7.1). Ramp günlükte
gözlemlenebilir (§3).

#### B3 — `task.complete` payload'ı eksik gidiyor — ✅ KAPANDI (O47)

`desktop/src/platform/data/work.ts` → `complete()` `runAction('task', id, 'complete')`
çağırıyordu, yani `payload: {}`. Sunucunun `CompleteTaskRequest`'i ise
`'completed' => ['required','boolean']` ister; B1 düzeltilse bile bu mutasyon
`INVALID_MUTATION` alacaktı. Artık `runAction('task', id, 'complete', { completed: true })`.
**Koşum 2 doğrulaması:** 18 ve 19 numaralı işlemler applied, `task 1` ve `task 31`
sunucuda `status=completed`.

#### B4 — `INVALID_MUTATION` için i18n anahtarı yok — ⚠️ YARIM KAPANDI (O48)

Koşum 1'de Çakışma Kutusu 6 reddi **"An unknown error occurred."** başlığıyla gösteriyor ve
satırlarda kayıt adı yerine `client_id` UUID'si yazıyordu.

O48 iki şey yapmayı hedefledi. **Kayıt adı yarısı çalışıyor** (§6.2): `conflictDisplayName()`
mutasyon payload'ından `title`/`name`/`subject`/`company_name`/`first_name+last_name`
alanlarını okuyor, bulamazsa çevrilmiş varlık tipine düşüyor. **Metin yarısı çalışmıyor** —
ayrıntı B7'de.

#### B5 — Bootstrap ilerleme sayısı ile saklanan satır sayısı tutmuyor — açık

Bootstrap ekranı "Company: 22 records" der; `retention_days=30` sonrası aynada 1 firma
kalır (sunucuda son 30 günde güncellenmiş firma sayısı da 1). Sayı yanıltıcı; veri kaybı
değil, gösterim sorunu. Koşum 2 bu davranışı değiştirmedi.

#### T1 — (testçi hatası, ürün hatası değil) geçersiz `priority` — ✅ giderildi

Koşum 1'in 4 numaralı işlemi `priority: 'medium'` gönderiyordu; geçerli değerler
`low|normal|high|urgent`. Koşum 2 `priority: 'normal'` ile koştu ve **applied** (server id
82). **5/5 create çevrimdışı çalışıyor.**

### 8.2 Koşum 2'nin yeni bulguları

#### B7 — `INVALID_MUTATION` hâlâ "An unknown error occurred." olarak gösteriliyor (YENİ)

O48 `INVALID_MUTATION` anahtarını **dört dilin dördüne birden** ekledi
(`frontend/src/i18n/locales/{tr,en,de,fr}/desktop.json` → `errors.INVALID_MUTATION`), ama
`desktop/src/ui/errors.ts` içindeki `KNOWN_ERROR_CODES` kümesine eklemedi. `errorMessage()`
bir kodu ancak o kümede bulursa çeviriyor, aksi hâlde `desktop:errors.unknown`'a düşüyor:

```ts
if (KNOWN_ERROR_CODES.has(code)) return t(`desktop:errors.${code}`)
return t('desktop:errors.unknown')
```

Yani sözlükteki cümle yazıldı ama hiçbir zaman okunmuyor.

**Gerçek kanıt.** Kasıtlı olarak geçersiz `priority` ile bir görev oluşturuldu
(`F4RUN2-RejectProbe-O48`); sunucu `INVALID_MUTATION` ile reddetti ve Çakışma Kutusu şunu
gösterdi:

```
● 1   An unknown error occurred.          ← olması gereken: "Bu değişiklik sunucu tarafından reddedildi."
      F4RUN2-RejectProbe-O48              ← O48'in kayıt adı yarısı ÇALIŞIYOR
      Task · a1d9ae63-35fa-47b8-abc1-b02f5d5b00cb
```

Ekran görüntüsü: `f4-shots-run2/09-o48-invalid-mutation.png`.

Dosyanın kendi başlık yorumu bu riski zaten adlandırıyor — küme "transcribed, not derived …
so drift has to fail loudly here rather than silently there" diyor — ama sözlük ile küme
arasındaki tutarlılığı **denetleyen hiçbir şey yok**, bu yüzden sürüklenme sessizce oldu.

> Bu bulgu F4 kabul kriterlerinden birini düşürmez (koşum 2'de senaryonun kendi işlemlerinin
> hiçbiri reddedilmedi); ama B4 "kapandı" sayılamaz.

#### B6 — Reddedilen bir action'ın iyimser yerel yazması geri alınmıyor (YENİ, düşük)

Koşum 1'de üç `deal.move` reddedilmiş, ama yerel ayna kartları taşınmış hâlde bırakmıştı.
Redler `TakeServer` ile atıldıktan **ve tam bir senkron turu koştuktan sonra bile** ayna
sunucudan farklıydı:

```
sunucu : deal 26 stage=1   deal 32 stage=2   deal 48 stage=3
ayna   : deal 26 stage=2   deal 32 stage=3   deal 48 stage=5
```

Sebebi anlaşılır: tek yönlü bir redde `theirs` `null`'dur, yani `TakeServer`'ın yazacak bir
sunucu satırı yoktur; sunucu satırı da değişmediği için sonraki `pull` onu göndermez.
Kayıt kaybı yok — sunucu doğru — ama ayna, kullanıcının geri alındığını sandığı bir
değişikliği göstermeye devam ediyor. Koşum 2'de taşımalar uygulandığı için ayna
kendiliğinden hizalandı.

#### B8 — Açık panelde çakışma listesi tazelenmiyor (YENİ, düşük)

Çakışma Kutusu açıkken bir senkron turu yeni bir çakışma üretirse, sekmedeki rozet **1**
olur ama liste gövdesi "No conflicts / There are no conflicts to resolve right now." demeye
devam eder. Paneli kapatıp açmak listeyi düzeltiyor. Rozet `status` olayından besleniyor,
liste ise tazelenmeyen bir sorgudan.

#### G1 — `sync_now` komutunun doküman yorumu artık yanlış (gözlem)

`desktop/src-tauri/src/commands/sync.rs`, `sync_now`'ın üstünde hâlâ şunu yazıyor:
"The background loop … is not started by this shell yet — F5 wires the OS-integration
triggers; this turn only exposes the manual path." O46 döngüyü `.setup()`'a taşıdı, yani
cümle artık doğru değil.

#### G2 — Hiç senkronlanmamış bir kaydı silmek `UNRESOLVED_REFERENCE` üretiyor (gözlem)

B7 probe'unun temizliğinde, sunucuya hiç ulaşmamış yerel görev silindiğinde silme mutasyonu
kuyruğa girdi ve sunucuda karşılığı olmadığı için `UNRESOLVED_REFERENCE` ile geri döndü.
Sadece yerelde var olan bir kaydın silinmesinin sunucuya gitmesi gerekmiyor olabilir; bu
F4 kapsamı dışında, not olarak bırakılıyor.

### 8.3 Kabul kriterleri

| Kriter | Koşum 1 | Koşum 2 |
|---|---|---|
| 20 işlem çevrimdışı yapılabiliyor | ✅ 20/20, outbox 19 satır | ✅ 20/20, outbox 19 satır, 205 ms |
| ≤60 sn içinde sunucuda | ❌ kendiliğinden hiç olmadı (B2) | ✅ **16.3 sn** — probe t+15.077 s'de keşfetti, yeniden başlatma/tıklama yok |
| Tüm mutasyonlar sunucuda | ❌ 19'un 11'i | ✅ 19'un **17'si applied**, 2'si kasıtlı çakışma, **0 red** |
| Sıra doğru / coalescing beklendiği gibi | ✅ | ✅ 6+7 tek satıra katlandı, `occurred_at` ilkinden / payload sonuncudan, sunucuda `pos-v2`, UI'da tek rozet |
| 2 kasıtlı çakışma inbox'ta | ✅ | ✅ ikisi de `FIELD_CONFLICT`, doğru `conflicting_fields`, başka hiçbir giriş yok |
| Çözüm sonrası tutarlı | ✅ | ✅ TakeServer ve KeepMine sonrası ayna = sunucu, `sync_state=synced`, board hizalı |
| Reddedilen mutasyon kalmadı | ❌ 6 red | ✅ **0** |

**Koşum 2 F4 kabul kapısını geçmiştir.**

---

## 9. Ekran görüntüleri

Kök: `scratchpad/f4-shots-run2/` (koşum 2). Koşum 1'inkiler `scratchpad/f4-shots/`.

| Dosya | Ne gösteriyor |
|---|---|
| `01-baseline-online.png` | Temiz taban, çevrimiçi |
| `S1-search-sources-online.png` | **O43** — birleşik arama, `LOCAL` / `SERVER` etiketleri aynı listede |
| `02-offline-bar.png` | `:8010` durdurulduktan sonra "Offline", yenile düğmesi soluk |
| `03-offline-after-20-ops.png` | 20 işlem sonrası uygulama |
| `04-offline-kanban-pending-badge.png` | **O41** — taşınan üç kartta sarı `pending` noktası, çubuk "Offline · 19 pending changes" |
| `05-offline-pending-panel.png` | "19 pending changes" paneli, kayıt kayıt (contact 39 **tek** satır) |
| `06-after-auto-recovery.png` | Kendiliğinden senkron sonrası — "Conflict detected", pending 0 |
| `07-conflict-inbox.png` | Çakışma Kutusu: yalnızca 2 gerçek çakışma, kayıt adlarıyla |
| `08-after-resolution.png` | İki çakışma çözüldükten sonra |
| `09-o48-invalid-mutation.png` | **B7 kanıtı** — kayıt adı doğru, başlık hâlâ "An unknown error occurred." |
| `10-final-state.png` | Koşum sonu: "Online", rozet yok, board sunucuyla hizalı |

Ham kanıtlar (koşum 2): `scratchpad/run2/ops-log.json` (20 işlem, zaman damgalı),
`scratchpad/run2/proxy-sync.log` (her `/api/sync/*` isteği ve yanıtı, `atMs` alanı T0'a
göreli), `scratchpad/run2/measure.json` (T0, probe/online/drained anları),
`scratchpad/run2/tauri-dev.log` (probe ramp'inin kendisi).

---

## 10. Başarısızlık durumunda nereye bakılır

| Belirti | Bakılacak yer |
|---|---|
| `tauri dev` "beforeDevCommand terminated" | 1420 portunda başka bir vite var (`strictPort`) |
| Uygulama açılıyor ama her `invoke()` patlıyor | CSP'de `ipc: http://ipc.localhost` eksik → `scripts/tauri.mjs` |
| Motor doğru sunucuya gitmiyor | `SYNCRA_API_URL` **derleme zamanı** (`option_env!`); crate yeniden derlendi mi? Log'da `Compiling syncra-desktop` görünmeli |
| Webview API'ye çıkamıyor | `VITE_API_URL` ile CSP `connect-src` aynı origin'i göstermeli — `.tauri/tauri.conf.generated.json` |
| `sync_now` → `OFFLINE` ama sunucu ayakta | En fazla **30 sn** bekleyin: probe ramp'inin tavanı budur. Daha uzun sürüyorsa `tauri-dev.log`'da `connectivity probe failed` satırları var mı diye bakın; hiç yoksa arka plan döngüsü başlamamıştır (B2'nin nüksü) |
| `resolve_conflict` → `expected adjacently tagged enum Resolution` | `choice` bir nesnedir: `{ "kind": "take_server" }` |
| `POST /api/auth/device` 422 | `device_fingerprint` **64 hex karakter**; ayrıca `platform` ve `app_version` zorunlu |
| Push 422 `batch id alanı zorunludur` | Elle push atarken gövdeye `batch_id` (UUID) eklenmeli |
| Beklenen çakışma oluşmuyor | Sunucu düzenlemesi istemcinin `occurred_at`'inden **sonra** ve **aynı alanda** olmalı; `activity_log` tutmayan varlıklarda (conversation/message/notification) kayıt seviyesine düşer |
| `INVALID_MUTATION: Action is not whitelisted` | B1 kapandı; yine görüyorsanız `action` alanı **çıplak fiil** mi (`move`, `deal.move` değil) diye bakın |
| Çakışma Kutusu "An unknown error occurred." | B7 — kod `desktop/src/ui/errors.ts` → `KNOWN_ERROR_CODES` kümesinde yok |
| Sekme rozeti N diyor ama liste "No conflicts" | B8 — paneli kapatıp açın |
| Aynada beklenen satır yok | `retention_days` (varsayılan 30) penceresi dışında kalmış olabilir; `storage_settings` ile bakılır |
| Ayna ile sunucu bir kayıtta ayrışmış | B6 — reddedilmiş bir action'ın iyimser yazması geri alınmamış olabilir |

---

## 11. Temizlik

```powershell
# :8010 / :8011 ve varsa vekil
Get-NetTCPConnection -LocalPort 8010,8011 -State Listen |
  ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }
# tauri dev + cargo watcher + vite (1420)
```

Geliştiricinin `:8000` backend'ine, `:8080` Reverb'üne ve `:5173` web vite'ına
**dokunulmaz**.

### Koşum 2'nin sunucuda bıraktığı kayıtlar (silme kararı teknik lidere aittir)

| Varlık | id | Ad / durum |
|---|---|---|
| company | 27 | `F4RUN2-Company-A` |
| contact | 62 | `F4RUN2 Contact-B` |
| lead | 44 | `F4RUN2 Lead-C` |
| lead | 43 | `F4RUN2-SEED DeleteMe` — **soft-deleted** (senaryonun `delete` işlemi; koşum 2'nin kendi oluşturduğu kayıt) |
| ticket | 32 | `F4RUN2-Ticket-E` |
| task | 82 | `F4RUN2-Task-D` |
| message | 124, 125, 126 | `F4RUN2-offline-message-1..3` (conv 10, 10, 9) |
| personal_access_token | 18 | `F4RUN2-probe` (curl için cihaz belirteci) |

Mevcut kayıtlarda bırakılan değişiklikler (geri alınmadı):

| Kayıt | Alan | Yeni değer |
|---|---|---|
| company 13 | `notes` | `F4RUN2-SERVER-notes-company13` |
| contact 39 | `position` | `F4RUN2-pos-v2` |
| lead 37 | `notes` | `F4RUN2-lead37-notes` |
| task 45 | `title` | `F4RUN2-task45-renamed` |
| ticket 12 | `subject` | `F4RUN2-local-subject-ticket12` |
| deal 26 | `pipeline_stage_id` | 1 → **4** (`version` 1 → 2) |
| deal 32 | `pipeline_stage_id` | 2 → **5** (`version` 1 → 2) |
| deal 48 | `pipeline_stage_id` | 3 → **2** (`version` 1 → 2) |
| task 1 | `status` / `completed_at` | `completed` / `2026-08-31 19:41:29` |
| task 31 | `status` / `completed_at` | `completed` / `2026-08-31 19:41:29` |

Koşum 1'den kalanlar (`F4TEST-` önekli: company 26, contact 61, lead 41/42, ticket 31,
task 81, message 121–123, pat 16) hâlâ duruyor; koşum 2 bunlara dokunmadı.

**Yerel ayna koşum sonunda temiz:** `pending=0, conflicts=0, outbox_count=0`.
