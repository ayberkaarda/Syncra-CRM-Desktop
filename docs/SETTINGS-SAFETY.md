# SETTINGS-SAFETY — Ayarlar Modülü Veri Bütünlüğü Sözleşmesi (Faz 10)

> **Bağlayıcı sözleşmedir.** Faz 10'da pipeline aşama editörünü, özel alan yönetimini ve
> rol/izin matrisini yazan her şerit bu dokümana uyar. Ortak tehdit modeli üçünde de aynıdır:
> **bir ayar değişikliği, çalışan bir şeyi sessizce bozabilir.** Bu doküman "sessizce" kısmını
> yasaklar — her riskli değişiklik ya engellenir, ya açık bir hedef/onay ister, ya da anında
> görünür bir sonuca bağlanır.
>
> İlgili sözleşmeler: `docs/DATABASE.md` §3, `docs/ROADMAP.md` §5 (R4, R11, R28),
> Faz 7 taşıma sözleşmesi (`PATCH /api/deals/{deal}/move`), `docs/AUTH-FLOWS.md`.

---

## 1. Karar Özeti

**S1 — Açık kartı olan aşama pasifleştirilirse:** **Seçenek (b) — hedef aşama zorunlu, taşıma atomik.**
Engelleme (a), yüzlerce eski açık kartı olan bir aşamayı fiilen ölümsüzleştirir ve kullanıcıyı kart kart
elle taşımaya mahkûm eder; salt-okunur sütun (c) ise "pano yalnızca aktif aşamaları gösterir" kuralını
deler ve Kanban'a kalıcı bir istisna durumu ekler. Hedef aşamaya atomik toplu taşıma hem veri kaybını
hem "kaybolan kart"ı imkânsız kılar ve Faz 7'nin `position`/`version` mekanizmasıyla birebir uyumludur.
Kapalı (`won`/`lost`) kartlar taşınmaz — pasif aşamaya tarihsel referans vermeleri Faz 3 kararı gereği
zaten geçerlidir ve raporlamayı bozmaz.

