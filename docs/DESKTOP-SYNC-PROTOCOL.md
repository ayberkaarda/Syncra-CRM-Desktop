# DESKTOP SYNC PROTOCOL — Bağlayıcı Sözleşme

> **Statü:** F0 çıktısı. **Tüm açık kararlar karara bağlandı (§8); 13 şartname düzeltmesinin tamamı kabul edildi (§9); öneriler ve riskler sonuçlandırıldı (§10).** Bu belge `SYNCDESKTOP.md` §4 (backend) ve §5 (`syncra-sync` crate) bölümlerini **gerçek sınıf/dosya adlarıyla ve F0'da doğrulanmış olgularla** somutlaştırır. W1 (F1 ∥ F2 ∥ F3a) için wire sözleşmesi **DONMUŞTUR** — §8.1'deki maddeler teknik liderin açık talimatı olmadan değiştirilmez.
>
> **Öncelik:** `SYNCDESKTOP.md` bağlayıcıdır. Bu belge onu **somutlaştırır**; çeliştiği yerlerde §12'de gerekçesiyle işaretlenmiştir ve o maddeler onaya tabidir.
>
> **Kapsam dışı:** repo yerleşimi, platform adaptörü, Vite/Tauri yapılandırması, realtime kurulumu, i18n, online-only UI davranışı → `docs/DESKTOP-ARCHITECTURE.md`.
>
> **Doğrulama yöntemi:** Bu belgedeki her olgu `dosya:satır` ile doğrulanmıştır. Trigger davranışı, izole bir MariaDB 10.4.32 örneğinde (ayrı datadir, port 13399, `test_tmp_syncprobe`) deneysel olarak ölçülmüştür; probe kimlikleri (T1–T9, C1–C3, D2, D3, E1) ilgili maddelerde anılır. Doğrulanmamış hiçbir iddia yazılmamıştır; ölçülmeyen şeyler **AÇIK** olarak işaretlidir.

---

## 1. SENKRON KAPSAMI (`SyncableRegistry`)

### 1.1 RW tablolar — entity haritası

