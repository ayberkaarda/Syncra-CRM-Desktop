# Mühendislik Kuralları — Syncra Desktop

## 0. ÖNCELİK SIRASI

1. **`SYNCDESKTOP.md` bağlayıcıdır.** "ZORUNLU / YASAK / KARAR" işaretli maddeler tartışmaya açık değildir. Bu dosya ile çelişirse SYNCDESKTOP kazanır.
2. Bu dosya çalışma biçimini düzenler.
3. Kullanıcının o anki açık talimatı, ikisini de o iş için ezebilir.

**Oturum başında oku** (SYNCDESKTOP §0.7): `docs/PROGRESS.md`, `docs/DATABASE.md`, `docs/AUTH-FLOWS.md`, `docs/QUOTE-FINANCIALS.md`, `docs/SLA-DESIGN.md`, `docs/SETTINGS-SAFETY.md`, `docs/PHASE-AUDIT.md`, `README.md` API tablosu.

> **Devredilmiş dokümanlar:** `docs/ROADMAP.md` ve `docs/PHASE-DELIVERY.md` tamamlanmış **web** projesinin tarihçesidir. Silinmediler çünkü yukarıdaki zorunlu dokümanların 6'sı onlara atıf veriyor (`SLA-DESIGN` "ROADMAP Faz 8", `SETTINGS-SAFETY` "ROADMAP §5 R4/R11/R28" vb.) ve silmek bağlayıcı sözleşmelerde kırık referans bırakırdı. **Desktop yol haritası yalnızca `SYNCDESKTOP.md`'dir** — ROADMAP'i faz/plan kaynağı olarak kullanma, sadece geçmiş kararların gerekçesini ararken aç.

---

## 1. GIT — ZORUNLU (SYNCDESKTOP §0.2)

- Çalışma branch'ini ve worktree'leri **teknik lider** açar.
- Şeritler için izinli git komutları: **`git status`, `git diff`, `git log`, `git ls-files`**. Başka hiçbiri yok.
- `commit`, `push`, `stash`, `reset`, `checkout`, `merge`, `rebase`, `cherry-pick`, `branch`, `worktree` → **yalnızca teknik lider**.
- Commit, faz kapısında, kararlaştırılan mesajla, **tek commit** olarak atılır.
- Yazma yapan bir git komutuna ihtiyaç duyan şerit onu kendisi çalıştırmaz; teknik lidere talep olarak iletir.
- Push teknik liderde olduğu için `gh run watch` türü push-sonrası CI doğrulama akışı şeritler için geçerli değildir.

---

## 2. FAZ KAPILARI — ZORUNLU (SYNCDESKTOP §0.3)

- Her fazın sonunda **DUR**, SYNCDESKTOP §11 formatında **Türkçe** raporla, onay bekle. Onaysız sonraki faza geçmek YASAK.
- "Çalışıyor / test ettim / doğrulandı" iddiası **yalnızca komut + gerçek çıktı** ile kurulur. Çalıştırılmayan test raporlanmaz.
- Bir şeridin "confirmed / yeşil / geçti" dediği her şey **faz kapısında teknik lider tarafından yeniden çalıştırılır**. Şerit raporu tek başına kanıt değildir.
- **Fixture'ın kapsadığı bir şekil için hiçbir test kendi inline JSON'unu kanıt sayamaz.** (`wire-fixtures/`)
- Testler **ön planda** çalıştırılır; arka plana atılıp sonuç okunmadan rapor yazılmaz.

### Regresyon kapısı (SYNCDESKTOP §0.4) — her faz sonunda yeşil olmalı, çıktılar rapora girer

```
cd backend  && php artisan test                                   # 1427 test tabanı (2026-08-31 ölçümü) + yeniler
cd frontend && npx tsc -p tsconfig.app.json --noEmit              # çıplak `tsc --noEmit` YASAK
cd frontend && npm run i18n:check && npm run i18n:check-bootstrap
cd frontend && npm run build                                      # web bundle etkilenmemeli
cd desktop/crates/syncra-sync && cargo test && cargo clippy --all-targets -- -D warnings
cd desktop  && npm run build:desktop                              # F3'ten itibaren
cd desktop  && npm test                                           # wire-fixture 3. tüketicisi
cd desktop  && npm run check:errors                               # errors.ts <-> sözlük simetrisi
```

