# Tasarım Sistemi — Figma Kaynak Analizi

**Kaynak:** Figma dosyası `Dashboard CRM (Community) (Copy)` — file key `tlJ6qKhhmbBZAKIYaCIolE`
**Frame:** "Dashboard CRM", 1920×2425 px, koyu tema
**Yöntem:** Figma REST API ile katman özelliklerinden ölçüldü. Dosyada yayınlanmış style/component YOK — tüm token'lar aşağıda "ölçüldü" notuyla belirtilen ham katman verisinden çıkarıldı, tahmin edilmedi.
**Kaynak şablon:** DexignZone admin teması (footer'da "Developed by DexignZone 2023")
**Tarih:** 2026-08-23

> Bu doküman, bu değerler Figma REST API ile ölçüldü, tahmin değil. Tek istisna açık tema paletidir: Figma'da light frame olmadığı için §1.6'da tasarım kararı olarak tanımlandı ve kontrast oranları hesapla doğrulandı (2026-08-23).

---

## 1. Renkler

### 1.1 Marka / Aksiyon

| Token | Hex | Kullanım Sayısı |
|---|---|---|
| `primary` | `#0D99FF` | 31 dolgu + 36 metin + 24 kenarlık |
| `primary-soft` | `#0D99FF` (%10 opaklık) | 14 — aktif nav item zemini, "Inprogress" rozet zemini |
| `primary-subtle` | `#0D99FF` (%5 opaklık) | 1 |

### 1.2 Durum Renkleri

| Token | Hex | Kullanım Sayısı |
|---|---|---|
| `success` | `#3AC977` | 10 dolgu, 11 metin |
| `success-soft` | `#3AC977` (%10 opaklık) | 9 |
| `danger` | `#FF5E5E` | 14 dolgu, 14 metin, 13 kenarlık |
| `danger-soft` | `#FF5E5E` (%15 opaklık) | 6 |
| `danger-subtle` | `#FF5E5E` (%5 opaklık) | 5 |
| `warning` | `#FF9F00` | 2 dolgu, 1 metin, 3 kenarlık |

> Not: Figma'da warning için ayrı bir tint (soft) ölçümü yok. primary ve success tint'leriyle tutarlı olacak şekilde %10 opaklık varsayıldı.

### 1.3 Koyu Tema Yüzeyleri

Not: Rol ataması (surface-0/1/2/3 isimlendirmesi) teknik lider tarafından yapıldı; hex değerlerin kendisi ölçüldü.

| Token | Hex | Kullanım Sayısı | Rol |
|---|---|---|---|
| `surface-0` | `#171717` | — | Uygulama zemini |
| `surface-1` | `#1E1E1E` | 49 | Kart / panel |
| `surface-2` | `#242424` | 30 | Input, hover, yükseltilmiş yüzey |
| `surface-3` | `#2C2C2C` | 3 | Chat balonu, popover |
| — | `#202020` | 2 | Dosyada ek koyu ton |
| — | `#383838` | 4 | Dosyada ek koyu ton |
| — | `#464646` | 2 | Dosyada ek koyu ton |
| `border` | `#444444` | 174 | En yaygın kenarlık rengi |
| `border-subtle` | `#2C2C2C` | — | İkincil kenarlık |
- `border-strong` (form kontrolü kenarlığı) `#888888` — Figma'da 50 kenarlık kullanımı. surface-1'e karşı **4.70:1**, surface-2'ye karşı **4.38:1** → WCAG AA UI bileşeni eşiğini (3:1) geçer. **`border` (#444444) form kontrollerinde kullanılamaz** — surface-1'e karşı yalnızca 1.71:1, dekoratif ayırıcı olarak sınırlıdır.

### 1.4 Metin

| Token | Hex | Kullanım Sayısı |
|---|---|---|
| `text-primary` | `#FFFFFF` | 183 |
| `text-secondary` | `#B3B3B3` | 17 |
| `text-muted` | `#828690` | 130 — tablo başlıkları / yardımcı metin |
| `text-disabled` | `#888888` | 72 dolgu, 50 kenarlık, 4 metin |
| — | `#E1E1E1` | 1 |
| — | `#C8C8C8` | 8 (kenarlık) |
| — | `#B6B6B6` | 5 (kenarlık) |

### 1.5 Nötr Açık Tonlar (İllüstrasyon Rengi — Tema Rengi DEĞİL)

Bu tonlar dünya haritası widget'ındaki ülke dolgu/kenarlıklarına ait; genel arayüz temasının parçası değildir.

| Hex | Kullanım Sayısı |
|---|---|
| `#EFF2F4` | 182 dolgu + 182 kenarlık |
| `#EEEEEE` | 12 dolgu, 6 kenarlık |
| `#E6EAEE` | 1 |
| `#FEFEFE` | 4 |
| `#FFFFFF` | 11 dolgu |
| `#000000` | 80 — gölge/overlay |

### 1.6 Açık Tema — KARAR (2026-08-23, tasarım mühendisliği kararı; Figma'da light frame yok)

**Karar ve gerekçe:** Figma dosyasında yalnızca koyu tema çizildiği için açık tema burada tasarım kararı olarak tanımlandı; koyu paletin mekanik tersi DEĞİLDİR. Koyu temanın accent'leri (`#0D99FF`, `#3AC977`, `#FF5E5E`, `#FF9F00`) koyu zemin için seçilmiş parlak tonlardır ve beyaz zeminde WCAG AA'yı geçemezler (ölçüldü: 2.99:1, 2.14:1, 2.99:1, 2.06:1) — bu yüzden açık temada her accent'in aynı hue'da, koyulaştırılmış bir "işlevsel" varyantı tanımlandı. Yüzey hiyerarşisi bilinçli olarak ters çevrildi (koyu temada kart zeminden açık; açık temada kart beyaz, zemin hafif gri), soft/tint zeminler %10 opaklık yerine sabit açık tonlar olarak yeniden belirlendi ve yükseklik anlatımı yüzey renginden gölgeye taşındı. Aşağıdaki tüm kontrast oranları relative luminance formülüyle hesaplandı.

#### 1.6.1 Açık Tema Token Tablosu

| Token | Hex | Rol / Kullanım |
|---|---|---|
| `surface-0` | `#F4F6F9` | Uygulama zemini (hafif soğuk gri) |
| `surface-1` | `#FFFFFF` | Kart / panel — zeminden açık, gölgeyle ayrışır |
| `surface-2` | `#EFF2F4` | Input zemini, hover, tablo satır hover'ı |
| `surface-3` | `#E9EDF2` | Karşı taraf chat balonu; popover'lar `surface-1` + `shadow-popover` kullanır |
| `border` | `#E2E6EC` | Varsayılan dekoratif kenarlık (kart, ayraç, tablo çizgisi) |
| `border-subtle` | `#EFF2F4` | İkincil/çok hafif ayraç |
| `border-strong` | `#878E99` | Form kontrolü sınırları (input, checkbox) — UI bileşeni için 3:1 zorunluluğunu bu token karşılar |
| `text-primary` | `#1E1E1E` | Ana metin, başlıklar, KPI rakamları |
| `text-secondary` | `#444444` | İkincil metin |
| `text-muted` | `#667085` | Tablo başlıkları, yardımcı metin (koyu temadaki `#828690` beyazda 3.64:1 → AA başarısız, koyulaştırıldı) |
| `text-disabled` | `#A0A6B1` | Devre dışı metin (WCAG'de kontrast muafiyeti var) |
| `primary` | `#0672C4` | Link, breadcrumb aktif, ikonlar, primary buton dolgusu, grafik çizgileri (`#0D99FF` beyazda 2.99:1 → AA başarısız) |
| `primary-soft` | `#EBF5FF` | Aktif nav item zemini, "Inprogress" rozet zemini |
| `primary-subtle` | `#F5FAFF` | Çok hafif vurgu zemini |
| `success` | `#16794A` | Başarı metni/ikonu, rozet metni (`#3AC977` beyazda 2.14:1 → AA başarısız) |
| `success-soft` | `#E2F6EB` | Success rozet/tint zemini |
| `danger` | `#C81E1E` | Hata metni/ikonu, rozet metni (`#FF5E5E` beyazda 2.99:1 → AA başarısız) |
| `danger-soft` | `#FDEDED` | Danger rozet/tint zemini |
| `danger-subtle` | `#FEF6F6` | Çok hafif hata zemini |
| `warning` | `#A15C00` | Uyarı metni/ikonu, rozet metni (`#FF9F00` beyazda 2.06:1 → AA başarısız) |
| `warning-soft` | `#FFF3E0` | Warning rozet/tint zemini |

Not: Koyu temanın parlak accent'leri (`#0D99FF`, `#3AC977`, `#FF5E5E`, `#FF9F00`) açık temada yalnızca **metin olmayan, dekoratif** öğelerde (sparkline dolgusu, avatar halkası vb.) kullanılabilir; metin, rozet metni, ikon-tek-başına-anlam ve grafik eksen/çizgileri işlevsel varyantları kullanır.

#### 1.6.2 Kontrast Doğrulama Tablosu (hesaplandı, göz kararı değil)

| Ön Plan | Arka Plan | Oran | AA | Kullanım Bağlamı |
|---|---|---|---|---|
| `text-primary` `#1E1E1E` | `surface-1` `#FFFFFF` | 16.67:1 | ✅ | Gövde metni, başlık |
| `text-primary` `#1E1E1E` | `surface-0` `#F4F6F9` | 15.40:1 | ✅ | Zemin üstü metin |
| `text-secondary` `#444444` | `surface-1` `#FFFFFF` | 9.74:1 | ✅ | İkincil metin |
| `text-muted` `#667085` | `surface-1` `#FFFFFF` | 4.97:1 | ✅ | Tablo başlığı, yardımcı metin |
| `text-muted` `#667085` | `surface-0` `#F4F6F9` | 4.59:1 | ✅ | Zemin üstü yardımcı metin |
| `primary` `#0672C4` | `surface-1` `#FFFFFF` | 4.99:1 | ✅ | Link, breadcrumb, fiyat metni |
| `primary` `#0672C4` | `primary-soft` `#EBF5FF` | 4.52:1 | ✅ | "Inprogress" rozet metni |
| `success` `#16794A` | `success-soft` `#E2F6EB` | 4.81:1 | ✅ | "Completed / Active / In Stock" rozet metni |
| `success` `#16794A` | `surface-1` `#FFFFFF` | 5.43:1 | ✅ | Success düz metin |
| `danger` `#C81E1E` | `danger-soft` `#FDEDED` | 5.06:1 | ✅ | "Pending / Out of Stock" rozet metni |
| `danger` `#C81E1E` | `surface-1` `#FFFFFF` | 5.74:1 | ✅ | Hata mesajı, takvim hafta sonu |
| `warning` `#A15C00` | `warning-soft` `#FFF3E0` | 4.73:1 | ✅ | Warning rozet metni |
| `warning` `#A15C00` | `surface-1` `#FFFFFF` | 5.19:1 | ✅ | Uyarı düz metni |
| Buton metni `#FFFFFF` | `primary` `#0672C4` | 4.99:1 | ✅ | Primary buton (Logout, Send) |
| Buton metni `#FFFFFF` | `danger` `#C81E1E` | 5.74:1 | ✅ | Danger buton |
| `border-strong` `#878E99` | `surface-1` `#FFFFFF` | 3.30:1 | ✅ (UI 3:1) | Input/checkbox sınırı |
| `border-strong` `#878E99` | `surface-0` `#F4F6F9` | 3.06:1 | ✅ (UI 3:1) | Zemin üstü form kontrolü |
| `border` `#E2E6EC` | `surface-1` `#FFFFFF` | 1.25:1 | — (dekoratif, muaf) | Kart kenarlığı, ayraç — anlam taşımaz |

Reddedilen değerler (AA'yı geçemediği için değiştirildi): `#0D99FF`/beyaz = 2.99 ❌, `#3AC977`/beyaz = 2.14 ❌, `#FF5E5E`/beyaz = 2.99 ❌, `#FF9F00`/beyaz = 2.06 ❌, `#828690`/beyaz = 3.64 ❌, ara aday `#DC2626`/`#FDEBEB` = 4.20 ❌ (yerine `#C81E1E` seçildi), ara aday `#0B7FD4`/beyaz = 4.20 ❌ (yerine `#0672C4` seçildi).

#### 1.6.3 Açık Tema Gölge Ölçeği

Koyu temada yükseklik yüzey rengiyle anlatılır (gölge neredeyse görünmez, `rgba(0,0,0,0.02)`); açık temada bu işi gölge taşır:

| Token | Değer | Kullanım |
|---|---|---|
| `shadow-sm` | `0 1px 2px rgba(16,24,40,0.06)` | Input, küçük yükselti |
| `shadow-card` | `0 1px 3px rgba(16,24,40,0.05), 0 8px 24px rgba(16,24,40,0.06)` | Kart / panel — kartı beyaz-üstü-gri ayrımıyla birlikte taşır |
| `shadow-popover` | `0 12px 32px rgba(23,28,38,0.14)` | Dropdown, popover, modal |
| `shadow-glow` | `0 4px 14px rgba(6,114,196,0.24)` | Primary buton vurgusu (yeni primary hue'ya uyarlandı) |

#### 1.6.4 Neden Birebir Ters Çevirme Değil

- **Yüzey hiyerarşisi tersine döndü:** Koyu temada kart zeminden açıktır (`#1E1E1E` > `#171717`); açık temada kart (`#FFFFFF`) zeminden (`#F4F6F9`) daha açıktır — "yükselen yüzey açıklaşır" ilkesi iki temada da korunur ama değerler negatif değildir.
- **Accent'ler koyulaştırıldı:** Dört accent'in hiçbiri beyaz üstünde AA geçmediği için açık temaya özel işlevsel varyantlar tanımlandı; koyu tema orijinal parlak değerlerini korur.
- **Tint'ler opaklıkla değil sabit renkle:** Koyu temadaki `%10 opaklık` yaklaşımı beyaz üstünde neredeyse görünmezdir; açık temada rozet/tint zeminleri sabit açık tonlar olarak tanımlandı.
- **Kenarlık zayıflatıldı:** Koyu temanın `#444444` çizgi ağırlığının açık temadaki dengi sert kaçar; dekoratif kenarlık `#E2E6EC`'e indirildi, form kontrolleri için ayrıca 3:1 sağlayan `border-strong` eklendi.
- **Yükseklik gölgeye taşındı:** Koyu temada fark yüzey rengindedir; açık temada kartlar ve popover'lar gerçek gölge ölçeğiyle ayrışır.
- **`text-muted` koyulaştırıldı:** Koyu temayla aynı bırakılan `#828690` beyazda AA geçemedi; `#667085`'e çekildi.

---

## 2. Tipografi

**Ana font ailesi:** Poppins
**İkon fontu:** Font Awesome 5 Free (weight 900)

Not: Dosyada Helvetica (17+6+5 kullanım) ve Arial (2+1+1 kullanım) de görünüyor, ancak bunlar Figma'nın fallback/import artifact'ı — tasarımın gerçek fontu değil.

### 2.1 Ölçülen Kombinasyonlar (aile / weight / size / lineHeight)

En çok kullanılandan aza sıralı:

| Kullanım | Weight | Size | LineHeight |
|---|---|---|---|
| 69 | 400 | 13 | 20 |
| 42 | 400 | 14 | 32 |
| 29 | 400 | 14 | 21 |
| 23 | 500 | 13 | 20 |
| 21 | 400 | 14 | 25 |
| 17 | 500 | 14 | 21 |
| 17 | 500 | 12 | 18 |
| 15 | 500 | 16 | 24 |
| 15 | 400 | 12 | 18 |
| 12 | 500 | 15 | 18 |
| 12 | 400 | 13 | 16 |
| 8 | 400 | 13 | 21 |
| 4 | 700 | 14 | 21 |
| 3 | 600 | 24 | 32 |
| 2 | 600 | 20 | 30 |
| 1 | 600 | 38 | 57 |
| 1 | 500 | 17 | 26 |

Letter-spacing tüm kombinasyonlarda **0**'dır.

### 2.2 Türetilen Ölçek

| Token | Boyut / LineHeight | Kullanım Alanı |
|---|---|---|
| `text-xs` | 12px / 18px | Rozet, yardımcı metin |
| `text-sm` | 13px / 20px | **Taban boyut — en yaygın.** Tablo hücresi, gövde metni |
| `text-base` | 14px / 21px | Form label, nav item |
| `text-md` | 15px / 23px | Kart alt başlığı |
| `text-lg` | 16px / 24px | Kart başlığı |
| `text-xl` | 20px / 30px | Bölüm başlığı |
| `text-2xl` | 24px / 32px | Sayfa başlığı |
| `text-3xl` | 38px / 57px | Büyük KPI rakamı |

### 2.3 Weight Skalası

| Weight | Ad | Kullanım |
|---|---|---|
| 400 | normal | Gövde metni |
| 500 | medium | En yaygın vurgu |
| 600 | semibold | Başlıklar |
| 700 | bold | Nadir kullanım |

---

## 3. Border Radius

### 3.1 Ölçülen Değerler (kullanım sayısı ile)

6 (48), 4 (41), 15 (34), 12 (13), 20 (12), 40 (12 — pill), 3 (5), 10 (3), 16 (3), 2 (3), 60 (2), 30 (1)

### 3.2 Asimetrik Radius (özel bileşenler)

| Değer (üst-sağ/alt-sağ/alt-sol/üst-sol) | Kullanım | Bağlam |
|---|---|---|
| `11 / 11 / 0 / 0` | 6 | Üst köşeler — tab/modal başlığı |
| `10 / 10 / 0 / 10` | 4 | Chat balonu |
| `0 / 10 / 10 / 10` | 2 | Karşı taraf chat balonu |
| `6 / 0 / 0 / 6` | 1 | Input grubu |

### 3.3 Ölçek

| Token | Değer |
|---|---|
| `rounded-sm` | 4 |
| `rounded` (varsayılan) | 6 |
| `rounded-md` | 12 |
| `rounded-lg` | 15 |
| `rounded-xl` | 20 |
| `rounded-full` | 9999 (40'lık pill'ler için) |

---

## 4. Spacing

### 4.1 Padding Değerleri (ölçülen, kullanım sayısıyla)

20 (107 — **baskın kart/panel padding'i**), 16 (29), 24 (21), 12 (38), 13 (18), 11 (23), 10 (15), 8 (12), 15 (12), 6 (12), 5 (38), 9 (7)

### 4.2 Autolayout Gap

5 (17), 10 (12+17), 2 (12), 8 (4), 1 (3), 15 (1)

### 4.3 Not — Ondalıklı Değerler

Dosyada görülen ondalıklı değerler (22.75, 23.25, 10.078, 19.859 vb.) HTML→Figma import artifact'ıdır; gerçek tasarım niyeti değildir. Uygulamada en yakın 4'ün katına yuvarlanmalıdır.

### 4.4 Önerilen Ölçek (4px taban)

`4, 8, 12, 16, 20, 24, 32, 40` — **20** baskın kart padding'i olarak öne çıkıyor.

---

## 5. Gölgeler

Dosyada yalnızca 3 farklı gölge tanımı var:

| Token | Değer | Kullanım |
|---|---|---|
| `shadow-card` | `0 15px 30px rgba(0,0,0,0.02)` | Kart gölgesi — çok yumuşak |
| `shadow-glow` | `0 0 20px rgba(13,153,255,0.20)` | Primary buton / aktif eleman parıltısı |
| `shadow-popover` | `0 0 30px rgba(82,63,105,0.15)` | Açılır panel — mor tonlu gölge (`#523F69`) |

---

## 6. Layout

Ekran görüntüsünden ölçüldü:

- Toplam genişlik: **1920px**
- Sidebar: **240px** sabit genişlik
- Sağ mesaj drawer'ı: **340px** sabit genişlik
- Orta bölüm: esnek içerik
- Üst bar yüksekliği: ~56px
- Text input yüksekliği: 50px
- Kart grid'i: 12 kolon mantığında; KPI satırı 4 kart, alt satırlar 2-3 kart
- Sayfa dikey akışı: Üst bar → sayfa başlığı + breadcrumb → KPI kartları → grafikler → tablolar → alt bölümler → footer

---

## 7. Bileşen Envanteri

Ekran görüntüsünden tespit edildi:

- **Sidebar**: Logo alanı, "YOUR COMPANY" / "OUR FEATURES" bölüm başlıkları (küçük, muted, uppercase), ikonlu nav item'ları, açılır alt menü göstergesi (chevron), aktif item = primary-soft zemin + primary metin, altta primary "Help Desk" butonu.
- **Üst bar**: Hamburger menü, arama input'u (ikon prefix'li, yuvarlak), sağda ikon butonları (ayar/zil/mail), primary "Logout" butonu, avatar + isim/e-posta bloğu.
- **Sayfa başlığı**: Başlık + breadcrumb (Home / Dashboard, aktif kısım primary renkte) + sağda aksiyon butonu ("+ Add Task").
- **KPI kartı**: Etiket + büyük rakam + sağ üstte renkli ikon kutusu (rounded, soft zemin) + altta sparkline alan grafiği; varyantlar: donut'lu, progress bar'lı.
- **Grafik kartı**: Başlık + segment kontrolü (Week/Month/Year/All — aktif olan primary dolgu, pill), bar+area kombo grafik, çizgi grafik, donut grafik (ortada toplam), lejant (renkli nokta + etiket + değer).
- **Veri tablosu**: Sıralanabilir başlıklar (yanında sıralama ikonu, başlıklar muted), avatar+isim hücresi, avatar grubu (üst üste binmiş), progress bar hücresi, **durum rozeti** (soft zemin + eşleşen metin rengi: Inprogress=primary, Pending=danger, Completed=success, In Stock=success, Out of Stock=danger, Active=success), "Showing 1 to 5 of 10 entries" + sayfalama (aktif sayfa primary dolgu kare).
- **To-do listesi**: Sürükleme tutamacı, checkbox, metin + zaman damgası, sağda sil/düzenle ikon butonları, grup başlıkları (uyarı ikonu + "Latest to do's").
- **Chat paneli**: Karşı taraf balonu (sol, surface-2, asimetrik radius), kendi balonu (sağ), zaman damgası, "Today" ayırıcı, altta input + primary "Send" butonu.
- **Mesaj drawer'ı** (sağ): NOTES/ALERTS/CHAT sekmeleri (aktif sekme primary alt çizgi), "+" butonu, alfabetik grup başlıkları (A, B, D, J), liste öğesi = avatar + online noktası (yeşil/kırmızı) + isim + durum metni.
- **Takvim**: Ay navigasyonu (chevron butonları), hafta günü başlıkları, gün hücreleri (hafta sonu danger renkte), seçili gün = primary dolgu kare + bildirim noktası, altında EVENTS listesi (tarih bloğu + başlık + alt başlık + saat).
- **Dünya haritası**: Ülke listesi (bayrak + isim + progress bar + yüzde).
- **Ürün listesi**: Küçük görsel + isim/tarih + fiyat (primary renk) + stok rozeti.
- **Butonlar**: Primary dolgu (Logout, Send, + Add Employee), ghost/outline (+ Invite Employee), ikon butonu, link buton (Export Report — ikon + primary metin).
- **Form**: Arama input'u (rounded, ikon prefix'li), checkbox, select/dropdown ("SORT BY: Today ˅", "Setting ˅").

---

## 8. Önemli Uyarı — IA (Bilgi Mimarisi) Uyuşmazlığı

Bu template **genel bir admin/proje yönetimi** teması; sidebar'ında Employees, Core HR, Finance, Projects, Performance, Manage Clients, Apps, Charts, Bootstrap, Plugins, Widget, Forms, Table, Pages gibi öğeler var. Bunlar bizim CRM modüllerimizle (Leads, Contacts/Companies, Deals+Kanban, Tasks, Tickets, Products/Quotes, Reports, Notifications, Settings, Logs, Chat) birebir örtüşmüyor.

**Karar:** Tasarımın **GÖRSEL DİLİ** (renk, tipografi, kart anatomisi, rozet/tablo/grafik kalıpları) alınacak; **BİLGİ MİMARİSİ** (menü yapısı) alınmayacak — o `PRODUCT-BRIEF.md`'deki modül listesinden gelir.

### Eşleme Tablosu

| Template Öğesi | Bizim Karşılığımız |
|---|---|
| Projects Overview grafiği | Satış hunisi / gelir trendi (Faz 11 Dashboard) |
| Active Projects tablosu | Fırsatlar (Deals) listesi (Faz 7) |
| Employees tablosu | Kullanıcı Yönetimi (Faz 2) ve Kişiler/Firmalar (Faz 6) |
| My To Do Items | Görevler (Faz 8) |
| Chat paneli + Messages drawer | Chat modülü (Faz 12) |
| Upcoming Schedules takvimi | Görevler takvim görünümü (Faz 8) |
| Best Selling Products | Ürünler (Faz 9) |
| Total Deposit/Expenses/Earning KPI kartları | Dashboard KPI'ları (Faz 11) |
| Active users haritası | Rapor/analitik widget'ı (Faz 11) — coğrafi veri modelimizde yoksa kaynak analizi grafiğiyle değiştirilir |
| Status rozetleri (Inprogress/Pending/Completed) | Deal aşamaları, ticket durumları, lead durumları |

---

## 9. Açık Sorular

- Açık tema §1.6'da karara bağlandı (2026-08-23).
- Poppins Google Fonts'tan mı yüklenecek (kapalı devre sistemde CDN yerine self-host önerilir)?
- Font Awesome 5 yerine modern bir ikon seti (lucide-react) kullanılsın mı? Template FA5 kullanıyor ama React projesinde lucide daha uygun — teknik lider önerisi: lucide-react, ikon isimleri eşlenerek.
- Dünya haritası widget'ı korunacak mı (CRM veri modelinde ülke bazlı veri yok)?

---

**Not:** Tailwind'e aktarım Faz 1'de yapılacak; bu doküman tek doğruluk kaynağıdır.
