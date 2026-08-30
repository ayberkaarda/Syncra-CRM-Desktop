import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
// i18n, App'TEN ÖNCE import edilir: `i18n.init()` senkron çalışıp `tr` sözlüğünü yerine
// koyar, böylece ilk render'da hiçbir bileşen ham anahtar basmaz (§1.1 — `tr` eager).
import { i18nReady } from './i18n'
import App from './App.tsx'

// AÇILIŞ KAPISI: seçili dil `tr` DEĞİLSE (localStorage'daki son seçim ya da tarayıcı
// dili), o dilin sözlüğü lazy chunk olarak iner ve ANCAK O ZAMAN ilk boya yapılır.
// Aksi halde `en/de/fr` seçili bir kullanıcı sayfayı yenilediğinde i18next elinde
// olmayan sözlük yerine `fallbackLng: 'tr'`e düşer ve arayüz sessizce Türkçe basardı.
// Beklemeyi render'ın ÖNÜNE koymak, "önce Türkçe, sonra İngilizce" ara karesini
// (flash) tamamen imkânsız kılar — Suspense'e ya da bir yükleme ekranına gerek yok.
//
// `tr` seçiliyken `i18nReady` zaten çözülmüş bir söz olur: tek bir mikro-görev, sıfır
// ağ beklemesi. `index.html`'deki `#root` boş bir div olduğu için o mikro-görev
// süresince ekranda değişen bir şey de yoktur.
void i18nReady.then(() => {
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
})
