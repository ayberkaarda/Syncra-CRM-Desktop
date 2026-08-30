# SLA Tasarım Sözleşmesi (Tickets — Faz 8)

> **Bağlayıcı sözleşmedir.** Faz 8'de `app/Services/Tickets/SlaService.php` ve ticket durum akışını
> uygulayacak tüm şeritler bu dokümana uyar. Çelişki görürsen kodu değil önce bu dokümanı sorgula;
> değişiklik ancak teknik lider onayıyla buraya işlenerek yapılır.
>
> İlgili kaynaklar: `backend/database/migrations/2026_08_23_100008_create_tickets_table.php`,
> `backend/database/seeders/SettingSeeder.php` (`ticket.sla_hours_*`),
> `backend/database/seeders/DemoDataSeeder.php` (`seedTickets()`), `docs/DATABASE.md` §2.2,
> `docs/ROADMAP.md` Faz 8.

---

## 1. Karar Özeti

**SLA, çözüm süresini ölçer: tek hedef, tek `sla_due_at`.** İlk yanıt süresi (`first_response_at`)
kayıt altına alınır ve raporlanır ama bu fazda ayrı bir SLA hedefi taşımaz. Sayaç **`pending`
durumunda durur** — bunun için `tickets` tablosuna duraklama kolonları eklenir (yeni migration,
bkz. §3). İhlal **kalıcı bayrak değil, türetilmiş değerdir**; filtreleme ham SQL'siz Eloquent
koşullarıyla yapılır. `sla_due_at` oluşturmada o anki ayarlardan hesaplanıp sabitlenir; **öncelik
değişince yeniden hesaplanır** (taban her zaman `created_at` + birikmiş duraklama). Çalışma
saatleri/tatil takvimi bilinçli olarak **kapsam dışıdır** — SLA takvim saatiyle işler ve bu
bilinen bir sınırdır. İhlal ticket'a yazılır, kullanıcıya değil; geri sayımı **her zaman sunucu
hesaplar**, istemci yalnızca sunucudan aldığı kalan saniyeyi monoton saatle eritir.

---

## 2. Ölçüm Tanımı

**SLA = ticket'ın oluşturulmasından çözülmesine kadar geçen, duraklama düşülmüş takvim süresi.**

| Olay | SLA sayacına etkisi |
| --- | --- |
| Ticket oluşturuldu (`created_at`) | Sayaç **başlar**. `sla_due_at = created_at + ayarlardaki saat` |
| Durum → `pending` | Sayaç **durur** (`sla_paused_at = now()`) |
| `pending`'den çıkış (`open`/`in_progress`/`resolved`) | Sayaç **devam eder**; duraklama süresi `sla_paused_seconds`'a eklenir ve `sla_due_at` aynı süre kadar ileri kayar |
| Durum → `resolved` (`resolved_at = now()`) | Sayaç **biter**. `resolved_at ≤ sla_due_at` ise SLA karşılandı, değilse tarihsel ihlal |
| `resolved` → `open` (yeniden açma) | Sayaç **devam eder**; `resolved` durumda geçen süre duraklama gibi işlenir (bkz. §4) |
| Durum → `closed` | SLA'ya etkisi yok (SLA zaten `resolved`'da bitmiştir) |
| Öncelik değişti | Hedef yeniden hesaplanır (bkz. §5), sayaç durmaz |
| Ticket devri (`assigned_to` değişimi) | **Etkisi yok** — sayaç ticket'ındır, kişinin değil (bkz. altta) |

**`first_response_at`:** Yalnızca metriktir, SLA hedefi değildir. İlk kez şu iki olaydan biri
gerçekleştiğinde `now()` yazılır ve **bir daha asla değiştirilmez**:
(a) ticket'a `call | email | meeting` tipli bir `Activity` (morph `activityable`) eklenmesi —
`note` sayılmaz, iç nottur; (b) ilk `open → in_progress` geçişi. Faz 11 "ilk yanıt süresi"
raporunu `first_response_at - created_at` ile üretir. İki alan + tek `sla_due_at` görünümü
tutarsızlık değil, bilinçli sadeliktir: kapalı devre CRM'de müşteri portalı yok, "ilk yanıt"
müşterinin gördüğü bir taahhüt değil iç metriktir; iki ayrı SLA hedefi (iki due, iki ihlal
bayrağı, iki bildirim akışı) bu fazda karşılığı olmayan karmaşıklıktır. İleride ilk-yanıt SLA'sı
gerekirse `first_response_due_at` kolonu eklenerek aynı makineyle kurulur.

