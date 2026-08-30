# Teklif Finansal Modeli — Bağlayıcı Sözleşme (Faz 9)

> Bu doküman, teklif (quote) hesaplama modelinin **tek doğruluk kaynağıdır**.
> `app/Services/Quotes/QuoteCalculator.php` ve ilgili tüm backend/frontend kodu
> buradaki kurallara birebir uymak zorundadır. Kurallar `DemoDataSeeder` ve
> testler için de bağlayıcıdır.
>
> Kapsadığı şema: `quotes` (2026_08_23_200002), `quote_items` (2026_08_23_200003).
> Para birimi bu fazda yalnızca `TRY`; KDV varsayılanı %20.

---

## 1. Karar Özeti

**KDV, teklif geneli indirim düşüldükten SONRAKİ matrah üzerinden hesaplanır.**
KDV Kanunu md. 25/a uyarınca fatura üzerinde gösterilen ticari teamüllere uygun
iskontolar KDV matrahına dahil edilmez; teklif bir fatura olmasa da faturaya
dönüşen belgedir ve tekliftaki toplam ile faturadaki toplamın farklı çıkması
ticari güven sorunu yaratır. Mevcut formül (`total = subtotal - discount_amount + tax_amount`,
KDV indirim öncesi tutardan) müşteriye **fazla KDV** yansıtır ve değiştirilmelidir.
Teklif geneli indirim, kalemlere değil **KDV oranı gruplarına** ciro payıyla
orantılı dağıtılır (e-fatura/fatura pratiğinde matrah oran bazında raporlanır;
kalem bazlı dağıtım hiçbir müşteri-görünür değeri değiştirmez, sadece karmaşıklık
ekler). Yuvarlama her yerde **half-up, 2 hane (kuruş)**; kuruş artığı **en büyük
kalan (largest remainder)** yöntemiyle deterministik kapatılır. Şemaya üç düşük
maliyetli ekleme yapılır: `discount_type`/`discount_value` (yüzde indirim girişi
için) ve `parent_quote_id`/`revision` (revizyon zinciri için).

---

## 2. Hesap Formülü (adım adım)

Tüm hesaplar **tamsayı kuruş** (int) veya bcmath ile yapılır; PHP float'ı üzerinde
zincirleme aritmetik YASAK (0.1+0.2 sınıfı hatalar). `round()` çağrıları
half-up'tır (PHP varsayılanı) — bkz. Bölüm 4.

Girdi değişkenleri (kalem `i` için): `quantity_i` (decimal 10,2), `unit_price_i`
(decimal 15,2), `discount_percent_i` (decimal 5,2, 0–100), `tax_rate_i`
(decimal 5,2, 0–100). Teklif seviyesinde: `discount_type` (`amount`|`percent`),
`discount_value` (decimal 15,2).

### Adım 1 — Kalem net tutarı (KDV HARİÇ)
```
line_total_i = round2( quantity_i × unit_price_i × (1 − discount_percent_i/100) )
```
`line_total_i` DB'ye bu (2 hane, half-up) değerle yazılır. KDV **dahil değildir**.

### Adım 2 — Ara toplam
```
subtotal = Σ line_total_i        // 2 haneli değerlerin tam toplamı, ek yuvarlama YOK
```

### Adım 3 — Teklif geneli indirim tutarı
```
discount_amount = discount_type == 'percent'
                    ? round2( subtotal × discount_value / 100 )
                    : discount_value                       // zaten 2 hane
```
Kısıt: `0 ≤ discount_amount ≤ subtotal`. İhlalde 422 validation hatası; kayıt
yazılmaz. `subtotal = 0` iken `discount_amount` 0 olmak zorundadır.

### Adım 4 — İndirimi KDV oranı gruplarına dağıt
Bölüm 3'teki algoritmayla her `tax_rate` grubu `g` için `group_discount_g`
hesaplanır. Garanti: `Σ group_discount_g = discount_amount` (tam eşitlik).