**S2 — Değeri olan özel alan silinirse:** **Silme engellenir (422); varsayılan yol pasifleştirmedir.**
Cascade silme geri alınamaz veri kaybıdır ve `custom_fields` soft delete kullanmadığı için gerçekten
DELETE tetikler (R28'in tersine burada koruyucu bir soft delete katmanı yok) — bu yüzden uygulama
katmanı guard'ı zorunludur. Gerçek DELETE yalnızca hiç değeri olmayan tanım için serbesttir. Değeri
olan alanda tip değişimi de engellenir; `select` seçeneği çıkarma ise kullanım sayısı gösterilen açık
onaylı iki adımlı akışla geçer ve mevcut değerlere dokunmaz.

**S3 — Rol/izin matrisi değişirse:** **Backend her istekte otorite kalır; UI realtime event ile anında
tazelenir; kendini kilitleme sunucuda engellenir.** İzin kontrolü zaten her istekte Policy/Gate ile
yapıldığı için güvenlik açısından "bayat UI" bir açık değildir — ama kötü UX'tir; mevcut
`private-user.{id}` altyapısına `permissions.changed` eventi eklenir ve istemci `GET /api/me`'yi
yeniden çeker. Zorla logout YOKTUR (pasifleştirmeden farklı olarak bu bir güvenlik acili değildir).
Super Admin rolü matristen düzenlenemez, aktör kendini matris erişiminden edecek değişikliği yapamaz,
son aktif Super Admin koruması (Faz 2) aynen geçerlidir. Spatie izin önbelleği her matris yazımından
sonra temizlenir.

---

## 2. Pipeline Aşama Editörü

### 2.1 Uçlar ve genel kurallar

Tüm uçlar `settings.manage` izni ister (Policy'de; Super Admin `Gate::before` ile geçer).
Hata zarfı proje standardıdır: `{ "errors": { "message", "code", "fields" } }`.

| İşlem | Uç | Not |
| --- | --- | --- |
| Listele | `GET /api/settings/pipeline-stages` | Aktif + pasif hepsi; her satırda `open_deals_count` |
| Oluştur | `POST /api/settings/pipeline-stages` | `slug` sunucuda üretilir |
| Güncelle | `PATCH /api/settings/pipeline-stages/{stage}` | ad/renk/olasılık; kısıtlar aşağıda |
| Yeniden sırala | `PATCH /api/settings/pipeline-stages/reorder` | tüm id'ler tek listede |
| Pasifleştir | `POST /api/settings/pipeline-stages/{stage}/deactivate` | `{ move_to_stage_id? }` |
| Aktifleştir | `POST /api/settings/pipeline-stages/{stage}/activate` | önkoşulsuz |
| **Sil** | **YOK** | Faz 3 kararı: DELETE ucu hiç açılmaz. `restrictOnDelete` DB emniyet kemeri olarak kalır. |

### 2.2 Değişmezler (invariant) — her yazma işleminden sonra doğru olmak zorunda

1. En az **1 aktif `is_won`** ve en az **1 aktif `is_lost`** aşama vardır (Faz 7 aşama geçiş
   kuralları terminal aşamasız çalışamaz).
2. En az **1 aktif terminal-olmayan** aşama vardır (deal oluşturma ve Faz 6 lead dönüşümü
   "en küçük `position`'lı aktif aşama"ya düşer).
3. Aktif hiçbir aşamada `status='open'` + pasif aşama kombinasyonu oluşmaz — pasifleştirme
   açık kartları taşımadan tamamlanamaz.
4. `is_won`/`is_lost` bayrakları ve `slug` **oluşturduktan sonra değişmez**. Tarihsel kapalı
   deal'ları olan bir aşamanın anlamını değiştirmek rapor verisini geriye dönük bozar; ihtiyaç
   varsa yeni aşama açılır, eskisi pasifleştirilir. (`PATCH` gövdesinde bu alanlar → 422.)

### 2.3 Pasifleştirme — kural ve hata kodları

`POST .../{stage}/deactivate`, gövde: `{ "move_to_stage_id": int|null }`.

| Durum | HTTP | `code` |
| --- | --- | --- |
| Aşama zaten pasif | 422 | `STAGE_ALREADY_INACTIVE` |
| Açık kart var, `move_to_stage_id` yok | 422 | `STAGE_HAS_OPEN_DEALS` — `fields.open_deals_count` ile kart sayısı döner |
| Hedef yok / pasif / terminal (`is_won` veya `is_lost`) / kendisi | 422 | `STAGE_TARGET_INVALID` |
| Son aktif `is_won` ya da son aktif `is_lost` aşaması | 422 | `LAST_TERMINAL_STAGE` |
| Son aktif terminal-olmayan aşama | 422 | `LAST_ACTIVE_STAGE` |
| Açık kart yok | 200 | taşıma adımı atlanır, yalnız `is_active=false` |

Hedefin terminal olamaması bilinçlidir: açık kartları toplu olarak `is_won`/`is_lost` aşamasına
"süpürmek", Faz 7'nin durum makinesini (`lost_reason` zorunluluğu, `closed_at` yazımı) yüzlerce kartta
kullanıcı iradesi olmadan tetiklerdi. Kartlar yalnızca açık bir aşamaya taşınır; kazandı/kaybetti
kararı kart kart Kanban'da verilir.

### 2.4 Atomik toplu taşıma algoritması (Faz 7 `position`/`version` uyumlu)

Tek `DB::transaction` içinde:

1. Kaynak ve hedef `pipeline_stages` satırları `lockForUpdate` ile okunur; §2.3 doğrulamaları yapılır.
2. Kaynaktaki taşınacak kartlar seçilir: `pipeline_stage_id = kaynak AND status = 'open'`
   (soft-deleted hariç), `ORDER BY position ASC`, `lockForUpdate`. Kapalı kartlar (`won`/`lost`)
   ve soft-deleted kartlar **taşınmaz** — pasif aşamaya tarihsel referansları Faz 3 gereği geçerlidir.
3. Hedef aşamadaki **tüm** deal'lar (statü fark etmeksizin — `position` tekilliği aşama içindir)
   arasından en büyük `position` bulunur (`tail`).
4. Kartlar mevcut göreli sıraları korunarak hedefin **sonuna** eklenir: ilk kart için
   `FractionalIndex::between(tail, null)` (hedef boşsa `FractionalIndex::first()`), sonrakiler
   zincirleme bir öncekinin arkasına. Yalnızca `FractionalIndex` kullanılır; elle string üretimi yasak
   (R21 — küçük harf base36 alfabesi, sondaki `'0'` yasağı).
5. Her kart için tek UPDATE: `pipeline_stage_id = hedef`, `position = yeni`, **`version = version + 1`**,
   `probability` yalnızca `null` ise hedef aşamanınki devralınır (Faz 7 kuralı aynen; dolu olasılık
   ezilmez). `status` `open` kalır, `closed_at`/`won_reason`/`lost_reason` dokunulmaz.
6. Kaynak aşama `is_active = false` yapılır ve commit edilir.

**Eşzamanlılık:** Adım 1–2'deki satır kilitleri, aynı anda gelen tekil `PATCH /deals/{id}/move`
isteğiyle MySQL seviyesinde serileşir: tekil taşıma önce commit olduysa kart toplu seçime yeni
haliyle girer (ya da kaynaktan çıkmıştır ve hiç girmez); toplu taşıma önce commit olduysa uçuştaki
tekil istek bayat `version` taşır ve mevcut mekanizmayla **409 `DEAL_VERSION_CONFLICT`** + kartın
güncel hali alır. Yeni bir çakışma mekanizması İCAT EDİLMEZ — `version` artırımı (adım 5) bu
senaryoyu Faz 7'nin var olan yoluna düşürmek için yeterlidir.

**Audit:** Kart başına audit KAPALI; işlem sonunda tek özet kayıt (`pipeline_stages.deactivated`,
`properties`: kaynak/hedef id, taşınan kart sayısı) + `batch_uuid`. (Faz 6 `leads.imported` deseniyle
tutarlı — 500 kartlık taşıma 500 audit satırı üretmez.)

**Yayın (transaction dışında, commit sonrası — Faz 7 deseni):**

- Kanal: `private-deals` (mevcut, `deals.view` yetkili).
- Event: `PipelineStageDeactivated`, `broadcastAs()` = **`stage.deactivated`**.
- Payload (düz skaler): `{ stage_id, moved_to_stage_id, moved_count, actor_id, actor_name, occurred_at }`.
- İstemci davranışı: kart kart delta uygulamaya ÇALIŞILMAZ (toplu taşımada delta maliyetli ve hataya
  açık) — board query'si invalidate edilip yeniden çekilir. Tekil `deal.moved` deltası aynen kalır.

### 2.5 Sıralama, olasılık, diğer düzenlemeler

- **Yeniden sıralama:** `PATCH .../reorder`, gövde `{ "ordered_ids": [...] }`. Liste **tüm** aşamaları
  (aktif + pasif) tam olarak birer kez içermek zorunda; eksik/fazla/yinelenen id → 422
  `STAGE_REORDER_INCOMPLETE`. Transaction içinde `position = 1..N` yazılır. `pipeline_stages.position`
  tamsayıdır ve satır sayısı küçüktür — deal'lardaki fractional index burada GEREKMEZ; toplu yeniden
  numaralama bilinçli tercihtir.
- **Olasılık değişimi ileriye dönüktür:** aşamanın `probability`'si değişince mevcut deal'ların
  `probability`'sine DOKUNULMAZ (deal olasılığı Faz 7'de yalnız taşıma anında ve yalnız `null` ise
  devralınır — bu bir snapshot'tır, canlı bağ değildir; `quote_items.name` snapshot gerekçesiyle aynı).
- **Aktifleştirme** önkoşulsuz 200; sütun panoda (boş ya da eski kapalı kartlarının sayılarıyla) geri görünür.
- Ad/renk/olasılık/sıra değişimi sonrası `private-deals` kanalına `PipelineStageUpdated` /
  **`stage.updated`** yayınlanır, payload düz skaler `{ stage_id, occurred_at }`; istemci board ve
  aşama listesi query'lerini invalidate eder. (Faz 10 DoD: "pipeline aşaması değişince Kanban güncelleniyor".)

---

## 3. Özel Alan Yönetimi

### 3.1 Silme

- `DELETE /api/settings/custom-fields/{field}`:
  - `custom_field_values` sayısı **0** ise → 200, gerçek DELETE (FK cascade'in silecek çocuğu yoktur).
  - Değer varsa → **422 `CUSTOM_FIELD_HAS_VALUES`**, `fields.values_count` ile sayı döner. Mesaj
    kullanıcıyı pasifleştirmeye yönlendirir.
- **Uygulama katmanı guard'ı zorunludur ve şemadaki `cascadeOnDelete` bunun yerine geçmez.**
  Dikkat — R28'in tersi durum: `custom_fields` soft delete KULLANMADIĞI için buradaki `delete()`
  gerçek bir DELETE'tir ve cascade gerçekten tetiklenir. Guard atlanırsa veri kaybı sessiz ve geri
  alınamaz olur. FK şemada kalır (yalnızca boş-silme yolunda ve manuel DB operasyonlarında tutarlılık
  emniyeti), ama koruma sorumluluğu uygulamadadır.

### 3.2 Pasifleştirme (`is_active = false`) — varsayılan "kaldırma" yolu

Anlamı kesin olarak şudur:

- Önkoşulsuz, her zaman 200. Değerler olduğu gibi durur.
- Alan; oluşturma/düzenleme **formlarında görünmez**, validasyonda yer almaz, yeni değer **yazılamaz**
  (pasif alana değer yazma denemesi → 422 `CUSTOM_FIELD_INACTIVE`).
- Mevcut değerler kayıt **detay** ekranında "Pasif alanlar" bölümünde **salt okunur** görünür.
  Bu madde Faz 6'da tespit edilen "şemaya göre geçerli ama hiçbir ekranda görünmeyen, buna karşılık
  raporların saydığı satır" sınıfını kapatır: değer ya görünürdür ya da alan silinmiştir; "görünmez
  ama sayılır" durumu YASAKTIR. Rapor/filtre uçları pasif alanları seçenek olarak sunmaz.
- Yeniden aktifleştirme önkoşulsuzdur; değerler aynen geri görünür.

### 3.3 Tip değişimi

- Değeri olan alanda `type` değişikliği → **422 `CUSTOM_FIELD_TYPE_LOCKED`** (`fields.values_count` ile).
  `text → number` gibi bir dönüşümde mevcut değerlerin bir kısmı sessizce anlamsızlaşır — EAV'de
  `value` kolonu `text` olduğundan DB hata da vermez; bu, sessiz bozulmanın ta kendisidir. Doğru yol:
  yeni tanım aç, eskisini pasifleştir. (Otomatik değer dönüştürme aracı bilinçli olarak kapsam dışı — §7.)
- Değeri olmayan alanda `type` serbestçe değişir.
- **`key` ve `entity_type` her koşulda değişmezdir** (422). `key` programatik kimliktir; Faz 6 lead
  dönüşümü değer kopyalamayı `key`+`type` eşleşmesiyle yapar — `key` oynarsa bu eşleşme sessizce kopar.
- `name`, `position`, `is_required` serbesttir. `is_required=true` yapmak yalnızca **ileriye dönük**
  uygulanır: mevcut kayıtlar geriye dönük doldurulmaz/işaretlenmez; kayıt bir sonraki düzenlenişinde
  form bu alanı zorunlu ister.

### 3.4 `select` / `multiselect` seçenek çıkarma — uyarıyla geçer, veri silinmez

`PATCH` gövdesindeki yeni `options` listesi mevcutla karşılaştırılır; **çıkarılan** her seçenek için
o seçeneği taşıyan değer sayısı hesaplanır (`multiselect` değeri JSON dizi tuttuğu için üyelik
kontrolü SQL'de garantili üst küme + PHP'de kesin doğrulama yaklaşımıyla yapılır — Faz 6 telefon
eşleştirme deseni, ham SQL yasağına uygun).

- Çıkarılan seçenek(ler) kullanımda ve gövdede `confirm_option_removal: true` YOK →
  **422 `CUSTOM_FIELD_OPTION_IN_USE`**, `fields` içinde seçenek başına kullanım sayısı.
- `confirm_option_removal: true` ile → 200. **Mevcut değerlere dokunulmaz** — silinmez, null'lanmaz.
  Artık listede olmayan bir değeri taşıyan kayıt, detayda değerini "*(kaldırılmış seçenek)*"
  rozetiyle göstermeye devam eder; kayıt bir sonraki düzenlenişinde form geçerli bir seçenek
  seçilmesini ister. Filtrelerde kaldırılmış seçenek önerilmez.
- Seçenek **yeniden adlandırma**, sunucu açısından çıkarma + ekleme demektir (değer, seçeneğin
  string'ini taşır) ve aynı onay akışından geçer. UI bu gerçeği kullanıcıya açıkça söylemelidir.

**Veri kaybının kabul edildiği tek yer:** hiç değeri olmayan tanımın DELETE'i (kaybedilecek veri
yoktur). Diğer tüm yollar ya engellenir ya da veriyi koruyarak geçer.

---

## 4. Rol/İzin Matrisi

### 4.1 Değişikliğin yürürlüğü ve giriş yapmış kullanıcılar

- **Otorite her zaman backend'dir**: her istek Policy/Gate'ten geçer; matris değişikliği + önbellek
  temizliği sonrası ilk istekten itibaren yeni izinler uygulanır. Frontend guard yalnız UX'tir
  (ROADMAP global kriteriyle aynı cümle).
- **UI anlık güncellenir, oturum düşürülmez.** İzin kaybı, hesap pasifleştirmenin aksine oturum
  sonlandırma gerektirmez — `EnsureUserIsActive` deseninin kopyalanması burada aşırıdır.
- **Realtime sözleşme (mevcut desene uygun — kısa sabit ad, düz skaler payload):**
  - Event sınıfı: `UserPermissionsChanged`, kanal: **`private-user.{id}`** (mevcut), `broadcastAs()` =
    **`permissions.changed`**.
  - Payload: `{ user_id, message, occurred_at }` — izin listesi payload'da TAŞINMAZ; istemci
    `GET /api/me`'yi yeniden çekip `usePermission()` durumunu yeniden kurar (izin listesinin tek
    kaynağı `/api/me` kalır, iki kaynaklılık yaratılmaz).
  - Bir rolün matrisi değişince event, o rolü taşıyan **her aktif kullanıcıya** kuyruklu olarak
    (queued broadcast, commit sonrası) yayınlanır. Tek kullanıcının rol ataması değişince yalnız ona.
- Event kaçarsa (istemci offline, Reverb kesintisi) sistem yine doğrudur: bayat UI'ın tıklaması
  backend'den 403 alır; global axios interceptor 403'te `/api/me`'yi tazeler. 403 asla veri bozmaz,
  yalnızca kısa süreli kötü UX'tir — kabul edilir.

### 4.2 Kendini kilitleme korumaları (hepsi sunucuda, hepsi test edilir)

| Kural | HTTP | `code` |
| --- | --- | --- |
| Super Admin rolü matristen düzenlenemez/silinemez (gücü `Gate::before`'dan gelir, satırları anlamsızdır; "düzenlenebilir" görünmesi yanlış güven yaratır) | 422 | `ROLE_IMMUTABLE` |
| Aktör (Super Admin değilse) kendisini `users.manage_roles` veya `settings.manage` izninden edecek matris değişikliği yapamaz | 422 | `SELF_LOCKOUT` |
| Kullanıcı **kendi** rol atamasını değiştiremez (Faz 2 "kendi hesabını pasifleştirememe" ile aynı ilke) | 422 | `SELF_ROLE_CHANGE_FORBIDDEN` |
| Son **aktif** Super Admin'den Super Admin rolü alınamaz (Faz 2 `UserPolicy` koruması — matris/atama uçlarında da bağlayıcıdır) | 422 | `LAST_SUPER_ADMIN` |
| İzin sözlüğü koddan/seeder'dan gelir: UI'dan izin OLUŞTURULAMAZ/SİLİNEMEZ; bilinmeyen izin adı | 422 | alan hatası (`fields.permissions`) |

Not: `users.manage_roles` izninin bir rolden kaldırılması, o roldeki *diğer* kullanıcılar için
serbesttir (`SELF_LOCKOUT` yalnız aktörü korur) — sistemde her zaman en az bir aktif Super Admin
olduğu Faz 2'de garanti edildiğinden tam kilitlenme zaten imkânsızdır.

### 4.3 Spatie izin önbelleği (`PermissionRegistrar`)

- `app(PermissionRegistrar::class)->forgetCachedPermissions()` şu yazımların **hepsinden sonra,
  transaction commit'ini takiben** çağrılır: matris güncelleme (`role_has_permissions`), kullanıcıya
  rol atama/çıkarma, rol oluşturma/silme.
- Bu çağrı servis katmanında tek bir noktada toplanır (ör. `RoleMatrixService` içinde); controller'lara
  serpiştirilmez. `UserResource`'un izinleri `PermissionRegistrar::getPermissions()` üzerinden okuduğu
  unutulmamalıdır — önbellek temizlenmezse `/api/me` bayat izin döndürür ve realtime tazeleme işe yaramaz.
- Broadcast, önbellek temizliğinden **sonra** kuyruğa girer (istemci `/api/me`'yi çektiğinde taze
  veri bulacağı garanti olsun).

### 4.4 Uç ve kapsam

- `GET /api/settings/roles` (roller + izin matrisi + rol başına kullanıcı sayısı),
  `PUT /api/settings/roles/{role}/permissions` gövde `{ "permissions": ["deals.create", ...] }` —
  tam liste anlamlıdır (replace semantiği), delta değil. Yetki: `users.manage_roles`.
- Matris yalnız **rol → izin** eşlemesini düzenler. Kullanıcıya doğrudan izin
  (`model_has_permissions`) bu ekrandan verilmez/alınmaz (§7).

---

## 5. Şema Değişikliği

**Mevcut şema yeterlidir — bu tur için yeni kolon/tablo YOK.**

- S1: Pasifleştirme `is_active` ile, taşıma mevcut `position`/`version` kolonlarıyla, denetim izi
  `activity_log` (+`batch_uuid`) ile karşılanır. Ayrı bir `deactivated_at` kolonu gerekmez — bilgi
  audit'te var.
- S2: Tüm kurallar uygulama katmanı guard'larıdır; `cascadeOnDelete` FK'sı bilinçli olarak yerinde
  bırakılır (§3.1'deki uyarıyla).
- S3: spatie tabloları ve `private-user.{id}` kanalı yeterli.
- Tek şerh: ileride kaldırılmış `select` seçeneklerinin *toplu* raporlanması istenirse
  `custom_field_values.value` üzerinde normalize edilmiş bir arama kolonu düşünülebilir — bu tur
  kapsam dışıdır ve mevcut kurallar onsuz doğru çalışır.

---

## 6. Kabul Kriterleri

### S1 — Pipeline aşama editörü

1. 3 açık, 2 kapalı (`won`/`lost`) kartı olan bir aşama `move_to_stage_id` olmadan pasifleştirilmek
   istendiğinde yanıt 422 `STAGE_HAS_OPEN_DEALS` ve `fields.open_deals_count = 3` olur; hiçbir kart taşınmaz.
2. Aynı aşama geçerli bir hedefle pasifleştirildiğinde: 3 açık kart hedef aşamanın **sonuna**, kaynaktaki
   göreli sıraları korunarak taşınır; her taşınan kartın `version` değeri tam 1 artar; 2 kapalı kart
   pasif aşamada kalır ve `GET /api/deals` + raporlarda görünmeye devam eder; `GET /api/deals/board`
   pasif sütunu döndürmez ve taşınan kartlar hedef sütunda görünür.
3. Toplu taşıma commit'inden önce alınmış `version` ile gönderilen tekil `PATCH /deals/{id}/move`
   isteği 409 `DEAL_VERSION_CONFLICT` alır ve kartın güncel (hedef aşamadaki) hali döner; istek uygulanmaz.
4. Seed'deki tek `is_won` aşaması pasifleştirilmek istendiğinde 422 `LAST_TERMINAL_STAGE`; tek kalan
   aktif terminal-olmayan aşama için 422 `LAST_ACTIVE_STAGE`; hedef olarak pasif/terminal/kendisi
   verildiğinde 422 `STAGE_TARGET_INVALID`.
5. Mevcut bir aşamanın `PATCH` gövdesinde `is_won`, `is_lost` veya `slug` gönderilirse 422 döner ve
   değer değişmez.
6. Aşama olasılığı %20'den %60'a çekildiğinde o aşamadaki mevcut deal'ların `probability` değeri
   değişmez; değişiklikten sonra bu aşamaya taşınan `probability=null` bir kart %60 devralır.
7. Pasifleştirme sonrası `private-deals` kanalına tek bir `stage.deactivated` eventi düşer (kart başına
   `deal.moved` yayınlanmaz) ve audit'te kart başına değil tek özet kayıt oluşur.
8. Reorder ucu, tüm aşama id'lerini içermeyen bir listeye 422 `STAGE_REORDER_INCOMPLETE` döner.

### S2 — Özel alan yönetimi

1. 5 değeri olan bir tanım için DELETE → 422 `CUSTOM_FIELD_HAS_VALUES` (`fields.values_count = 5`);
   `custom_field_values` satır sayısı değişmez. 0 değerli tanım için DELETE → 200 ve tanım DB'den silinir.
2. Pasifleştirilen alan: ilgili entity'nin oluştur/düzenle formu şemasından çıkar, o alana değer yazma
   isteği 422 `CUSTOM_FIELD_INACTIVE` alır, mevcut değer kayıt detayında salt okunur görünmeye devam
   eder; yeniden aktifleştirmede değer aynen geri gelir.
3. Değeri olan `text` alanı `number`'a çevrilmek istendiğinde 422 `CUSTOM_FIELD_TYPE_LOCKED`; değeri
   olmayan alanda aynı istek 200 döner. `key` veya `entity_type` içeren her PATCH değeri olsun olmasın 422.
4. Kullanımda olan bir `select` seçeneği `confirm_option_removal` olmadan çıkarılınca 422
   `CUSTOM_FIELD_OPTION_IN_USE` ve seçenek başına kullanım sayısı döner; `confirm_option_removal: true`
   ile 200 döner, o seçeneği taşıyan `custom_field_values` satırları silinmez ve değerleri değişmez.
5. `multiselect` alanında yalnızca dizinin bir elemanı olarak geçen seçenek de "kullanımda" sayılır
   (JSON dizi üyeliği, substring eşleşmesi değil).
6. `is_required=true` yapılan alan: mevcut kayıtlar listede/detayda hatasız açılmaya devam eder;
   yalnızca sonraki create/update validasyonu alanı zorunlu kılar.

### S3 — Rol/izin matrisi

1. Bir rolden `deals.create` kaldırıldığında: o roldeki giriş yapmış kullanıcının bir sonraki
   `POST /api/deals` isteği 403 alır (UI durumundan bağımsız) ve `private-user.{id}` kanalına
   `permissions.changed` eventi düşer; event sonrası çekilen `GET /api/me` yeni izin listesini döner.
2. Matris güncellemesi `PermissionRegistrar` önbelleğini temizler: değişiklikten hemen sonra (yeni
   login olmadan) çağrılan `/api/me`, güncellenmiş izinleri içerir — bayat önbellek dönmez.
3. Super Admin rolünün matrisini değiştirme denemesi 422 `ROLE_IMMUTABLE`; Super Admin olmayan aktörün
   kendi rolünden `users.manage_roles` iznini kaldırma denemesi 422 `SELF_LOCKOUT`; her iki durumda da
   `role_has_permissions` değişmez.
4. Kullanıcının kendi rol atamasını değiştirme denemesi 422 `SELF_ROLE_CHANGE_FORBIDDEN`; sistemdeki
   son aktif Super Admin'den rolü alma denemesi 422 `LAST_SUPER_ADMIN`.
5. Matris değişikliği oturum düşürmez: etkilenen kullanıcının session'ı ve `EnsureUserIsActive` akışı
   dokunulmadan kalır; kullanıcı logout olmadan yeni izinlerle çalışmaya devam eder.
6. Bilinmeyen izin adı içeren `PUT .../permissions` isteği 422 alan hatası döner ve hiçbir izin değişmez
   (istek atomiktir — kısmî uygulama yok).

---

## 7. Kapsam Dışı (bu turda yapılmayacaklar)

| Konu | Neden |
| --- | --- |
| Pipeline aşaması için DELETE ucu (boş aşama dahil) | Faz 3 kararı bağlayıcı: silme yerine pasifleştirme. Boş görünen aşamada bile kapalı/soft-deleted deal referansı olabilir; `restrictOnDelete` DB emniyeti olarak yeterli, uç hiç açılmaz. |
| Toplu taşımada kartları hedefin "arasına" yerleştirme / hedef içi konum seçimi | Sona ekleme deterministik ve çakışmasızdır; ince yerleştirme zaten Kanban'da tek tek yapılabilir. |
| Özel alan tip dönüştürme sihirbazı (`text→number` değer migrasyonu) | Kısmen dönüşemeyen değerler için politika (at/sıfırla/işaretle) ayrı bir tasarım ister; yanlış yapılırsa sessiz bozulmanın kendisidir. Yeni alan + pasifleştirme yolu ihtiyacı karşılar. |
| Kaldırılmış `select` seçeneklerini mevcut kayıtlarda toplu değiştirme aracı | Aynı gerekçe — veri düzeltme, ayar ekranının değil bilinçli bir toplu düzenleme aracının işidir. |
| Kullanıcıya doğrudan izin atama UI'ı (`model_has_permissions`) | Yetki modeli rol üzerinden okunabilir kalmalı; kullanıcı-bazlı istisnalar matris ekranının denetlenebilirliğini bozar. İhtiyaç doğarsa ayrı karar. |
| UI'dan yeni rol/izin oluşturma-silme | İzin sözlüğü kodla (`RolePermissionSeeder` + Policy'ler) yaşar; UI'dan üretilen izni hiçbir Policy kontrol etmez — ölü izin yaratır. 6 seed rolün matrisi düzenlenir, sözlük koddan evrilir. |
| İzin değişiminde zorla logout / session revoke | Backend her istekte otorite; revoke yalnızca hesap pasifleştirme (güvenlik olayı) için ayrılmıştır (Faz 2). |
| E-posta şablonları ve şirket profili güvenlik kuralları | Bu iki alt modül mevcut canlı veriyi etkilemez (şablon render'ı gönderim anında, profil düz `settings` key/value) — bu dokümanın tehdit modeline girmez. |

---

## 8. Mevcut Kararlarla Tutarlılık Notları

- **Faz 3 "aşama silinmez, pasifleştirilir"** — aynen korunur; bu doküman Faz 3'ün açık bıraktığı
  boşluğu (pasifleştirmenin AÇIK kartları panodan sessizce düşürmesi) kapatır. Çelişki değil tamamlamadır:
  Faz 3'ün "geçmiş deal'lar pasif aşamaya referans verebilir" cümlesi kapalı kartlar için geçerli
  kalır; açık kartlar için "sessizce kaybolma" artık imkânsızdır.
- **Faz 7 optimistic locking** — toplu taşıma `version` artırımı ve satır kilidiyle aynı mekanizmaya
  bağlanır; 409 sözleşmesi (`DEAL_VERSION_CONFLICT` + güncel kart) değişmeden kullanılır.
- **Faz 9 / R28 "soft delete çocukları korur"** — `custom_fields`'ta soft delete OLMADIĞI için R28'in
  koruması burada yoktur; eşdeğer koruma §3.1'deki uygulama guard'ıyla sağlanır. Bu, R28 ile çelişki
  değil, R28'in "soft delete yoksa cascade gerçekten yıkıcıdır" çıkarımının uygulamasıdır.
- **Faz 2 kendini-koruma kuralları** (`UserPolicy`: kendi hesabını pasifleştirememe, son aktif Super
  Admin koruması) — §4.2'de matris/atama uçlarına genişletilir, gevşetilmez.
- Yeni hata kodları mevcut adlandırma diliyle uyumludur (`SCREAMING_SNAKE`, kaynak öneki:
  `STAGE_*`, `CUSTOM_FIELD_*`, `ROLE_*` — `DEAL_VERSION_CONFLICT`/`QUOTE_LOCKED` deseni).
- Yeni event adları mevcut sözlükle uyumludur: kısa sabit ad (`stage.deactivated`, `stage.updated`,
  `permissions.changed` ↔ `deal.moved`, `user.deactivated`), düz skaler payload, kuyruklu yayın,
  commit sonrası, mevcut kanallar (`private-deals`, `private-user.{id}`) — yeni kanal açılmaz.