> **Tuzak 1:** root `tsconfig.json` solution-style'dır; çıplak `tsc --noEmit` hiçbir şey kontrol etmeden sessizce 0 döner. Her zaman `-p tsconfig.app.json` ver.
>
> **Tuzak 2:** `npm run i18n:check-bootstrap` (`frontend/scripts/check-i18n-bootstrap.mjs`) `src/i18n/index.ts` ve `src/main.tsx` **kaynak metnine** statik assert'ler yapar. Bu iki dosyaya dokunan her değişiklik script'i kırabilir — dokunulduğunda script de güncellenir.

---

## 3. ŞERİT DÜZENİ

| Katman | Ne için |
|---|---|
| **Teknik lider** | Planlama, sözleşme yazımı, faz bütünleştirme, şerit çıktısı doğrulama, karar |
| **Ağır şerit** | Sync protokolü, çakışma algoritması, Rust crate tasarımı, migration risk analizi — teknik lider eşdeğer kritiklikte başka bir parçadayken |
| **Keşif turu** | Çok dosyalı keşif, envanter çıkarma, risk taraması, paralel plan taslakları |
| **Standart şerit** | i18n key ekleme, test yazma, boilerplate, CRUD, mekanik rename/config, doküman güncelleme |

Şüphede kalınırsa iş standart şeritte başlar; kritiklik ortaya çıktıkça yukarı taşınır.

---

## 4. TEKNİK LİDER vs ŞERİT

- **Teknik lider:** planlar, böler, sözleşme yazar, doğrular, entegre eder.
  - **Üretim kodunu** (backend PHP, frontend TS/TSX, Rust) doğrudan yazmaz — şeride devreder.
  - **Serbest:** sözleşme/protokol dokümanları (`docs/DESKTOP-*.md`), plan dosyaları, bu dosya, konfigürasyon düzeltmeleri, tek satırlık entegrasyon yamaları. SYNCDESKTOP §0.1 sözleşme yazımını zaten teknik lidere veriyor.
- **Şerit:** tüm hacimli dosya değişiklikleri ve çok adımlı yürütme.
- **Paralellik:** 2+ bağımsız görev varsa aynı dalgada paralel şeritlere devredilir; teknik lider boşta beklemez.

---

## 5. İŞ BÖLÜMÜ KURALLARI

- **Contract first:** Parçalar birbirine dokunuyorsa, teknik lider arayüzü (fonksiyon imzaları, tipler, endpoint şekilleri, hata kodları) **dağıtımdan önce** tanımlar. Hiçbir şerit tahmin yürütmez.
- **File ownership:** Her şeride açık ve çakışmayan dosya listesi verilir. Aynı turda iki şeride aynı dosya asla atanmaz.
- **Worktree izolasyonu (SYNCDESKTOP K13):** Paralel fazlar ayrı git worktree'lerinde çalışır (`../syncra-wt-backend`, `../syncra-wt-crate`, …). Worktree'leri **teknik lider** açar. **Bir şerit kendi worktree'si dışına yazamaz** — görev tanımında kök dizin mutlak yolla belirtilir.
- **Sequencing:** Bir parça gerçekten diğerinin çıktısına bağlıysa sahte paralellik yapılmaz; önce bloklayan parça bitirilir.
- **Test izolasyonu:** Aynı dalgada birden fazla şerit test çalıştıracaksa her birine izole veritabanı verilir:
  `DB_DATABASE=test_tmp_<sonek> php artisan test`
  Paylaşılan `syncra_crm_test` üzerinde eşzamanlı koşum şeritlerin birbirinin şemasını bozmasına ve sahte kırmızıya yol açar. Şerit, koşum bitince kendi `test_tmp_<sonek>` veritabanını düşürmekle yükümlüdür.