| entity | Model | Store/Update Request | Policy | Service / Action | SoftDeletes | `version` | activity_log |
|---|---|---|---|---|---|---|---|
| company | `app/Models/Company.php` | `Http/Requests/Companies/{Store,Update}CompanyRequest.php` | `Policies/CompanyPolicy.php` | `Services/Companies/CompanyService.php` | ✅ | — | ✅ |
| contact | `app/Models/Contact.php` | `Http/Requests/Contacts/{Store,Update}ContactRequest.php` | `Policies/ContactPolicy.php` | `Services/Contacts/ContactService.php` | ✅ | — | ✅ |
| lead | `app/Models/Lead.php` | `Http/Requests/Leads/{Store,Update,Assign,Convert,Import}LeadsRequest.php` | `Policies/LeadPolicy.php` | `Services/Leads/LeadService.php`, `LeadConversionService.php`, `LeadImportService.php`, `DuplicateDetector.php` | ✅ | — | ✅ |
| deal | `app/Models/Deal.php` | `Http/Requests/Deals/{Store,Update,Move,Assign,Board}DealRequest.php` | `Policies/DealPolicy.php` | `Services/Deals/DealService.php`, `DealMoveService.php` | ✅ | **✅** | ✅ |
| task | `app/Models/Task.php` | `Http/Requests/Tasks/{Store,Update,Assign,Complete}TaskRequest.php` | `Policies/TaskPolicy.php` | `Services/Tasks/TaskService.php` | ✅ | — | ✅ |
| activity | `app/Models/Activity.php` | `Http/Requests/Activities/{Store,Update}ActivityRequest.php` | `Policies/ActivityPolicy.php` | `Services/Activities/ActivityService.php` | ✅ | — | ✅ |
| ticket | `app/Models/Ticket.php` | `Http/Requests/Tickets/{Store,Update,Assign,Status}TicketRequest.php` | `Policies/TicketPolicy.php` | `Services/Tickets/TicketService.php`, `TicketStatusMachine.php`, `SlaService.php` | ✅ | — | ✅ |
| quote | `app/Models/Quote.php` | `Http/Requests/Quotes/{Store,Update,Status,Calculate}QuoteRequest.php` | `Policies/QuotePolicy.php` | `Services/Quotes/QuoteService.php`, `QuoteStatusMachine.php`, `QuoteCalculator.php` | ✅ | — (`revision` int ≠ versiyon) | ✅ |
| quote_item | `app/Models/QuoteItem.php` | `Http/Requests/Quotes/QuoteItemRules.php` (quote request'leri içinde) | — (QuotePolicy üzerinden) | QuoteService / QuoteCalculator | **❌ hard delete** | — | ✅ |
| | ⚠️ **§1.5 gereği ayrı sync tablosu DEĞİL** — `quotes` payload'ına `items: [...]` olarak gömülür | | | | | | |
| conversation | `app/Models/Conversation.php` | `Http/Requests/Chat/{StoreConversation,UpdateConversation,StoreMember}Request.php` | `Policies/ConversationPolicy.php` | `Services/Chat/ConversationService.php` | ✅ | — | **❌** |
| message | `app/Models/Message.php` | `Http/Requests/Chat/{Store,Update}MessageRequest.php` | `Policies/MessagePolicy.php` | `Services/Chat/MessageService.php`, `ChatReadState.php`, `TickState.php` | ✅ | — | **❌** |
| notification | **model yok** — `Illuminate\Notifications\DatabaseNotification` | — (controller inline `validate()`) | — | `Observers/Notifications/*.php` → `Notifications/Support/NotificationDispatcher.php` | ❌ | — | ❌ |
| tag | `app/Models/Tag.php` | — (`TagController.php:49` inline) | `Policies/TagPolicy.php` | — (`TagController.php:60` `firstOrCreate`) | **❌** | — | ✅ |
| custom_field_value | `app/Models/CustomFieldValue.php` | — (entity request'leri içinde) | — | entity servisleri, ör. `CompanyService.php:111` | **❌** | — | **❌** |
| conversation_user | **model yok** (pivot) | — | ConversationPolicy üzerinden | `Services/Chat/ChatReadState.php` | ❌ | — | ❌ |
| taggables | **model yok** (pivot), **`id` kolonu ve PK yok** | — | — | repository'lerde `->tags()->sync()` | ❌ | — | ❌ |

**Ortak audit trait:** `app/Support/ActivityLogging/LogsCrmActivity.php` (spatie `LogsActivity` sarmalayıcısı, log adı `crm`).

### 1.2 RO tablolar

`pipeline_stages`, `custom_fields`, `products`, `price_lists`, `price_list_items`, `exchange_rates` (son 7 gün), `saved_views`, `settings` (public), `users` (projeksiyon: `id,name,email,avatar_url,is_active,department` — **başka kolon YASAK**), `permissions` (efektif, manifest içinde).

### 1.3 Hiç senkronlanmayanlar

`activity_log`, `page_visit_logs`, `session_logs`, `sessions`, `personal_access_tokens`, `password_reset_tokens`, `email_templates`, `automation_rules`, `attachments`, `jobs*`, `cache*`.

### 1.4 KARAR P1 — `taggables` kendi `sync_version`'ını ALMAZ

`taggables` **pull tablo setinden çıkarılır** ve `sync_deletions`'a hiç girmez.

**Gerekçe:** (a) Tablonun `id` kolonu ve PRIMARY KEY'i yok — `2026_08_23_200014_create_taggables_table.php:15-20` yalnızca `foreignId('tag_id')`, `morphs('taggable')` ve `taggables_unique` bileşik unique'i tanımlıyor. (b) `SYNCDESKTOP.md` §4.1 zaten "entity ile birlikte payload'da (ayrı mutasyon değil)" diyor ve §4.4 satır payload'ına `tags: [ids]` gömüyor. (c) Tag kaybı, sahip satırın payload'ındaki `tags` dizisinin kısalmasıyla iletilir — ayrı tombstone gereksiz.

Bunun yerine **sahip entity bump'lanır**: `TagSyncService::apply(Model $owner, array $tagIds)` sarmalayıcısı `->tags()->sync()` çağrısından sonra sahibin `sync_version`'ını ilerletir. Yönlendirilecek 7 çağrı noktası: `CompanyRepository.php:206`, `ContactRepository.php:176`, `DealRepository.php:241`, `LeadRepository.php:163`, `TicketRepository.php:128`, `ProductService.php:51`, `ProductService.php:77`. Ayrıca `LeadConversionService.php:376-390` (`moveTaggables()`) **hem `lead`'in hem `contact`'in** versiyonunu bump'lamalıdır.

> **Not (ileride gerekirse):** `taggables` ayrı tablo olarak senkronlanmak zorunda kalırsa tek mümkün `row_key` formatı `tag_id:taggable_type:taggable_id`'dir. `Relation::enforceMorphMap()` projede hiç çağrılmıyor → `taggable_type` tam sınıf adıdır (`App\Models\Contact`); `:` ayırıcısı FQCN'de ve id'de geçemeyeceği için güvenlidir, en kötü uzunluk ~45 karakter (`VARCHAR(191)` yeterli). Probe D2 bu formatı PK'sız tabloda `AFTER DELETE` trigger'ı ile doğruladı.

### 1.5 KARAR P1b — `quote_items` ve `custom_field_values` de payload'a gömülür

`taggables` ile aynı ilke üç tabloya birden uygulanır: **pull tablo setinde yer almazlar, kendi `sync_version`'larını almazlar, `sync_deletions`'a girmezler.**

| Tablo | Nereye gömülür | Gerekçe |
|---|---|---|
| `taggables` | sahip satırın `tags: [ids]` alanı | §1.4 |
| **`quote_items`** | `quotes` satırının `items: [...]` alanı | `Repositories/QuoteRepository.php:176` `replaceItems()` **her düzenlemede TÜM kalemleri silip yeniden yaratıyor**. Ayrı tablo olarak senkronlamak her düzenlemede N tombstone + N yeni satır + kullanılmayan `client_id` eşleme çöpü üretir. |
| **`custom_field_values`** | sahip satırın `custom_fields: {key: value}` alanı | `SYNCDESKTOP.md` §4.1 zaten "entity ile birlikte payload'da (ayrı mutasyon değil)" diyor ve §4.4 alanı zaten gömüyor; ayrı ayna tablosu ve tombstone yüzeyi gereksiz. |

**Sahip bump zorunluluğu:** Üçünde de, yalnızca gömülü veri değişip sahip entity'nin kendi alanları temiz kaldığında delta **kaçar**. Bu yüzden her üçü için `TagSyncService` deseninde bir sarmalayıcı sahip satırın `sync_version`'ını garanti bump'lar:

- `taggables` → §1.4'teki 7 çağrı noktası + `LeadConversionService.php:376-390` çift bump.
- `quote_items` → `QuoteRepository::replaceItems()` (`:176`) sonrası sahip `quote` bump'lanır. **Per-item Eloquent delete'e çevirme GEREKMEZ** — kalemler ayrı senkronlanmadığı için tombstone da gerekmez; toplu delete olduğu gibi kalır.
- `custom_field_values` → entity servislerindeki `updateOrCreate` noktaları (`DealRepository:270`, `LeadRepository:192`, `TicketRepository:158`, `CompanyService:111`, `ContactService:124`, `ProductService:223`, `LeadConversionService:475`). Tam envanter F1'de çıkarılır.

---

## 2. `sync_version` MEKANİZMASI

### 2.1 Sorunun kökü — model event bypass'ları

Observer tabanlı atama yalnızca Eloquent model event'i üreten yazma yollarında çalışır. F0'da doğrulanan bypass'lar:

| Sınıf | Konum | Etkilenen tablo |
|---|---|---|
| Ham SQL (`DB::update`) | `Services/Chat/ChatReadState.php:71,104,129,144` | conversation_user |
| Ham query builder | `Services/Chat/ConversationService.php:256,394-397`, `MessageService.php:292` | conversation_user |
| Ham query builder | `Services/Leads/LeadConversionService.php:387,390` | taggables |
| Pivot ilişki API'si | `attach()/detach()/sync()` — **Laravel 12'de model event üretmiyor** | taggables, conversation_user |
| Eloquent toplu delete | `Repositories/QuoteRepository.php:176` | **quote_items** |
| Eloquent toplu update | `Http/Controllers/Api/NotificationController.php:99` | notifications |
| Eloquent toplu update | `Services/Leads/LeadConversionService.php:349,354` | tasks, activities |
| Eloquent toplu update | `Repositories/ContactRepository.php:160` | contacts |
| Eloquent toplu update/delete | `Services/Settings/PipelineStageService.php:238`, `Repositories/PriceListRepository.php:147,200` | pipeline_stages, price_lists, price_list_items |
| Toplu insert | `database/seeders/DemoDataSeeder.php:1684` (`bulkInsert()`, 20 çağrı) | 17 kapsam tablosu |

**Pivot API'si hakkında düzeltme:** Yaygın varsayımın aksine `attach()/detach()/sync()` **hiçbir** model event'i üretmez. `vendor/laravel/framework/.../Relations/Concerns/InteractsWithPivotTable.php` doğrudan `newPivotStatement()->insert(...)` ve `$query->delete()` çağırır; `pivotAttached`/`pivotDetached`/`pivotSynced` Laravel çekirdeğinde **yoktur** (grep: sıfır sonuç). Tek yan etki `touchIfTouching()`'dir ve hiçbir modelde `$touches` tanımlı değildir → ebeveynin `updated_at`'i bile ilerlemez.

**Doğrulandığı üzere PROJEDE HİÇ GEÇMEYENLER:** `DB::statement`, `DB::insert`, `DB::delete`, `DB::unprepared`, `Model::unguarded`, `withoutEvents`, `updateQuietly`, `deleteQuietly`, `forceDeleteQuietly`, `restoreQuietly`, `withoutTimestamps`, `upsert`, `updateOrInsert`, `insertGetId`, `toggle`, `syncWithoutDetaching`, `updateExistingPivot`. Tek `saveQuietly`: `Services/Auth/AuthService.php:171` (`last_login_at`).

### 2.2 KARAR P2 — Tablo bazlı mekanizma

| Tablo(lar) | Mekanizma | Gerekçe |
|---|---|---|
| companies, contacts, leads, deals, tasks, activities, tickets, quotes, conversations, messages, tags, products, custom_fields, exchange_rates, saved_views, settings, users | **`SyncVersionObserver`** (`SYNCDESKTOP.md` §4.2 aynen) | Yazma yollarının ~%95'i zaten Eloquent. Observer değeri PHP'de anında verdiği için §4.3'teki push yanıtı ekstra `SELECT` gerektirmez. |
| **conversation_user** | **DB TRIGGER** (`BEFORE INSERT` + `BEFORE UPDATE` + `AFTER DELETE`) | Tüm mutasyon yüzeyi ham SQL/query-builder. Eloquent'e çevirmek reddedildi: `ChatReadState`'in `GREATEST(...)`, `unread_count = (SELECT COUNT(*)…)` ve cross-member `+1` atomikliği dosyanın kendi yorumlarında gerekçelendirilmiş; bölmek o yarışları geri getirir. Sahip-entity bump da yanlış: `unread_count`/`last_read_message_id`/`is_muted` **kişiye özeldir**, `conversations.sync_version`'ı bump'lamak bir üyenin okuma durumunu tüm üyelerin delta'sına sokar. |
| **taggables, quote_items, custom_field_values** | **Sahip entity bump** (§1.4, §1.5) | Kendi versiyonları yok, pull setinde yoklar; sahip satırın payload'ına gömülüler. |
| pipeline_stages, price_lists, price_list_items | Observer **+ nokta düzeltmesi** | RO ama Kanban sütun sırası bayatlarsa offline pano yanlış çizilir. |
| notifications | Observer (`DatabaseNotification::observe(...)`) **+ nokta düzeltmesi** | Emsal: `AppServiceProvider.php:106-111` sahibi olmadığı bir vendor modelini (`ActivitylogServiceProvider::determineActivityModel()`) zaten gözlemliyor. |
| **DemoDataSeeder yolu** | **Tek seferlik backfill** | `DemoDataSeeder.php:31`'in açık tasarım kararını ("performans için factory yerine toplu insert") bozmamak için. `run()` sonunda §2.5'teki backfill helper'ı çağrılır. |

**Trigger toplamı: yalnızca `conversation_user` (3 trigger).** Bu, "trigger kod review'da görünmez, `pint`/PHPStan görmez" maliyetini alternatifi olmayan tek tabloyla sınırlar.

> **YASAK:** Aynı tabloda observer + trigger **birlikte kullanılmaz**. Trigger `SET NEW.sync_version` ile observer'ın yazdığını ezer; sonuç doğru olur ama yazma başına iki sayaç bump'ı harcanır ve versiyon uzayında boşluk açılır.

### 2.3 KARAR P3 — Düzeltilecek 8 çağrı noktası (F1 kapsamı)

| # | Konum | Düzeltme | Risk |
|---|---|---|---|
| 1 | `Repositories/QuoteRepository.php:176` | **`replaceItems()` sonrası sahip `quote`'un `sync_version`'ı garanti bump'lanır.** Toplu delete olduğu gibi kalır. | §1.5 gereği kalemler `quotes` payload'ına gömüldüğü için per-item delete'e çevirmeye ve tombstone'a gerek yok. Bump olmadan, yalnız kalem değişip quote alanları temiz kaldığında delta kaçar. |
| 2 | `Http/Controllers/Api/NotificationController.php:99` | `unreadNotifications()->update([...])` → chunk'lı Eloquent döngüsü | §4.3'ün `notification.read_all` action'ının ta kendisi. Satır başına tekil versiyon şartı (K-C) toplu UPDATE'i zaten yasaklıyor. |
| 3 | `Services/Leads/LeadConversionService.php:349` | `Task::withTrashed()->…->update()` | |
| 4 | `Services/Leads/LeadConversionService.php:354` | `Activity::withTrashed()->…->update()` | |
| 5 | `Repositories/ContactRepository.php:160` | `Contact::query()->…->update(['is_primary'=>false])` | |
| 6 | `Services/Settings/PipelineStageService.php:238` | `PipelineStage::query()->whereKey()->update(['position'…])` | |
| 7 | `Repositories/PriceListRepository.php:147` | `PriceList::query()->…->update(['is_default'=>false])` | |
| 8 | `Repositories/PriceListRepository.php:200` | `PriceListItem::query()->…->delete()` | |

Artı: §1.4'teki 7 `->tags()->sync()` çağrısının `TagSyncService`'e yönlendirilmesi ve `LeadConversionService.php:376-390`'ın çift bump'ı.

**Mevcut testlerde kırılma riski en yüksek ikisi:** #1 (kalem id'lerinin yeniden üretilme davranışı korunuyor, yalnızca silme N statement'a bölünüyor) ve #2.

### 2.4 KARAR P4 — Sayaç davranışı ve serileşme

`sync_version` ataması: `UPDATE sync_counter SET value = LAST_INSERT_ID(value+1) WHERE id=1`.

**Deneysel bulgular (MariaDB 10.4.32, `innodb_autoinc_lock_mode=1`, `log_bin=OFF`):**

| Probe | Bulgu |
|---|---|
| T1 | `LAST_INSERT_ID(expr)` trigger içinde sorunsuz oluşturuluyor ve çalışıyor. |
| T2/T3/T9 | **`PDO::lastInsertId()` BOZULMUYOR** — 1, 2, 7 doğru AUTO_INCREMENT id'leri döndü. Auto-inc değeri trigger'ın yazdığını eziyor. Eloquent `create()`/`insertGetId()` kırılmıyor. *(Bu, LAST_INSERT_ID yaklaşımının 1 numaralı korkusuydu; çürütüldü.)* |
| T4 | Çok satırlı INSERT: her satıra ayrı versiyon (3,4,5) — `FOR EACH ROW`. |
| T5/T6 | Toplu UPDATE (`WHERE id>1`): her satır kendi versiyonunu aldı (7,8,9,10). Observer'ın yapamadığı şey. |
| T7 | **No-op UPDATE (`SET title=title`) de tetikliyor** — sayaç 10→11. Sahte delta üretebilir. Eloquent temiz modelde UPDATE atmadığı için pratikte nadir; ham SQL'de gerçek. |
| T8 | ROLLBACK: sayaç 12→11'e geri döndü. Sayaç **transaction'a bağlı**. |
| C3 | Trigger'ın saf maliyeti: **33 ms trigger'lı / 31 ms trigger'sız, 300 INSERT**. İhmal edilebilir. |
| **C1/C2** | **İki eşzamanlı transaction `1205 Lock wait timeout exceeded` ile ölüyor** (3517 ms, 4026 ms; timeout 3 sn'ye çekilmişti). |
| E1 | AUTO_INCREMENT bilet tablosu alternatifi bloklamıyor (0 ms) ama **commit sırası ≠ versiyon sırası** olabiliyor. |

**KARAR (K-B):** `sync_counter`'ın küresel yazma mutex'i **kabul edilir**.

Gerekçe: bu maliyet trigger'a özgü değildir — `SYNCDESKTOP.md` §4.2'nin kendi observer tasarımı da birebir aynı cümleyi request transaction'ı içinde çalıştırır. Dahası serileşme bir bedel değil, bir **gerekliliktir**: commit sırası = versiyon sırası garantisini o sağlar. E1'in gösterdiği auto-increment alternatifi bu garantiyi kaybeder — B (v=2) A'dan (v=1) önce commit ederse, arada pull yapan istemci cursor'ı 2'ye taşır ve A'nın satırını **hiç görmez**. Serileşme, sessiz veri kaybının bedelidir.

#### KARAR P4a — Kilit çakışması retry politikası (bağlayıcı)

| Boyut | Karar |
|---|---|
| **Katman** | Yalnızca `SyncPushService`'in mutasyon-başına `DB::transaction`'ı. Retry birimi zaten izole; web (sync-dışı) yazma yollarına retry **eklenmez**. |
| **Yakalanan hatalar** | `1205` (lock wait timeout) ve `1213` (deadlock). |
| **Deneme** | En fazla **3**; backoff **100 / 400 / 900 ms**, ±%25 jitter. |
| **Tükenirse** | Mutasyon `rejected` işaretlen**mez** — geçici bir hata terminal statü alamaz. Batch işleme o noktada kesilir, işlenmiş sonuçlarla **HTTP 200 kısmi yanıt** döner (bkz. §4.3 kısmi yanıt semantiği). |
| **`innodb_lock_wait_timeout`** | **10 sn**, `config/database.php` `options` içinde `PDO::MYSQL_ATTR_INIT_COMMAND` ile **bağlantı düzeyinde** — sunucu ayarından bağımsız ve deterministik. Prod varsayılanı 50 sn'dir; 50 sn asılı kalmaktansa 10 sn'de gürültülü hata tercih edilir. |

**F1 ölçüm yükümlülüğü (aynen kalır):** iki uzun transaction ölçülüp belgelenecek — `Services/Settings/PipelineStageService.php:267` (`deactivate()`, `lockForUpdate()` ile tüm açık fırsatlar) ve `Services/Chat/ChatReadState.php:129` (`fanOutNewMessage()`, mesaj başına N-1 satır).

#### KARAR P4b — No-op UPDATE guard'ı (probe T7)

Probe T7'de `SET title = title` gibi değer değiştirmeyen bir UPDATE de trigger'ı tetikledi (sayaç 10→11). Bu **kabul edilmez**.

`conversation_user` trigger'ının `BEFORE UPDATE` dalına **NULL-safe alan karşılaştırması (`<=>`) guard'ı** yazılır: senkronlanan kolonların hiçbiri değişmediyse sayaç bump'lanmaz.

Gerekçe: (a) sahte delta üretimi engellenir; (b) `fanOutNewMessage()` — K-B mutex'inin ölçülmüş en riskli müşterisi, mesaj başına N-1 satır — üzerindeki gereksiz bump'lar elenerek kilit tutma süresi doğrudan kısalır. Maliyet düşük: trigger yalnız tek tabloda ve senkronlanan kolon sayısı ~5.

Observer tarafında bu sorun **yoktur**: Eloquent temiz modelde UPDATE atmaz, dolayısıyla T7 yalnızca ham SQL yolunda gerçektir.

### 2.5 KARAR P5 — Satır başına TEKİL versiyon zorunlu

`SYNCDESKTOP.md` §4.4 cursor'ı tek skalerdir (`{"deals": 184320}`). `LIMIT :limit` sınırı aynı `sync_version` değerine sahip iki satırın arasına düşerse, ikinci satır **bir daha asla dönmez**.

**KARAR (K-C):** Cursor tek skaler kalır; `sync_version` tablo içinde **satır başına tekil** olmak zorundadır. Trigger `FOR EACH ROW` olduğu için bunu doğal olarak sağlar.

Bu kararın sonucu: **"transaction başına tek versiyon" optimizasyonu masadan kalkmıştır.** `NotificationController.php:99`'u tek toplu UPDATE olarak bırakıp tüm satırlara aynı versiyonu vermek yasaktır (§2.3 #2). Alternatif olarak cursor `(sync_version, id)` bileşiğine çıkarılabilirdi; tekil bump daha basit olduğu için tercih edilmedi.

### 2.6 Backfill

Mevcut satırlar ve seeder yolu için, §4.2 backfill migration'ı ile **aynı** helper kullanılır:

```sql
SET @n := (SELECT value FROM sync_counter);
UPDATE <table> SET sync_version = (@n := @n + 1) ORDER BY id;
UPDATE sync_counter SET value = @n;
```

~17 statement, deterministik, prod'a sıfır maliyet. `DemoDataSeeder::run()` sonunda çağrılır. `conversation_user` zaten trigger'lı olduğundan seeder'ın o insert'i kendiliğinden versiyonlanır. `taggables` §1.4 gereği kapsam dışıdır.

### 2.7 Tombstone (`sync_deletions`)

| Tablo | `deleting` tetikleniyor mu? | Mekanizma | `row_key` |
|---|---|---|---|
| tags | Evet (silme ucu yok — `routes/api.php:254-255` yalnızca index+store) | `SyncDeletionObserver` (savunma amaçlı) | `id` |
| custom_field_values | — | **Tombstone yazılmaz** (§1.5 — sahip payload'ına gömülü) | — |
| quote_items | — | **Tombstone yazılmaz** (§1.5 — `quotes.items` içinde) | — |
| notifications | Evet (`NotificationController.php:114`) | `DatabaseNotification::observe(SyncDeletionObserver::class)` | `id` (UUID) |
| conversation_user | **Hayır** (`detach()` query-builder DELETE) | **`AFTER DELETE` trigger — tek yol** | `conversation_id:user_id` |
| taggables | — | **Tombstone yazılmaz** (§1.4) | — |

Sonuç: `sync_deletions`'a yalnızca **`tags`, `notifications`, `conversation_user`** girer. Gömülü üç tablo (§1.5) tombstone yüzeyinden tamamen çıkmıştır.

**`conversation_user` `row_key` gerekçesi:** surrogate `id` yerine `(conversation_id, user_id)` kullanılır — üye ayrılıp yeniden katılırsa yeni bir `id` doğar; istemci pivot'u mantıksal anahtarla adreslemelidir.

**`SyncDeletionObserver` uygulama notu:** `deleted` değil **`deleting`** kullanılır — aynı transaction içinde çalışır, DELETE geri alınırsa tombstone da geri alınır. Observer, soft-delete kullanan modellerde `deleting`'in `$model->delete()` ile de tetiklendiğini hesaba katmalı: `usesSoftDelete() && ! isForceDeleting()` durumunda **tombstone yazmamalıdır** (o durumda §4.2 gereği satırın kendisi `deleted_at != null` + yeni `sync_version` ile delta'da döner).

### 2.8 AÇIK RİSK — FK `ON DELETE CASCADE` kör noktası

**Probe D3 ile kanıtlandı:** MariaDB 10.4.32'de FK cascade ile silinen çocuk satır, çocuk tablonun `AFTER DELETE` trigger'ını **tetiklemiyor** (`taggables` satırı silindi, `sync_deletions`'a hiçbir şey yazılmadı). Eloquent de görmez. **Ne observer ne trigger bu yolu yakalayabilir.**

Şemadaki cascade zincirleri ve bugünkü durumları:

| FK | Bugün ölü mü? | Neden |
|---|---|---|
| `taggables.tag_id → tags` (`…200014:16`) | Ölü | tag silme ucu yok |
| `custom_field_values.custom_field_id → custom_fields` (`…200012:16`) | Ölü | `destroy()` aslında deactivate |
| `quote_items.quote_id → quotes` (`…200003:16`) | Ölü | Quote SoftDeletes |
| `messages.conversation_id`, `conversation_user.conversation_id → conversations` (`…200006:16`, `…200007:16`) | Ölü | Conversation SoftDeletes |
| `conversation_user.user_id → users` (`…200007:17`) | Ölü | User SoftDeletes |
| `price_list_items.*` (`…200002:16-17`) | Ölü | Ebeveynler SoftDeletes |

Hepsi **yalnızca soft delete sayesinde** uykuda — bu bir tesadüftür, tasarım değil. İleride bir `forceDelete`, KVKK purge veya gerçek tag silme ucu eklenirse sessiz veri kaybı doğar.

**KARAR P16 — F1'de iki katmanlı mimari regresyon testi.** `RESTRICT` migration'ı **yapılmaz** (§10.1 — kapsam genişletmesi).

1. **Şema kilidi:** `information_schema.REFERENTIAL_CONSTRAINTS`'ten kapsam tablolarındaki tüm `DELETE_RULE` değerleri okunur ve bilinen listeye `assertSame` ile kilitlenir. Yeni bir cascade eklenirse test kırmızıya döner.
2. **Yol kilidi:** kapsamdaki ebeveyn tablolarda hard-delete yolu bulunmadığı assert edilir.

Böylece bugünkü tesadüf (zincirlerin yalnız soft delete sayesinde ölü olması) bir sözleşmeye dönüşür.

---

## 3. KİMLİK DOĞRULAMA

### 3.1 Mevcut durum

`User` **`HasApiTokens` kullanmıyor** (`app/Models/User.php:16`: `HasFactory, HasRoles, LogsCrmActivity, Notifiable, SoftDeletes`). Kimlik doğrulama tamamen Sanctum SPA cookie modu (`bootstrap/app.php:116` `statefulApi()`). `laravel/sanctum ^4.3` bağımlılık olarak mevcut, `personal_access_tokens` migration'ı (`2026_08_23_123318`) hazır.

`bootstrap/app.php:112-115` ve `AuthController.php:19-22`'deki "No API tokens are used anywhere" yorumları **tasarım kararı değil, durum tespitidir** — bu depoda gerçek kararlar belirgin bir kalıpla yazılıyor (büyük harfli başlık + GEREKÇE/REDDEDİLDİ/DELIBERATELY ABSENT; bkz. `bootstrap/app.php:62-90`, `96-109`, `118-154`). Her iki yorum F1'de güncellenecektir.

### 3.2 `HasApiTokens` eklemenin etkisi — doğrulanmış

- **İsim/metot/property çakışması: YOK.** Trait yalnızca `tokens()`, `tokenCan()`, `tokenCant()`, `createToken()`, `generateTokenString()`, `currentAccessToken()`, `withAccessToken()` ekliyor. `Notifiable`, `HasRoles`/`HasPermissions`, `LogsCrmActivity`, `SoftDeletes` ile kesişim yok (`grep "tokenCan|accessToken|Sanctum" vendor/spatie/laravel-permission/src/` → 0 sonuç).
- **`/api/me` yanıt şekli DEĞİŞMEZ.** `AuthController.php:47-54` → `UserResource::make(...)`; `Http/Resources/UserResource.php:28-53` **açık beyaz listedir**, `toArray()` kullanmaz. `grep '\$user->toArray' app` → 0 sonuç.
- **Serileştirme sızıntısı YOK.** `tokens()` bir ilişkidir, `toArray()` yalnızca yüklenmiş ilişkileri serileştirir; hiçbir yerde `->load('tokens')` yok. `withAccessToken()` düz PHP property'sine yazar, Eloquent attribute değildir.

### 3.3 KARAR P6 — `ability:desktop` TEK BAŞINA YETMEZ

**Doğrulanmış zincir:**
1. `vendor/laravel/sanctum/src/Guard.php:31-38` — cookie kullanıcısı bulununca `supportsTokens($user) ? $user->withAccessToken(new TransientToken) : $user`
2. `Guard.php:71-76` — `supportsTokens()` = `in_array(HasApiTokens::class, class_uses_recursive(...))` → **bugün `false`, trait eklenince `true`**
3. `vendor/laravel/sanctum/src/TransientToken.php:15-18` — `can()` → **`return true;`** (koşulsuz)
4. `Http/Middleware/CheckForAnyAbility.php:22-30` — yalnızca `currentAccessToken()` dolu mu + `tokenCan($ability)` bakıyor

**Sonuç:** `HasApiTokens` eklendiği anda **her SPA cookie oturumu** `ability:desktop`'tan geçer. `SYNCDESKTOP.md` §9'un 1. maddesi ("`desktop` ability'siz token → `/api/sync/*` 403") bu hâliyle sağlanamaz; dahası o madde için `actingAs()` ile yazılacak bir test **yanlış yeşil** verir.

**KARAR:** Sync route'ları `ability:desktop`'a ek olarak `currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken` kontrolü yapan bir middleware taşır (`EnsureDeviceToken`). §9 testleri gerçek `createToken()` + `withToken(...)` ile yazılır; `actingAs()` bu testlerde **YASAK**.

### 3.4 KARAR P7 — `ability` alias'ı kayıtlı DEĞİL (şartname boşluğu)

- `vendor/laravel/framework/.../Configuration/Middleware.php` `defaultAliases()` içinde `ability`/`abilities` **yok** (Laravel 11+ ile kaldırıldı; grep: 0 sonuç).
- Sanctum hiçbir alias kaydetmiyor (`grep aliasMiddleware vendor/laravel/sanctum/src/` → 0 sonuç).
- `bootstrap/app.php:157-160` alias bloğunda yalnızca `active` ve `password.changed` var.

**Sonuç:** `SYNCDESKTOP.md` §4.4'ün route tanımı bu hâliyle boot'ta `BindingResolutionException` verir. F1'de alias bloğuna `'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class` eklenmesi **ön koşuldur**.

**Hata eşlemesi doğru çalışır, yeni kod gerekmez:** `MissingAbilityException extends AuthorizationException` → `AccessDeniedHttpException` → `bootstrap/app.php:198-200` → **403 FORBIDDEN**. `currentAccessToken()` boşsa `AuthenticationException` → `bootstrap/app.php:194-196` → **401**.

### 3.5 `POST /api/auth/device`

`SYNCDESKTOP.md` §4.3 sözleşmesi aynen geçerlidir. Ek gereksinimler:

- **`personal_access_tokens` tablosunda `device_fingerprint` kolonu YOK** (`2026_08_23_123318:14-23`). §4.3'ün "aynı `device_fingerprint` için eski token silinir" kuralı ya yeni bir kolon ya da `name` alanına gömme gerektirir (`name` `text`, unique değil). **KARAR:** ayrı kolon eklenir (`device_fingerprint CHAR(64) NULL, INDEX`), gömme reddedilir — `name` kullanıcıya gösterilen cihaz adıdır.
- `config/sanctum.php:57` `expiration = null` — kalıcı token için doğru. **Değiştirilmemeli**, yoksa tüm token'lar toptan süreli olur.
- `config/sanctum.php:44` `guard = ['web']` — önce session, sonra bearer denenir. Doğru, değiştirilmemeli.
- Rate limit: mevcut `RateLimiter::for('login')` (`Providers/AppServiceProvider.php:192`, `registerLoginRateLimiter()`) yeniden kullanılır — 5 deneme, 1→2→4→…→60 dk üstel pencere, anahtar `AuthService::throttleKey` (email+IP).
- `session_logs` tablosunda **`channel` kolonu yok** (`2026_08_23_200010`) — §4.3'ün `channel=desktop` kaydı için `channel VARCHAR(16) DEFAULT 'web'` eklenir.
- Soft-delete'li kullanıcı **fail-closed**: `Guard.php:45-48` `supportsTokens($accessToken->tokenable)`; SoftDeletes global scope'u yüzünden `tokenable` `null` döner → 401. Ek kod gerekmez.

### 3.6 KARAR P8 — Token iptal noktaları

| Akış | Servis | Nereye |
|---|---|---|
| `PATCH /api/users/{user}/active` (false) | `Services/Users/UserService.php:177-193` `toggleActive()` | `$user->tokens()->delete()`, satır 186 (`save()`) ile 188 (`event(new UserDeactivated)`) arasına, `DB::transaction` içine |
| `DELETE /api/users/{user}` | `Services/Users/UserService.php:208-216` `delete()` | satır 212 (`save()`) ile 214 (`repository->delete()`) arasına |
| **`POST /api/users/{user}/reset-password`** | `Services/Users/UserService.php:195-206` `resetPassword()` | satır 201 (`setRememberToken`) ile 203 (`save()`) arasına — **`SYNCDESKTOP.md` §4.3'te ATLANMIŞ**; yönetici şifre sıfırlaması tipik olarak "hesap ele geçirildi" senaryosudur, tüm cihaz token'ları silinmelidir |
| `POST /api/password/change` | `Services/Auth/AuthService.php:243-273` `changePassword()` | adım 4 (`save()`, satır 255) ile adım 5 (`session()->regenerate()`, satır 258) arasına — `docs/AUTH-FLOWS.md` §3.2 sırası bağlayıcı |

**Şifre değişikliğinde "mevcut cihaz korunur" TUZAĞI:** Naif `where('id','!=',$user->currentAccessToken()?->id)` **sessizce hiçbir şey silmez** — cookie oturumundan gelen bir şifre değişiminde `currentAccessToken()` bir `TransientToken`'dır ve `->id`'si yoktur, `where('id','!=',null)` SQL semantiği gereği hiçbir satırı eşleştirmez. Doğru koşul token tipini açıkça ayırmalıdır: SPA'dan gelen değişimde **tüm** cihaz token'ları silinir; masaüstünden gelende **kendisi hariç** hepsi.

> `AuthService.php:260-265`'teki "diğer oturumlar için ek kod gerekmez" yorumu artık eksiktir — `AuthenticateSession`/`password_hash_web` yalnızca stateful isteklerde çalışır, bearer token'lar o kontrole hiç girmez. Yorum ve `docs/AUTH-FLOWS.md` §3.3 F1'de genişletilecek.

### 3.7 KARAR P9 — `/broadcasting/auth` bearer

Mevcut kayıt: `bootstrap/app.php:91-94` → `withBroadcasting(channels.php, ['middleware'=>['web','auth:sanctum','active']])` — cookie yığını. `frontend/src/lib/echo.ts:77-87` authorizer'ı CSRF'li axios ile gidiyor.

**`withBroadcasting` ikinci kez ÇAĞRILMAZ.** `BroadcastManager::routes()` URI'yi hard-code eder; ikinci kayıt da `/broadcasting/auth` üretir. Route'ların adı olmadığı için "duplicate name" hatası **çıkmaz** — `RouteCollection` ilk eşleşeni döndürür ve ikinci kayıt **sessizce hiç çalışmaz**. Sessiz sapma, gürültülü hatadan kötüdür.

**KARAR:** `routes/api.php` içinden `Broadcast::routes(['middleware' => ['auth:sanctum','active']])` çağrılır → `GET|POST /api/broadcasting/auth`. `ApplicationBuilder::buildRoutingCallback()` route dosyasını `Route::middleware('api')->prefix('api')->group(...)` içinde yüklediği için iç `->group()` bu dış grubu miras alır (`api` grubu = `statefulApi` + `SetLocale`). Mevcut `/broadcasting/auth` **olduğu gibi kalır** — SPA yolu değişmez.

`password.changed` **bilinçli olarak eklenmez** (`bootstrap/app.php:82-89`'daki DELIBERATELY ABSENT gerekçesi masaüstü için de aynen geçerli). `routes/channels.php` hiçbir kanalda `['guards'=>...]` opsiyonu kullanmadığı için kanal callback'leri değişmeden çalışır.

### 3.8 TUZAK — Origin/CSRF

`EnsureFrontendRequestsAreStateful::fromFrontend()` yalnızca `Origin`/`Referer` başlığına bakar. Masaüstü webview'ının origin'i (Windows'ta `http://tauri.localhost`) `SANCTUM_STATEFUL_DOMAINS` listesiyle eşleşirse istek **stateful** sayılır, `ValidateCsrfToken` devreye girer ve bearer'lı `POST` **419 CSRF_TOKEN_MISMATCH** alır. `/api/broadcasting/auth` `POST` olduğu için en çok orada ısırır.

**Kural:** masaüstü origin'i `SANCTUM_STATEFUL_DOMAINS`'in **dışında** tutulur; dev ortamında Vite ile aynı origin'den istek atılmaz.

---

## 4. SYNC ENDPOINT'LERİ

Route grubu:
```php
Route::prefix('sync')
     ->middleware(['auth:sanctum','active','password.changed','ability:desktop','device.token'])
```
(`device.token` = §3.3'teki `EnsureDeviceToken`.)

### 4.1 `GET /api/sync/manifest` — `throttle:30,1,sync`

`SYNCDESKTOP.md` §4.4 sözleşmesi aynen. `protocol_version = 1`. Modül `.view` izni yoksa tablo **anahtarıyla birlikte** yanıttan çıkar (`GlobalSearchService` ile aynı ilke). `tables` haritasında `taggables` **yer almaz** (§1.4).

### 4.2 `POST /api/sync/pull` — `throttle:30,1,sync`

`SYNCDESKTOP.md` §4.4 sözleşmesi aynen. Sorgu `WHERE sync_version > :cursor ORDER BY sync_version ASC LIMIT :limit` — keyset, tek kolon; §2.5'in tekil versiyon garantisi bunun doğruluk ön koşuludur.

`rows` içine gömülü gidenler: `tags: [ids]` (§1.4) ve `custom_fields: {key: value}`.

### 4.3 `POST /api/sync/push` — `throttle:20,1,sync-push`, batch ≤ 200, ≤ 2 MB

`SYNCDESKTOP.md` §4.4 sözleşmesi aynen, iki ekle:

**`deal.move` payload'ı iki sayaç taşır.** `deals` tablosunda **mevcut bir `version` kolonu vardır** (`2026_08_23_100004:25`, `unsignedInteger('version')->default(1)`, optimistic locking; `DealMoveService` + `MoveDealRequest` akışında kullanılıyor). Bu, `sync_version`'dan **bağımsız** bir kavramdır: `version` çakışma tespiti için, `sync_version` delta cursor'ı için. `op=action deal.move` payload'ı `version`'ı (mevcut sözleşme gereği) taşır; `base_sync_version` ayrıca taşınır.

**`notification.read_all` genel şemaya uymuyor.** §4.4'ün `op=action` şekli `entity` + `server_id`/`client_id` odaklıdır; `read_all` ise **kullanıcı kapsamlı** ve satır id'si taşımaz.

**KARAR P10:** `read_all` için parametresiz, kullanıcı kapsamlı bir action alt-türü tanımlanır:
```json
{ "seq": n, "idempotency_key": "…", "op": "action", "entity": "notification",
  "action": "read_all", "scope": "user", "occurred_at": "…" }
```
`server_id` ve `client_id` bu tek durumda **yoktur**; sunucu §2.3 #2'deki chunk'lı döngüyü çalıştırır ve sonuçta etkilenen satır sayısını `{"status":"applied","affected":N}` olarak döner.

**KARAR P10b — Kısmi yanıt semantiği (wire sözleşmesi).** Kilit çakışması retry'ı tükendiğinde (§2.4 P4a) batch işleme kesilir ve sunucu **HTTP 200** ile o ana kadar işlenmiş sonuçları döner. Sözleşmeye bağlayıcı cümle:

> `results` dizisinde `seq`'i bulunmayan her mutasyon **işlenmemiş sayılır**; istemcide `queued` durumunda kalır ve sonraki turda yeniden gönderilir.

Bu, yeni bir hata kodu veya statü gerektirmez — `idempotency_key` tekrar gönderimi güvenli kılar. İstemci tarafı karşılığı §6'daki push sonuç işleyicisidir.

### 4.4 Hata kodları

Yeni: `ONLINE_ONLY`, `UNRESOLVED_REFERENCE`, `FIELD_CONFLICT`, `RECORD_DELETED`, `PROTOCOL_VERSION_MISMATCH`, `PUSH_BATCH_TOO_LARGE`, `INVALID_MUTATION`, `ABILITY_REQUIRED`.
Mevcutlar aynen: `DEAL_VERSION_CONFLICT`, `QUOTE_LOCKED`, `INVALID_STATUS_TRANSITION`, `ROLE_NOT_EDITABLE`.

---

## 5. ÇAKIŞMA TESPİTİ (`ConflictDetector`)

`SYNCDESKTOP.md` §4.4'ün alan bazlı algoritması `activity_log` kayıtlarına dayanır: `server.sync_version > base_sync_version` ise `subject=(entity, server_id)` ve `created_at > occurred_at` olan kayıtların `properties.attributes` anahtarları toplanır; `changed_fields ∩ değişen_anahtarlar ≠ ∅` → `conflict`.

### 5.1 KARAR P11 — activity_log tutmayan entity'lerde kayıt düzeyi çakışma

`app/Support/ActivityLogging/LogsCrmActivity.php` doc bloğunda gerekçelendirildiği üzere şu entity'ler **kasıtlı olarak** audit tutmaz:

**`Conversation`, `Message`, `CustomFieldValue`, `Attachment`, `PageVisitLog`, `SessionLog`**, ve `notifications` (DatabaseNotification).

Bunlardan sync kapsamında olanlar: `conversations`, `messages`, `custom_field_values`, `notifications`.

**KARAR:** Bu tablolarda alan bazlı LWW **uygulanmaz**; `sync_version` farkı varsa doğrudan kayıt düzeyi `conflict` üretilir.

`LogsActivity` eklemek **reddedilmiştir**: chat'te her mesaj için audit satırı yazmak, mevcut tasarımın bilinçle reddettiği bir maliyettir ve `activity_log` §1.3 gereği zaten hiç senkronlanmaz.

Pratikte etkisi sınırlıdır: `messages` üzerinde eşzamanlı alan düzenlemesi nadir; `custom_field_values` sahip entity payload'ına gömülü gider (§4.2); `notifications` yalnızca `read`/`delete` action'ları alır.

---

## 6. `syncra-sync` CRATE

`SYNCDESKTOP.md` §5 sözleşmesi aynen geçerlidir (Cargo bağımlılıkları, modül yapısı, public API, lokal şema, outbox topolojik sırası, sync döngüsü, retention, test matrisi). Bu belge yalnızca F0'da netleşen sapmaları kaydeder.

### 6.1 KARAR P12 — `notifications` lokal şeması

`notifications.id` zaten `CHAR(36)` UUID'dir, hiçbir zaman null olamaz ve auto-increment `id` yoktur.

**KARAR:** İstemci ayna tablosunda `client_id = notifications.id` doğrudan kullanılır; ayrı bir `client_id` kolonu **eklenmez** ve `server_id INTEGER UNIQUE` alanı bu tabloda **atlanır**.

Sonuç: §5.3'teki "sunucuda `client_id` null olan web kayıtları için deterministik `uuid5(namespace, 'entity:server_id')`" adımı `notifications` için **yapısal olarak gereksizdir** — o durum bu tabloda var olamaz.

### 6.2 KARAR P13 — Üç tablo lokal şemada ayna tablosu DEĞİLDİR

§1.4 ve §1.5 gereği `taggables`, `quote_items` ve `custom_field_values` pull setinde yoktur; istemcide de ayrı tablo tutulmaz. Sırasıyla sahip satırın `tags`, `quotes.items` ve `custom_fields` alanlarında saklanırlar.

**Crate'e üç somut etki:**
1. Lokal şemadan (`migrations/0001_init.sql`) bu üç ayna tablosu **düşer**.
2. §5.4'teki outbox topolojik sırasından **`quote_item(4)` seviyesi kalkar** — kalemler `quote` mutasyonunun payload'ında taşınır.
3. FTS trigger'ları etkilenmez (`quotes` için `quote_number` indeksleniyor, kalem satırları değil).

### 6.3 KARAR P14 — `conversation_user` push yanıtı

Trigger'ın yazdığı `sync_version` PHP'de doğrudan mevcut değildir.

**KARAR:** `ChatReadState.php:166` `cursorsFor()` sorgusuna `sync_version` kolonu eklenir. `conversation.read` / `conversation.delivered` action'ları zaten o SELECT'i atıyor → deterministik ve sıfır ek maliyet.

`SELECT LAST_INSERT_ID()`'nin UPDATE sonrası trigger değerini döndürüp döndürmediği ölçülmedi ve **ölçülmeyecek**: ikinci bir yolu ölçülmemiş bir MariaDB davranışına dayandırmak yalnızca belirsizlik taşır. Bu seçenek kapatılmıştır.

### 6.4 KARAR P15 — Push sonuç işleyicisi kısmi yanıtı ele alır

§4.3 P10b gereği: sunucudan dönen `results` dizisinde `seq`'i bulunmayan outbox kaydı **`queued` durumunda bırakılır** (`inflight`'tan geri alınır), `attempts` artırılmaz ve sonraki `sync_now()` turunda yeniden gönderilir. Bu kural F2'de baştan implemente edilir; sonradan eklenmesi push state machine'inin yeniden yazılmasını gerektirir.

---

## 7. TEST MATRİSİ (F1 — `tests/Feature/Sync`)

`SYNCDESKTOP.md` §4.6'nın 1–6 maddeleri aynen, aşağıdaki eklerle.

### 7.1 KESİN KIRILACAK MEVCUT TEST

**`tests/Feature/Security/PasswordChangeGateTest.php:64-95`** — `test_password_changed_middleware_whitelist_is_exactly_the_four_expected_endpoints`. `Route::getRoutes()`'u tarar, `auth:sanctum` taşıyıp `password.changed` taşımayan tüm route'ları toplar ve `assertSame` ile şu dörde kilitler:
```
'GET api/me', 'GET|POST broadcasting/auth', 'POST api/logout', 'POST api/password/change'
```

Kırılma sebepleri: (a) `GET /api/me/devices` ve `DELETE /api/me/devices/{token}` — **KARAR:** bunlar bilinçli olarak `password.changed` grubuna konur (zorunlu şifre değişimi bekleyen bir kullanıcı cihaz kaydetmemeli) → listeyi etkilemez. (b) `GET|POST api/broadcasting/auth` (§3.7) → listeye **5. eleman olarak eklenir** ve gerekçesi test içinde yorumla yazılır. Bu test tam bu amaçla yazılmıştır; **sessizce güncellenmez**.

`POST /api/auth/device` public olduğu (`auth:sanctum` taşımadığı) için listeyi etkilemez.

### 7.2 Etkilenmeyenler — doğrulandı

87 test dosyası, 1090 `actingAs(` çağrısı, `Sanctum::actingAs` **0**, `assertExactJson` **0**, `personal_access_tokens`/`createToken`/`tokenCan` kullanımı **0**. `tests/Feature/Security/ChannelPayloadLeakTest.php:129,199,230,246` tam eşitlik (`assertEqualsCanonicalizing`) kullanıyor ama kaynağı `Broadcasting/ChannelRegistry.php:101` açık dizidir → etkilenmez. `tests/Feature/AuthTest.php:136-143` gevşek `assertJsonStructure` → etkilenmez. Migration hazır olduğu için `RefreshDatabase` sorunsuz.

### 7.3 Yeni zorunlu testler

1. **§3.3 kilidi:** `actingAs()` ile giren cookie kullanıcısı `/api/sync/*`'a **403** alır. `actingAs()` bu testte kullanılmak zorundadır (kırılganlığı kanıtlamak için); ability testleri ise `createToken()` + `withToken()` ile yazılır.
2. **§2.3 #1:** teklif kalemi silindiğinde `sync_deletions` satırı oluşur.
3. **§2.3 #2:** `notification.read_all` sonrası etkilenen her satırın **tekil** `sync_version`'ı vardır (§2.5).
4. **§2.5 keyset kararlılığı:** 600 kayıt / limit 500 — sıfır tekrar, sıfır atlama.
5. **§2.8:** kapsam tablolarında hard cascade tetiklenemez (mimari regresyon).
6. **conversation_user trigger'ı:** `ChatReadState::markRead/markDelivered/fanOutNewMessage` sonrası `sync_version` ilerlemiştir; `detach()` sonrası `sync_deletions`'a `conversation_id:user_id` yazılmıştır.
7. **§2.6:** `DemoDataSeeder` sonrası hiçbir kapsam tablosunda `sync_version = 0` kalmaz.
8. **§3.6:** şifre değişikliğinde SPA'dan gelen istek tüm cihaz token'larını siler; masaüstünden gelen kendi token'ını korur.

**Test izolasyonu:** paralel worktree'lerde `DB_DATABASE=test_tmp_<sonek>` (docs/ENGINEERING-RULES.md §5).

---

## 8. KARARA BAĞLANMIŞ MADDELER

Tümü karara bağlanmıştır; açık madde kalmamıştır.

| # | Konu | Karar |
|---|---|---|
| **K-A** | `ability:desktop` cookie oturumunu engellemiyor (§3.3) | **ONAYLANDI** — `EnsureDeviceToken` middleware'i eklenir. §9/1 testleri `createToken()` + `withToken()` ile yazılır; `actingAs()` o testlerde **YASAK**. |
| **K-B** | `sync_counter` küresel yazma mutex'i (§2.4) | **ONAYLANDI** — serileşme, "commit sırası = versiyon sırası" garantisinin kendisidir; alternatifi sessiz veri kaybıdır. Retry politikası §2.4 P4a'da bağlayıcı olarak yazıldı. |
| **K-C** | Tek skaler cursor vs `(sync_version, id)` (§2.5) | **ONAYLANDI** — tek skaler + satır başına tekil bump. Bileşik cursor hem sunucu sorgusunu hem crate'in `cursors` tablosunu hem pull sözleşmesini karmaşıklaştırırdı. Toplu-tek-versiyon optimizasyonu **kalıcı olarak** masadan kalkmıştır. |
| **K-D** | `conversation_user` trigger değerinin okunma yolu (§6.3) | **DARALTILARAK ONAYLANDI** — `cursorsFor()` sorgusuna kolon eklenir; `LAST_INSERT_ID` seçeneği **tamamen kapatıldı**, ölçüm gereksizleşti ve F1'i bloklamıyor. |
| **K-E** | `device_fingerprint` kolonu (§3.5) | **ONAYLANDI** — `CHAR(64) NULL` ayrı kolon + index. `name` `text` tipinde ve kullanıcıya gösterilen cihaz adıdır; anahtar niteliğindeki değer gösterim alanına gömülmez. |
| **K-F** | `quote_items`'ı `quotes` payload'ına gömmek | **ONAYLANDI — şartname düzeltmesi olarak** (kapsam genişletmesi değil). `custom_field_values` ile birlikte §1.5'e işlendi; §2.3 #1 düzeltmesi "per-item delete" yerine "sahip quote bump" şeklini aldı. |
| **P4a** | Kilit çakışması retry politikası | §2.4 — `SyncPushService` katmanı, `1205`+`1213`, max 3 deneme, 100/400/900 ms ±%25 jitter, `innodb_lock_wait_timeout=10s` bağlantı düzeyinde. |
| **P4b** | No-op UPDATE trigger'ı (probe T7) | §2.4 — **kabul edilmedi**; `BEFORE UPDATE` içine NULL-safe (`<=>`) guard yazılır. |
| **P10b** | Kısmi push yanıtı | §4.3 — `results`'ta `seq`'i olmayan mutasyon istemcide `queued` kalır; yeni hata kodu gerekmez. |

### 8.1 F1 öncesi dondurulmuş olması ŞART olanlar

K-A, K-B, K-C, K-E, K-F, P4a'nın kısmi-yanıt semantiği, P4b, P10, P10b, §1.5 gömme kararı ve D1–D13'ün tamamı. **Bunlar hem F1'in hem F2'nin girdisidir** — F2 crate'i wire format'a karşı yazılacağı için üç şerit açılmadan ikisi de donmuş olmalıdır.

**F1 içinde çözülebilir (wire'ı etkilemez):** K-D'nin sunucu-içi uygulaması, iki uzun transaction'ın ölçümü, token iptal noktalarının tam satır konumları, `custom_field_values` bump çağrı noktalarının tam envanteri.

---

## 9. ŞARTNAME DÜZELTMELERİ

Aşağıdakiler `SYNCDESKTOP.md` ile bu belgenin ayrıştığı noktalardır. **13'ünün de KABUL edildiği karara bağlanmıştır** — her biri kodda doğrulanmış olgulara dayanıyor ve şartname keşiften önce yazıldığı için ayrışmalar şartnamenin güncellenmesiyle giderilir. `SYNCDESKTOP.md`'nin ilgili maddeleri bu belgeye göre revize edilecektir.

İki maddede karar, belgedeki ilk öneriden farklılaştı:
- **D2** — §9/1'in metni "device token taşımayan istemci (cookie dahil) → 403" olarak yeniden yazılır; mevcut ifade fiziksel olarak sağlanamıyor.
- **D5** — düzeltme "per-item delete" değil, K-F sonrası **"sahip quote bump"** şeklinde uygulanır (§1.5, §2.3 #1).

| # | §  | Şartname | Bulgu | Bu belgedeki karar |
|---|---|---|---|---|
| D1 | §4.4 | `ability:desktop` middleware'i | Alias Laravel 12'de ve Sanctum'da kayıtlı değil → boot'ta `BindingResolutionException` | §3.4 — alias F1 ön koşulu |
| D2 | §9/1 | "`desktop` ability'siz token → 403" | `TransientToken::can()` koşulsuz `true` → cookie oturumu geçer | §3.3 — `EnsureDeviceToken` |
| D3 | §4.1 | `taggables` RW, `sync_deletions`'a hard delete | Tablonun `id`/PK'si yok; §4.1 zaten "ayrı mutasyon değil" diyor | §1.4 — sahip entity bump, pull setinden çıkar |
| D4 | §4.2 | `SyncVersionObserver` tüm tablolarda | `conversation_user`'ın tüm yüzeyi ham SQL; `attach/detach/sync` event üretmiyor | §2.2 — o tabloda trigger |
| D5 | §4.2 | (kapsanmamış) | `QuoteRepository.php:176` toplu delete → `quote_items` versiyon+tombstone kaybediyor | §2.3 #1 |
| D6 | §4.4 | `op=action` `entity`+`server_id` odaklı | `notification.read_all` kullanıcı kapsamlı, satır id'si yok | §4.3 — `scope: "user"` alt-türü |
| D7 | §4.3 | Token iptali `active`/`destroy` akışlarında | `UserService::resetPassword()` atlanmış | §3.6 |
| D8 | §4.3 | "aynı fingerprint için eski token silinir" | `personal_access_tokens`'ta `device_fingerprint` kolonu yok | §3.5 |
| D9 | §12/4 | "route'u `api` grubuna ikinci kez kaydetmek mi?" | `withBroadcasting` iki kez → aynı URI, ikincisi **sessizce ölür** | §3.7 — `Broadcast::routes()` |
| D10 | §5.3 | `client_id` + `server_id INTEGER` + `uuid5` türetme | `notifications.id` zaten UUID | §6.1 |
| D11 | §12/1 | "activity_log yoksa `LogsActivity` eklensin mi?" | 6 entity kasıtlı olarak dışarıda | §5.1 — kayıt düzeyi çakışma, trait eklenmez |
| D12 | §12/2 | "seeder/import observer tetikliyor mu?" | `LeadImportService` Eloquent kullanıyor (event **var**); `DemoDataSeeder` toplu insert (event **yok**) | §2.6 — yalnızca seeder için backfill |
| D13 | §4.2 | `session_logs`'a `channel` eklenmesi | Kolon gerçekten yok, doğrulandı | §3.5 |

---

## 10. ÖNERİLER — KARARA BAĞLANDI

| Öneri | Karar | Gerekçe |
|---|---|---|
| `quote_items`'ı `quotes` payload'ına gömmek | **ŞİMDİ (F1)** | K-F ile onaylandı, §1.5'e işlendi. `replaceItems()`'ın id-churn'ü ayrı tabloyu fiilen işlevsiz kılıyor. |
| `custom_field_values`'ı payload'a gömmek | **F1'DE** | §1.5'e işlendi. `taggables` (D3) ile simetrik; bir ayna tablosu ve bir tombstone yüzeyi eksilir. Şartı sahip-bump sarmalayıcısıdır. |
| `sync_counter` yerine tablo başına ayrı sayaç | **HİÇ** | Tablolar arası nedensellik (tutarlı kesit) kaybı kabul edilemez: B'nin `deal`'i A'nın `company`'sinden önce görünürse istemci kırık referans upsert eder. Mutex zaten P4a ile yönetiliyor; sorun ölçülerek kanıtlanmadan mimari değiştirilmez. |

### 10.1 Kapsam genişletmesi — kullanıcı onayı gerekir, uygulanmadı

Aşağıdakiler `SYNCDESKTOP.md` §0.5 gereği kapsam dışıdır ve **yapılmayacaktır**; yalnızca kayda geçirilmiştir.

1. **Cascade FK'ları `RESTRICT`'e çeviren migration** (§2.8). Web ürününün şema semantiğini değiştirir. §2.8'in regresyon testi bugünkü durumu zaten kilitliyor; `RESTRICT` ancak `forceDelete` / KVKK purge gündeme gelirse gerekir.