### Adım 5 — Grup matrahı ve grup KDV'si
```
group_net_g      = Σ line_total_i    (tax_rate_i = g olan kalemler)
group_base_g     = group_net_g − group_discount_g          // KDV matrahı
group_tax_g      = round2( group_base_g × g / 100 )
```

### Adım 6 — Teklif toplamları
```
tax_amount = Σ group_tax_g           // tam toplam, ek yuvarlama YOK
total      = subtotal − discount_amount + tax_amount       // tam aritmetik
```

### Kenar durumlar
- **Kalemsiz teklif (draft):** subtotal = tax_amount = total = 0.00; discount_amount 0 olmak zorunda.
- **`tax_rate = 0`** (istisna/ihracat): geçerli bir gruptur; group_tax = 0. İndirim payını yine alır.
- **`quantity = 0` veya `unit_price = 0`:** line_total = 0.00; geçerli. Grup payı hesabında payı 0'dır.
- **Negatif değerler:** quantity, unit_price, discount_percent, discount_value, tax_rate negatif OLAMAZ (validation). Negatif kalem (iade satırı) bu fazda kapsam dışı.
- **`discount_amount = subtotal`:** tüm matrahlar 0, tax_amount 0, total 0.00 — geçerli.
- Aynı `tax_rate` değerli kalemler tek gruptur; gruplama `tax_rate`'in 2 haneli sayısal değeriyle yapılır (20.00 ile 20.0 aynı grup).

---

## 3. İndirim Dağıtım Algoritması (grup bazlı, largest remainder)

Dağıtım **ciro payına göre orantılıdır** (nötr ve savunulabilir; "yüksek KDV'li
kalemden düş" gibi taraflı stratejiler reddedildi — vergi idaresi nezdinde
orantısız dağıtım izahı zordur). Kuruş artığı floor + en büyük kalan yöntemiyle
kapatılır; sonuç deterministiktir ve artık asla negatif olmaz.

```
girdi : groups = [{rate, net}]  (net = kuruş cinsinden int, net > 0 olan gruplar)
        D = discount_amount (kuruş, int)
çıktı : her grup için pay_g (kuruş, int),  Σ pay_g == D

eğer D == 0 → tüm pay_g = 0, bitir
S = Σ net_g                                  // == subtotal (kuruş)

her grup için:
    raw_g   = D × net_g / S                  // rasyonel sayı; tam kesir olarak tut
    floor_g = ⌊raw_g⌋                        // kuruşa AŞAĞI yuvarla
    frac_g  = raw_g − floor_g                // kalan kesir, karşılaştırma için

residual = D − Σ floor_g                     // 0 ≤ residual ≤ grup_sayısı − 1

grupları sırala: 1) frac_g büyükten küçüğe
                 2) eşitse net_g büyük olan önce
                 3) hâlâ eşitse tax_rate YÜKSEK olan önce   // müşteri lehine: yüksek
                                                            // oranlı matrah daha çok düşer
ilk `residual` gruba +1 kuruş ekle
pay_g = floor_g (+1 eklenmişse)
```

Notlar:
- `net_g = 0` olan grup (tüm kalemleri 0 TL) dağıtıma katılmaz, payı 0'dır.
- `raw_g` kesiri tam hesaplanmalı: `D × net_g` int çarpımı sonra `intdiv`/`%` —
  float'a dönüştürülmez.
- Dağıtım kalem tablosuna YAZILMAZ; yalnızca `tax_amount` hesabının ara adımıdır.
  PDF/UI'da "KDV matrah özeti" tablosu istenirse aynı fonksiyondan türetilir.
- `QuoteCalculator` bu dağıtımı döndüren public bir metod sunmalıdır
  (ör. `taxBreakdown(): array{rate, base, tax}[]`) — PDF'teki oran bazlı KDV
  özeti ve testler bunu kullanır.

---

## 4. Yuvarlama Kuralı

- **Yön: half-up** (0.005 → 0.01; PHP `round($x, 2)` varsayılanı,
  `RoundingMode::HalfAwayFromZero`). Banker's rounding KULLANILMAZ — Türkiye
  ticari pratiği ve müşterinin elle doğrulayabilirliği esastır.