- **Şerit brifingi (SYNCDESKTOP §0.1):** Her şeride SYNCDESKTOP §0.2–0.6 **aynen** iletilir (git kuralı, faz kapısı, regresyon, scope, dil kuralı).

---

## 6. SCOPE VE DİL

- **Scope (§0.5):** SYNCDESKTOP'ta olmayan özellik eklenmez. Fikirler rapordaki "Öneriler (uygulanmadı)" bölümüne yazılır. Onaylanmış bir karar/kod, açık talimat olmadan değiştirilmez.
- **Dil (§0.6):** Kod, identifier, commit mesajı, log, dosya adı, doküman içi teknik terimler → **İngilizce**. Kullanıcıya rapor → **Türkçe**.
- **UI metni hard-code YASAK.** Mevcut 27 namespace'e ek olarak yeni `desktop` namespace açılır ve **tr / en / de / fr dördü birden** doldurulur (`frontend/src/i18n/locales/<lang>/desktop.json`). Eksik dil = kırık faz; `npm run i18n:check` yakalar.
- Tauri komut hataları `{code, message}` döner, UI'da `desktop.errors.*` anahtarıyla gösterilir.
- **Zamanlama ve toplu silme kararları kullanıcıya bırakılır.** Toplu silme yapan bir komut yazılabilir, ama `routes/console.php`'ye kaydedilip zamanlanması ayrı ve açık bir karardır (bkz. `attachments:prune-orphans`).

---

## 7. HATA YÖNETİMİ VE ESKALASYON

- Teknik lider tüm şerit çıktılarını inceler, çakışmaları çözer.
- Hata veya düzeltme gerekiyorsa **yeni şerit açılmaz** — bağlamı korumak için düzeltme talimatı **aynı** şeride iletilir.
- Bir şerit aynı görevde iki kez başarısız olursa, parça hata bağlamıyla birlikte deneyimli bir şeride devredilir. O da başarısız olursa teknik lider parçayı kendi üstlenir.

---

## 8. YIKICI KOMUT GÜVENLİĞİ

- `db:seed`, `db:reset`, `db:drop`, `migrate:fresh`, `migrate:refresh`, `TRUNCATE`, toplu `delete_all`/`destroy_all` veya eşdeğeri hiçbir komut — teknik lider veya şerit farketmez — **o çağrıya özel açık kullanıcı onayı olmadan** çalıştırılamaz.
- Repoda bir script'in var olması onu çalıştırma onayı değildir. Etkisi bilinmiyorsa önce içeriği okunur.
- **Dosya silme/taşıma da yıkıcıdır:** silinecek yolların tam listesi önce kullanıcıya sunulur, onaysız silinmez.
- `test_tmp_` önekli veritabanları geçicidir (`RefreshDatabase` şemayı her koşumda kurar) ve faz kapanışında onay istenmeden düşürülebilir; ancak prosedür zorunludur:
  1. `SHOW PROCESSLIST` ile o veritabanlarına bağlı aktif oturum kalmadığını doğrula,
  2. silinecek isimleri açık liste olarak çıkar ve `syncra_crm` ile `syncra_crm_test`'in listede **bulunmadığını** programatik kontrol et,
  3. her birini tek tek, açık isimle düşür.

  **Joker desen (`DROP DATABASE test_tmp_%` vb.) hiçbir koşulda kullanılmaz.** `syncra_crm` ve `syncra_crm_test` bu istisnanın DIŞINDADIR; her zaman açık onay gerektirir.

---

## 9. RAPOR FORMATI (SYNCDESKTOP §11)

```
## FAZ N RAPORU
### Yapılanlar (madde madde, dosya referanslı)
### Dokunulan dosyalar — path | neden
### Çalıştırılan komutlar → gerçek çıktı (sayılar, süreler)
### Kabul kriterleri — [x]/[ ] + gerekçe
### Riskler / açık sorular
### Öneriler (uygulanmadı)
### Onay bekliyorum: F(N+1)
```