**Sorumluluk (soru 6):** İhlal **ticket'a** aittir; `users` tablosuna veya ayrı bir tabloya ihlal
yazılmaz. Faz 11 "kullanıcı performansı" raporu ihlali **çözüm anındaki (açıksa mevcut)
`assigned_to`** kullanıcısına sayar; `assigned_to` null ise "atanmamış" kovasına düşer. Ticket
devredildiğinde önceki kişiye hiçbir şey yazılmaz ve sayaç sıfırlanmaz — müşteri taahhüdü devirle
uzamaz. Atama geçmişi zaten `activity_log`'da (`properties.old/attributes.assigned_to`) durur;
Faz 11 daha ince kişi-bazlı atıf isterse oradan türetir, yeni şema gerekmez.

---

## 3. Şema Değişikliği (GEREKLİ — yeni migration)

Faz 8 başında tek migration: `add_sla_columns_to_tickets_table`. Mevcut kolonlara dokunulmaz.

| Kolon | Tip | Null | Varsayılan | Amaç |
| --- | --- | --- | --- | --- |
| `sla_paused_at` | dateTime | evet | null | Aktif duraklamanın başlangıcı. Null ise sayaç akıyor. Doludur ⇔ `status = 'pending'` (uygulama katmanı invariant'ı) |
| `sla_paused_seconds` | unsignedInteger | hayır | 0 | Birikmiş toplam duraklama (kapanmış duraklamalar + yeniden açmadaki `resolved` boşlukları). Rapor + yeniden hesap tabanı |
| `sla_warning_notified_at` | dateTime | evet | null | "İhlale yaklaşıyor" bildirimi bir kez üretilsin diye idempotency damgası |
| `sla_breach_notified_at` | dateTime | evet | null | "İhlal edildi" bildirimi için idempotency damgası |

Gerekçe: duraklamasız model yeni kolon istemezdi ama `pending`'de akan sayaç, temsilciyi
müşterinin gecikmesinden dolayı ihlalde gösterir ve metriği ekip değerlendirmesi için kullanılmaz
kılar — SLA'nın varlık sebebi ortadan kalkar. Faz 8 başlamadığı için şema maliyeti bugün en
düşük seviyededir. Bildirim damgaları, `tickets:scan-sla` tarayıcısının (bkz. §5) yeniden
başlatmalarda ve her 5 dk'lık turda aynı bildirimi tekrar üretmemesi içindir; Redis'te tutmak
uçucudur, `notifications` tablosunda arama ise her turda ek sorgu + kırılgan eşleştirmedir.

Yeni index gerekmez: tarayıcı ve filtreler mevcut `sla_due_at` + `status` index'lerini kullanır.
DİKKAT (ROADMAP R12): kolon metotları FK/davranış metotlarından önce zincirlenir.

`docs/DATABASE.md` §2.2 `tickets` tablosu bu dört kolonla güncellenmelidir (implementasyon
sırasında, bu dokümanla aynı PR'da).

---

## 4. Durum Makinesi

Durumlar: `open | pending | in_progress | resolved | closed`. Geçerli geçişler **yalnızca**
şunlardır (satır = kaynak, listelenmeyen her hedef geçersizdir; aynı duruma geçiş de geçersizdir):

| Geçiş | SLA etkisi | Yan etkiler |
| --- | --- | --- |
| `open → in_progress` | devam (etkisiz) | `first_response_at` null ise `now()` |
| `open → pending` | **durdur**: `sla_paused_at = now()` | — |
| `open → resolved` | **bitir**: `resolved_at = now()` | — |
| `in_progress → pending` | **durdur**: `sla_paused_at = now()` | — |
| `in_progress → open` | devam (etkisiz) | geri alma/atama kaldırma senaryosu |
| `in_progress → resolved` | **bitir**: `resolved_at = now()` | — |
| `pending → open` | **devam ettir**: `d = now() - sla_paused_at`; `sla_paused_seconds += d`; `sla_due_at += d`; `sla_paused_at = null` | — |
| `pending → in_progress` | **devam ettir** (aynı formül) | `first_response_at` null ise `now()` |
| `pending → resolved` | **devam ettir** + **bitir** (önce duraklama kapatılır, sonra `resolved_at = now()`) | — |
| `resolved → closed` | etkisiz | `closed_at = now()`. **Elle yapılır** — otomatik kapama bu fazda yok (bkz. §7) |
| `resolved → open` | **yeniden aç**: `g = now() - resolved_at`; `sla_paused_seconds += g`; `sla_due_at += g`; `resolved_at = null` | çözümde geçen süre duraklama sayılır — yeniden açılan ticket sırf rafta bekledi diye anında ihlale düşmez |
| `closed → *` | — | **closed terminaldir.** Yeniden açılamaz; aynı konu tekrar ederse yeni ticket açılır. Gerekçe: kapanmış dönem raporları (Faz 11) geriye dönük değişmez kalır |

**Uygulama:**
- Durum değişikliği **yalnızca** özel uçtan yapılır: `PATCH /api/tickets/{id}/status`, gövde
  `{ "status": "<hedef>" }`. Genel `PATCH /api/tickets/{id}` gövdesinde `status`, `sla_due_at`,
  `sla_paused_at`, `sla_paused_seconds`, `first_response_at`, `resolved_at`, `closed_at`
  alanlarını **422 ile reddeder** (Faz 7'deki `/move` deseninin aynısı).
- Geçersiz geçiş: **HTTP 422**, hata zarfı ROADMAP standardında:
  `{ "errors": { "message": "Bu durum geçişine izin verilmiyor: closed → open.",
  "code": "INVALID_STATUS_TRANSITION", "fields": { "status": ["..."] } } }`
- Geçiş tablosu tek yerde yaşar: `app/Services/Tickets/TicketStatusService.php` içinde sabit bir
  `const TRANSITIONS = ['open' => ['in_progress','pending','resolved'], ...]` haritası; SLA yan
  etkileri `SlaService`'e delegedir. Geçiş + SLA alan güncellemeleri tek `DB::transaction` +
  `lockForUpdate` içinde yapılır (eşzamanlı iki durum isteğinde ikincisi güncel durumdan
  doğrulanır; bayatsa 422 alır).
- Tüm geçişler `activity_log`'a zaten düşer (spatie, `status` dirty); ayrıca `TicketUpdated`
  eventi `private-tickets` kanalına yayınlanır (Faz 7 `private-deals` deseni, `toOthers()`).

---

## 5. Hesaplama Kuralları

**Sistem invariant'ı (her an doğru olmalı):**
`sla_due_at = created_at + hedef_saat(priority) + sla_paused_seconds` *(saniye hassasiyetinde; aktif duraklama henüz eklenmemiştir — çıkışta eklenir)*.

1. **Oluşturmada:** `sla_due_at = created_at + settings("ticket.sla_hours_{priority}") saat`.
   Ayar değeri oluşturma anında okunur ve **sabitlenir**; ayarlar sonradan değişirse mevcut
   ticket'ların `sla_due_at`'i **değişmez**, yalnız yeni ticket'lar yeni değeri alır.
2. **Öncelik değişiminde — yeniden hesaplanır:**
   `sla_due_at = created_at + hedef_saat(yeni_priority) + sla_paused_seconds`.
   `normal → urgent` yükseltmesinde 48 saatlik hedef 4 saate iner ve ticket **anında ihlale
   düşebilir — bu istenen davranıştır**: aciliyet gerçeği sonradan anlaşıldıysa taahhüt baştan
   beri 4 saatti; hedefi korumak "acile çekip rahat rahat 48 saat kullanma" kaçağı açardı.
   Düşürmede hedef aynı formülle uzar. Her öncelik değişiminde `sla_warning_notified_at` ve
   `sla_breach_notified_at` **null'a çekilir** (yeni hedefe göre bildirimler yeniden kurulur).
   Duraklama sürerken öncelik değişirse formül aynen uygulanır; aktif duraklama çıkışta yine
   `sla_due_at`'e eklenir.
3. **İhlal (aktif) — türetilmiş değerdir, kalıcı bayrak yoktur:**
   - Sayaç akarken: `resolved_at IS NULL AND sla_paused_at IS NULL AND sla_due_at < now()`
   - Duraklamadayken: `resolved_at IS NULL AND sla_paused_at IS NOT NULL AND sla_due_at < sla_paused_at`
     *(donmuş kalan süre negatifse — yani duraklamaya zaten ihlaldeyken girildiyse — ihlal
     duraklamayla "iyileşmez"; duraklamaya pozitif kalanla girildiyse duvar saati due'yu geçse
     bile ihlal SAYILMAZ, çünkü çıkışta `sla_due_at` duraklama kadar kayacaktır.)*
   - **Tarihsel ihlal** (raporlama): `resolved_at IS NOT NULL AND resolved_at > sla_due_at`.
   Neden bayrak değil: türetilmiş değer her an doğrudur; bayrak ise duraklama/öncelik değişimi/
   yeniden açma senaryolarının her birinde senkron tutulması gereken ikinci bir doğruluk kaynağı
   yaratır ve tarayıcı gecikmesinde yanlış negatif üretir.
4. **Filtreleme/sıralama:** `GET /api/tickets?filter[sla_breached]=1` → yukarıdaki **aktif ihlal**
   koşulu, ham SQL'siz (`where` grupları + `whereColumn('sla_due_at','<','sla_paused_at')`).
   `sort=sla_due_at` mevcut index'le çalışır ve "en acil önce" görünümünü verir (duraklamadaki
   ticket'ların araya karışması kabul edilmiş bir yaklaşıklıktır; hedefe göre mutlak sıra için
   yeterlidir).
5. **Tarayıcı:** `php artisan tickets:scan-sla` — schedule'da **her 5 dakikada** koşar.
   Kapsam: `resolved_at IS NULL AND sla_paused_at IS NULL` (duraklamadaki ticket'a bildirim
   üretilmez). İki eşik:
   - **Uyarı:** kalan süre hedefin (`sla_due_at - created_at - sla_paused_seconds`) **%20'sinin
     altına** indiğinde ve `sla_warning_notified_at IS NULL` → `TicketSlaWarning` eventi,
     damga yazılır.
   - **İhlal:** `sla_due_at < now()` ve `sla_breach_notified_at IS NULL` → `TicketSlaBreached`
     eventi, damga yazılır.
   Eventler Faz 8'de üretilir, Faz 10'da bildirime dönüşür (ROADMAP Faz 8 DoD). Sorgular
   `sla_due_at` index'ini kullanır.

---

## 6. API Sözleşmesi

`TicketResource` (tekil ve liste) SLA alanları — isimler ve tipler bağlayıcıdır:

| Alan | Tip | Açıklama |
| --- | --- | --- |
| `sla_due_at` | string (ISO 8601, UTC) \| null | Etkin hedef an. Yalnızca mutlak tarih GÖSTERİMİ için; istemci bununla aritmetik yapmaz |
| `sla_total_seconds` | integer | Hedef süre, ticket'ın KENDİ taahhüdünden türetilir: `sla_due_at - created_at - sla_paused_seconds` (§5 invariant'ının yeniden düzenlenmiş hâli; §5.5'in "hedef" tanımıyla aynı formül). İlerleme çubuğu paydası. `sla_due_at` yoksa `hedef_saat(priority) * 3600`'e düşülür |
| `sla_remaining_seconds` | integer \| null | **Sunucuda, yanıt üretilirken hesaplanır.** Akarken `sla_due_at - now()`; duraklamada `sla_due_at - sla_paused_at` (donmuş değer). Negatif = aşılmış. `resolved/closed` sonrası `null` |
| `sla_paused` | boolean | `status === 'pending'` (≡ `sla_paused_at !== null`) |
| `sla_breached` | boolean | Açık ticket'ta aktif ihlal; çözülmüşte tarihsel ihlal (`resolved_at > sla_due_at`). §5.3 kuralları |
| `sla_paused_seconds` | integer | Birikmiş duraklama (rapor/tooltip) |
| `first_response_at` / `resolved_at` / `closed_at` | string (ISO 8601) \| null | Mevcut alanlar, olduğu gibi |

> **`sla_total_seconds` neden ayardan okunmaz (Faz 8 implementasyon düzeltmesi).** Ayarlar
> değiştiğinde `hedef_saat(priority) * 3600` oynar ama mevcut ticket'ın `sla_due_at`'i §5.1
> gereği SABİT kalır — payda ticket'ın gerçek taahhüdüyle çelişir ve ilerleme çubuğu yanlış
> dolar (kabul kriteri 2 ile doğrudan gerilim). Ayrıca ayardan okumak liste yanıtında TICKET
> BAŞINA bir `settings` sorgusu demektir (N+1); türetilmiş biçim satırın zaten yüklü
> alanlarını kullanır ve sıfır ek sorgu maliyeti taşır. Ayarlar değişmediği sürece iki tanım
> birebir aynı sayıyı verir.

**İstemci geri sayım kuralı (soru 7):** İstemci saati **hiçbir hesapta kullanılmaz** —
`Date.now()` ile `sla_due_at` asla karşılaştırılmaz (kullanıcının saati yanlışsa ihlal yanlış
görünürdü). Doğru desen:
1. Yanıt geldiği an `t0 = performance.now()` (monoton saat) ve `r0 = sla_remaining_seconds`
   kaydedilir.
2. Ekrandaki sayaç her saniye `r0 - (performance.now() - t0)/1000` gösterir;
   `sla_paused === true` ise sayaç `r0`'da donuk kalır.
3. Sıfırın altına inince arayüz "aşıldı" durumuna döner ama **ihlal gerçeği sunucunundur**:
   TanStack Query 60 sn'de bir refetch + `private-tickets` kanalından gelen `TicketUpdated`
   eventi ile `r0/t0` yeniden senkronlanır. Böylece uyku/sekme askıya alma sonrası sapma da
   düzelir.

Liste ucu Faz 6 standardına uyar (`?page&per_page&sort&q&filter[...]`); SLA'ya özel:
`filter[sla_breached]=1`, `sort=sla_due_at` / `sort=-sla_due_at`.

---

## 7. Kapsam Dışı (bu turda YAPILMAYACAKLAR)

| Konu | Neden dışarıda |
| --- | --- |
| **Çalışma saatleri / tatil takvimi** | SLA **takvim saatiyle** işler. Cuma 17:00'de açılan `urgent` ticket'ın Pazartesi sabahı ihlalde görünmesi **bilinen ve kabul edilmiş bir sınırdır**. İş-saati hesabı; mesai tanımı, resmi tatil tablosu, saat dilimi kuralları ve tüm süre aritmetiğinin takvim-farkındalı sürümünü gerektirir — ayarlarda karşılığı olmayan (yalnız `sla_hours_*` var), kapalı devre iç kullanım için bu fazda maliyeti faydasını aşan bir katmandır. Eklenmek istenirse tek dokunuş noktası `SlaService` içindeki süre aritmetiğidir |
| **Çoklu SLA politikası** (müşteriye/kategoriye/firmaya özel hedefler) | Tek küresel politika: `ticket.sla_hours_*` ayarları. Politika tablosu + eşleştirme kuralları ayrı bir tasarım turudur |
| **İlk yanıt SLA'sı** | `first_response_at` metrik olarak tutulur ama hedefi/ihlali yoktur (gerekçe §2). Gelecekte `first_response_due_at` ile eklenebilir |
| **Otomatik `resolved → closed`** | Kapama elle yapılır. Otomatik kapama (`ticket.auto_close_days` ayarı + zamanlanmış job) müşteri portalı olmayan sistemde aciliyet taşımaz; Faz 10 Settings turunda değerlendirilebilir |
| **SLA ihlal geçmişi tablosu / kişi bazlı ihlal kaydı** | İhlal türetilmiştir, ticket'a aittir; kişi atfı Faz 11'de `assigned_to` + `activity_log` üzerinden yapılır (§2) |
| **Duraklama gerekçesi / çoklu duraklama dökümü** | Yalnız toplam (`sla_paused_seconds`) tutulur; tek tek duraklama aralıkları `activity_log`'daki durum geçişlerinden zaten türetilebilir |

---

## 8. Kabul Kriterleri (test edilebilir)

Zaman kontrolü gerektiren tüm testler `travel()`/`Carbon::setTestNow()` kullanır.

1. `priority=urgent` ile oluşturulan ticket'ın `sla_due_at` değeri `created_at + 4 saat` olur
   (`low`→72, `normal`→48, `high`→24 için aynı şekilde, değerler `settings`'ten okunur).
2. `ticket.sla_hours_urgent` ayarı 4'ten 8'e çekildiğinde mevcut ticket'ların `sla_due_at`'i
   değişmez; yeni oluşturulan `urgent` ticket `created_at + 8 saat` alır.
3. `open → pending` geçişi `sla_paused_at`'i doldurur; yanıtta `sla_paused=true` döner ve
   1 saat ileri sarılıp tekrar okunduğunda `sla_remaining_seconds` aynı (donmuş) değerdir.
4. 2 saat `pending`'de kalıp `in_progress`'e dönen ticket'ta: `sla_paused_at=null`,
   `sla_paused_seconds=7200` ve `sla_due_at` tam 2 saat ileri kaymıştır.
5. Duraklamaya **pozitif** kalan süreyle giren ticket, duvar saati eski `sla_due_at`'i geçse
   bile `sla_breached=false` kalır; duraklamaya **zaten ihlaldeyken** giren ticket
   `sla_breached=true` kalır (duraklama ihlali iyileştirmez).
6. `normal` (48s) bir ticket 6. saatte `urgent`'a çekildiğinde `sla_due_at`
   `created_at + 4 saat + sla_paused_seconds` olur → `sla_breached=true` (anında ihlal) ve
   `sla_warning_notified_at`/`sla_breach_notified_at` null'a döner.
7. `resolved_at <= sla_due_at` ile çözülen ticket `sla_breached=false`;
   `resolved_at > sla_due_at` ile çözülen `sla_breached=true` (tarihsel) döner ve her iki
   durumda `sla_remaining_seconds=null` olur.
8. `resolved → open` yeniden açmasında `resolved_at` null'a döner, `sla_due_at` çözümde geçen
   süre kadar kayar ve ticket sırf beklediği için ihlale düşmez (kalan süre, çözüm anındaki
   kalanla aynıdır).
9. `closed` ticket'a herhangi bir durum geçişi isteği **422** + `code=INVALID_STATUS_TRANSITION`
   döner; §4 tablosunda olmayan tüm diğer geçişler (ör. `open → closed`, `resolved → pending`,
   aynı duruma geçiş) de aynı şekilde reddedilir.
10. Genel `PATCH /api/tickets/{id}` gövdesinde `status` veya herhangi bir SLA alanı
    (`sla_due_at`, `sla_paused_seconds`, `resolved_at` ...) **422** ile reddedilir; durum
    yalnızca `PATCH /api/tickets/{id}/status` ile değişir.
11. `first_response_at` ilk `open → in_progress` geçişinde YA DA ticket'a ilk
    `call|email|meeting` aktivitesi eklendiğinde (hangisi önceyse) bir kez yazılır; sonraki
    geçiş ve aktiviteler değeri değiştirmez; `note` tipli aktivite tetiklemez.
12. `GET /api/tickets?filter[sla_breached]=1` taze seed'de **tam olarak 8** ticket döndürür
    (demo verideki bilinçli ihlaller) ve `app/` altında ham SQL kullanılmadan uygulanır.
    **DİKKAT — bu sayı yalnızca SEED ANINDA geçerlidir.** Seeder'ın "SLA penceresinin ilk
    yarısında" ürettiği 7 açık ticket'ın kısa hedefli olanları (`urgent` = 4 sa, `high` =
    24 sa) saatler geçtikçe DOĞAL OLARAK vadeyi geçer; demo veritabanı yaşlandıkça ihlal
    sayısı 8'in üzerine çıkar. Bu bir hata değil, sayacın doğru çalıştığının kanıtıdır
    (ölçüldü: 2026-08-23 16:02'de seed edilen veritabanı ertesi sabah 10 ihlal gösterdi —
    bilinçli 8 + TKT-000012 `urgent` + TKT-000015 `high`). Bu kriter testlerde SABİT bir
    dağılım (8 ihlalli / 7 açık / 8 çözülmüş / 7 kapalı) kurularak doğrulanır; canlı demo
    veritabanındaki anlık sayı ile karşılaştırılmamalıdır.
13. `tickets:scan-sla` — kalan süresi hedefin %20'sinin altına inen ticket için
    `TicketSlaWarning`, `sla_due_at`'i geçen ticket için `TicketSlaBreached` eventini **bir kez**
    üretir; komut ikinci kez koşulduğunda aynı ticket için tekrar event üretilmez; `pending`
    durumdaki ticket'lar için hiç üretilmez.
14. `TicketResource` §6'daki yedi SLA alanının tamamını belirtilen tip ve anlamlarla döndürür;
    ihlaldeki ticket'ta `sla_remaining_seconds` negatiftir.
15. `php artisan migrate:fresh --seed` yeni kolonlarla hatasız tamamlanır; yeni kolonların
    varsayılanları (null / 0) altında demo verideki 8 ihlal, 7 açık, 8 çözülmüş, 7 kapalı dağılımı
    §5.3 kurallarıyla tutarlı kalır.

---

## 9. Demo Veri Uyumluluğu

Mevcut `DemoDataSeeder::seedTickets()` bu tasarımla **uyumludur — zorunlu değişiklik yoktur**:

- 8 "breached" ticket: `sla_due_at` geçmişte + `resolved_at` null + `status=open` → §5.3 aktif
  ihlal koşulunu birebir sağlar. Yeni kolonlar migration varsayılanlarıyla (`sla_paused_at=null`,
  `sla_paused_seconds=0`) gelir; ek doldurma gerekmez.
- 7 "open" ticket SLA penceresinin ilk yarısında oluşturulur → ihlal filtresine girmez;
  `filter[sla_breached]=1` tam 8 döndürür (kabul kriteri 12).
- Breached ticket'ların 4'ünde `first_response_at` dolu olması çelişki DEĞİLDİR: SLA çözüm
  süresini ölçer; ilk yanıt verilmiş ama çözülmemiş ticket ihlalde olabilir (gerçekçi senaryo).
- `sla_due_at = created_at + ayarlardaki saat` üretimi §5.1 invariant'ıyla aynıdır.
- Resolved/closed ticket'ların bir kısmında `resolved_at > sla_due_at` çıkabilir → bunlar
  raporlarda "tarihsel ihlal" görünür; bu da gerçekçidir ve tutarlıdır.

**Önerilen (zorunlu olmayan) seeder güncellemesi — bu turda YAPILMAYACAK, yalnızca not:**
Demo veride hiç `in_progress` ve `pending` ticket yok; durum makinesi ve donmuş sayaç UI'ı canlı
veriyle test edilemiyor. Faz 8 implementasyonunda 7 "open" ticket'ın 2'sinin `in_progress`
(`first_response_at` dolu), 2'sinin `pending` (`sla_paused_at` dolu, invariant'a uygun
`sla_paused_seconds` ve kaydırılmış `sla_due_at`) yapılması önerilir — 8 ihlalli dağılım
bozulmadan. Ayrıca `TicketFactory`'ye `pending`/`inProgress` state'leri eklenmelidir.