- **Hassasiyet: 2 hane (kuruş).** DB kolonları decimal(15,2); daha hassas ara
  değer DB'ye yazılmaz.
- **Yuvarlama yalnızca şu 3+1 noktada yapılır:**
  1. `line_total_i` (Adım 1) — half-up.
  2. `discount_amount` yüzdeden hesaplanıyorsa (Adım 3) — half-up.
  3. Grup indirim payları (Adım 4) — floor + largest remainder (half-up DEĞİL;
     tam kapanma garantisi için).
  4. `group_tax_g` (Adım 5) — half-up.
- Toplamlar (`subtotal`, `tax_amount`, `total`) 2 haneli değerlerin **tam
  toplamıdır**; toplama sırasında ek yuvarlama yapılmaz (kuruş int'leriyle
  toplama zaten kayıpsızdır). Seeder'daki `round($subtotal + $lineTotal, 2)`
  tarzı adım-adım re-round, int kuruş aritmetiğine geçince gereksizleşir.
- Kuruş artığı: yalnızca indirim dağıtımında oluşur ve Bölüm 3 kuralıyla
  kapatılır; `Σ pay_g = discount_amount` **tam** eşitliği her durumda sağlanır.
  `Σ line_total`↔`subtotal` ve `Σ group_tax`↔`tax_amount` eşitlikleri tanım
  gereği tamdır — 0.01 toleransa gerek yoktur, testler **tam eşitlik** aramalıdır.

---

## 5. Şema Değişikliği (öneriliyor — tek yeni migration)

`quotes` tablosuna 4 kolon (yeni migration, ör. `2026_08_XX_XXXXXX_add_discount_and_revision_to_quotes_table.php`):

| Kolon | Tip | Default | Amaç |
|---|---|---|---|
| `discount_type` | `string` (values: `amount`, `percent`) | `'amount'` | Kullanıcının indirim giriş biçimi. Satışta "%5 kır" konuşması yaygın; yüzde girişi UI'da şart. |
| `discount_value` | `decimal(15,2)` | `0` | Girilen ham değer (yüzde ise 0–100, tutar ise TL). |
| `parent_quote_id` | `foreignId` nullable → `quotes.id`, `nullOnDelete` | `null` | Revizyon zinciri (Bölüm 6). |
| `revision` | `unsignedSmallInteger` | `1` | Revizyon numarası. |

- **`discount_amount` KALIR** ve anlamı değişmez: uygulanan indirimin TL
  karşılığı, her zaman `QuoteCalculator` tarafından yazılır (yüzde girişinde
  `round2(subtotal × discount_value/100)`, tutar girişinde `discount_value`).
  Tüm matematik ve raporlama `discount_amount` üzerinden döner — mevcut kod ve
  kontrol sorguları kırılmaz.
- `quote_items` DEĞİŞMEZ. KDV dahil satır tutarı için kolon EKLENMEZ (Bölüm 5b).
- Gerekçe: Faz 9 henüz yazılmadığı için migration maliyeti sıfıra yakın;
  `discount_value` olmadan yüzde girişi kaybolur (kullanıcı %5 girdi, kalem
  değişti → tutar sabit kalır ve %5'lik anlam bozulur; `percent` tipinde
  `discount_amount` her yeniden hesapta güncellenir).

### 5b. `line_total` KDV dahil mi hariç mi?
**Hariçtir ve hariç kalır.** KDV dahil satır tutarı türetilebilir:
`line_gross_i = round2(line_total_i × (1 + tax_rate_i/100))` — Quote model
accessor'ı / frontend'te hesaplanır, kolon eklenmez. UI/PDF kalem tablosunda
"KDV Dahil" sütunu gösterilebilir; ancak **dipnot toplamları daima quote
başlığındaki `subtotal/discount_amount/tax_amount/total` alanlarından basılır**,
KDV dahil sütununun toplamından ASLA türetilmez (teklif geneli indirim varken
sütun toplamı `total`'dan kuruş düzeyinde sapabilir — bu bir hata değildir).

---

## 6. Revizyon Modeli

**Karar: `parent_quote_id` + `revision` eklenir; "sent sonrası kilit + yeni
teklif" kuralı korunur ama yeni teklif zincire bağlanır.**

- `sent`, `rejected` veya `expired` durumundaki teklif **"Revize Et"** ile
  kopyalanır: tüm başlık alanları + kalemler derin kopya, yeni kayıt
  `status='draft'`, `revision = parent.revision + 1`,
  `parent_quote_id = <kopyalanan teklifin id'si>` (zincir: her revizyon bir
  öncekini gösterir), `sent_at/accepted_at/rejected_at = null`,
  `valid_until` yeniden hesaplanır.
- `quote_number`: kök `QTE-000007` (revision 1, suffix yok); revizyonlar
  `QTE-000007-R2`, `QTE-000007-R3`… Unique kısıtı korunur; kök numara suffix
  atılarak bulunur.
- `draft` revize edilmez (zaten düzenlenebilir); `accepted` revize edilmez
  (kabul edilmiş taahhüt — değişiklik gerekiyorsa bağımsız yeni teklif açılır).
- Eski kaydın durumu DEĞİŞMEZ (`revised` diye yeni status eklenmiyor);
  "bu teklifin daha yeni revizyonu var" bilgisi child sorgusuyla bulunur ve
  UI'da rozet olarak gösterilir.
- "Aynı anda tek aktif revizyon" kısıtı DB'de zorlanmaz; `QuoteService`
  revize ederken parent'ın zaten draft bir child'ı varsa onu döndürür
  (ikinci kopya açmaz).
- **Maliyet/fayda:** 2 kolon + 1 FK'lik maliyete karşılık "QTE-000007'nin
  2. revizyonu" bilgisi kalıcı olarak kazanılır; satış sürecinde revizyon
  geçmişi (kaç tur pazarlık döndü) raporlanabilir. Sadelik alternatifi bu
  bilgiyi tamamen kaybettirdiği için reddedildi.

---

## 7. Demo Veri (seeder değişiklik tarifi — bu turda uygulanmadı)

`backend/database/seeders/DemoDataSeeder.php`:

1. **`seedQuotes()` (satır ~697):** kalem döngüsünde `$lineTax` hesabı ve
   `$taxAmount` birikimi KALDIRILIR (satır 736, 739). Kalemler bitince:
   `$discountAmount` mevcut kuralla hesaplanır (`$i % 4 === 0` →
   `round($subtotal * 0.05, 2)`), ardından kalemler `tax_rate`'e göre
   gruplanıp Bölüm 3 dağıtımı + Bölüm 2 Adım 5–6 ile `$taxAmount` ve `$total`
   bulunur. İdeali: Faz 9'da yazılacak `QuoteCalculator`'ı doğrudan çağırmak
   (formül tek yerde yaşasın); calculator henüz yoksa seeder'a geçici private
   helper yazılır ve Faz 9'da calculator çağrısıyla değiştirilir.
2. **Yeni kolonlar:** indirimli tekliflerde (`i % 4 === 0`, yani QTE-000001/5/9/13)
   `discount_type='percent'`, `discount_value=5.00`; diğerlerinde
   `'amount'`/`0`. Tüm tekliflerde `revision=1`, `parent_quote_id=null`.
   (İsteğe bağlı zenginleştirme: `rejected` tekliflerden birine bir `-R2`
   draft revizyonu eklenebilir; zorunlu değil.)
3. **`assertConsistency()` (satır ~1482):** `'teklif toplamı kalemlerle
   uyuşmuyor'` SQL kontrolü mevcut haliyle YANLIŞ hale gelir (KDV'yi indirim
   öncesi matrahtan doğruluyor) ve largest-remainder SQL'de ifade edilemez.
   Bu kontrol SQL listesinden çıkarılır; yerine PHP tarafında, her teklif için
   kalemlerden aynı algoritmayla (calculator/helper) beklenen
   `subtotal/tax_amount/total` yeniden hesaplanıp DB değerleriyle **tam
   eşitlik** (tolerans yok — algoritma deterministik) karşılaştırılır;
   uyuşmazlıkta `RuntimeException`.
4. **`migrate:fresh --seed` GEREKLİ** (kullanıcı onayı alınmış durumda):
   mevcut 15 teklifin 4'ü indirimli olduğundan `tax_amount`/`total` değerleri
   yeni kurala göre değişecek; ayrıca yeni kolonların dolması gerekir.
   Ürünlerdeki karışık KDV dağılımı (index %7==0 → %10, diğerleri %20)
   KORUNUR — indirim dağıtımının çok-oranlı yolunu demo veride canlı tutar.

---

## 8. Kabul Kriterleri

Her madde `QuoteCalculator` birim testine birebir çevrilir. Tüm karşılaştırmalar
**tam eşitlik** (0.01 tolerans YOK).

1. **Karışık oran + teklif indirimi (ana senaryo).** Kalemler: (a) 2 × 100.00,
   kalem indirimi %10, KDV %20 → `line_total` 180.00; (b) 1 × 50.00, %0, KDV %10
   → 50.00. Teklif indirimi 30.00 TL. Beklenen: `subtotal = 230.00`; dağıtım
   %20 grubuna 23.48, %10 grubuna 6.52; `tax_amount = 31.30 + 4.35 = 35.65`;
   `total = 235.65`. (Eski formül 41.00 KDV / 241.00 total verirdi — test bunu
   da negatif kontrol olarak içermeli.)
2. **Üç grup + kuruş artığı tie-break.** Kalemler: 1×100.00 %20 KDV, 1×100.00
   %10 KDV, 1×100.00 %1 KDV; teklif indirimi 10.00. Ham paylar 3.33̅; floor
   sonrası residual 1 kuruş, kesirler ve netler eşit → yüksek oran kazanır:
   paylar 3.34 / 3.33 / 3.33. KDV: 19.33 + 9.67 + 0.97 = `tax_amount 29.97`;
   `subtotal 300.00`, `total 319.97`.
3. **Artık negatif olamaz.** Kalemler: 1×100.00 %20, 1×100.00 %10; indirim
   10.01. Ham paylar 5.005/5.005 → floor 5.00/5.00, residual 1 → net eşit,
   yüksek oran kazanır: 5.01/5.00. KDV: 19.00 + 9.50 = 28.50; `total 218.49`.
4. **Yalnız kalem indirimi (D=0).** (a) 3×2500.00 %0 KDV %20 → 7500.00;
   (b) 10×120.00 %5 KDV %10 → 1140.00. `subtotal 8640.00`,
   `tax_amount 1500.00+114.00 = 1614.00`, `total 10254.00`. Dağıtım fonksiyonu
   D=0'da tüm paylar 0 döndürür.
5. **Yüzde tipi teklif indirimi.** 1×1234.56, %0, KDV %20; `discount_type=
   'percent'`, `discount_value=5.00`. `discount_amount = round2(61.728) =
   61.73`; matrah 1172.83; `tax_amount = round2(234.566) = 234.57`;
   `total = 1407.40`.
6. **Half-up kanıtı (kalem).** 1.50 × 0.03, %0 → ham 0.045 →
   `line_total = 0.05` (banker's 0.04 verirdi — bu değer gelirse test FAIL).
   Ek: 1×149.90 %15 → ham 127.415 → 127.42; KDV %20 → 25.484 → 25.48;
   `total (D=0) = 152.90`.
7. **Tam indirim.** 1×500.00 %0 KDV %20; indirim 500.00 →
   `subtotal 500.00, tax_amount 0.00, total 0.00`.
8. **Validation.** `discount_amount > subtotal` (ör. subtotal 100.00, indirim
   100.01) → 422, kayıt yazılmaz. Negatif `quantity/unit_price/
   discount_percent/discount_value` → 422. `discount_type='percent'` iken
   `discount_value > 100` → 422.
9. **KDV dahil görünüm.** `line_total 180.00`, KDV %20 → görüntülenen dahil
   tutar 216.00; DB'de kolon yok. Teklif geneli indirimli bir teklifte
   "KDV dahil" sütun toplamının `total`'a eşit olması ZORUNLU DEĞİL; dipnot
   toplamları quote başlığından basılır (test: senaryo 1'de dahil sütun
   toplamı 216.00+55.00=271.00 ≠ 235.65 — PDF/UI toplam satırı 235.65 basar).
10. **Toplam bütünlüğü (invariant).** Her kayıtlı teklifte
    `subtotal = Σ line_total` (tam), `tax_amount = Σ taxBreakdown().tax` (tam),
    `total = subtotal − discount_amount + tax_amount` (tam) ve
    `Σ dağıtılan pay = discount_amount` (tam). Property-bazlı test: rastgele
    1–10 kalem, rastgele oranlar {0,1,10,20}, rastgele indirim → dört eşitlik
    her zaman sağlanır.
11. **Determinizm.** Aynı girdiyle hesap 1000 tekrarında bit-bit aynı sonucu
    verir (largest-remainder tie-break'leri tam sıralı olduğundan).
12. **Revizyon.** `status='sent'` olan QTE-000007 revize edilir → yeni kayıt:
    `quote_number='QTE-000007-R2'`, `revision=2`, `parent_quote_id=<eski id>`,
    `status='draft'`, kalem sayısı ve içerikleri birebir kopya, `sent_at null`;
    eski kayıt tamamen değişmemiş. `accepted` teklifte revize → 422/403.
    Parent'ın draft child'ı varken ikinci revize çağrısı yeni kayıt AÇMAZ.
13. **Seeder tutarlılığı.** `php artisan migrate:fresh --seed` hatasız biter;
    15 teklifin tamamı PHP tarafı yeniden-hesap kontrolünden tam eşitlikle
    geçer; QTE-000001/5/9/13'te `discount_type='percent'`,
    `discount_value=5.00`, `discount_amount = round2(subtotal×0.05)` ve
    `tax_amount` indirim-sonrası matrahtan hesaplanmış durumda.

---

## 9. Kapsam Dışı (bu turda yapılmayacaklar)

- **Çoklu para birimi / kur:** `currency` kolonu durur, her zaman `TRY`.
  Kur yönetimi ayrı faz konusu.
- **KDV tevkifatı (kısmi tevkifat oranları), ÖTV, konaklama/iletişim vergisi
  gibi ek vergiler:** teklif belgesi düzeyinde gereksinim yok; fatura
  entegrasyonu gündeme gelirse ele alınır.
- **e-Fatura / e-Arşiv / GİB entegrasyonu:** Syncra kapalı devre; teklif
  PDF'i resmi belge değildir. (Model, oran-bazlı matrah özeti üretebildiği
  için ileride UBL-TR eşlemesine hazırdır.)
- **Negatif kalem / iade satırı:** validation ile engellenir; ihtiyaç doğarsa
  dağıtım algoritmasının negatif netlerle davranışı ayrıca tanımlanmalıdır.
- **Kalem bazlı indirim TUTARI** (`discount_percent` yerine TL): kalemde yüzde
  yeterli; iki mekanizmayı kalem düzeyinde karıştırmak UI'ı karmaşıklaştırır.
- **KDV dahil fiyat girişi** (gross-to-net geri hesabı): ürün fiyatları KDV
  hariç tanımlı; dahil-fiyat girişi ayrı bir UX kararı.
- **`revised` status'u ve revizyon diff görünümü:** zincir kuruldu; diff/rozet
  ötesi UI Faz 9 kapsamına göre değerlendirilir.
- **Eski verinin migrasyonu:** prod veri yok, demo veri `migrate:fresh --seed`
  ile yeniden üretilir; veri dönüştürme script'i yazılmaz.
