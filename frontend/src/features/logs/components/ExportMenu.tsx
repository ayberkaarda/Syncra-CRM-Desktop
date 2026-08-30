// Dışa Aktar butonu — CSV/Excel açılır menüsü. Aktif sekmenin `type`'ını ve o an
// uygulanan filtreleri export URL'ine taşır. `logs.export` izni yoksa hiç render edilmez
// (bkz. `LogsPage.tsx`).
//
// İndirme mekanizması: endpoint bir dosya akıtıyor ve kimlik doğrulama cookie tabanlı, bu
// yüzden `fetch` ile blob'a çevirmeye gerek yok — ama düz `window.location.href` / `<a>`
// tıklaması da risklidir: backend 403 (izin) veya 422 (geçersiz filtre) dönerse tarayıcı SPA'yı
// bırakıp o JSON hata gövdesini tam sayfa gösterir ve kullanıcı boş/çıplak bir sayfada kalır.
// Bunu önlemek için görünmez, sıfır boyutlu bir `<iframe>` üzerinden tetikleniyoruz: başarılı
// yanıt `Content-Disposition: attachment` taşıdığından tarayıcı SPA sekmesini hiç terk etmeden
// indirmeyi başlatır; hata yanıtı ise yalnızca iframe'in gizli belgesine yüklenir, kullanıcı
// hiçbir zaman görmez ve Loglar sayfasında kalmaya devam eder. Çapraz-origin (8000 vs. Vite dev
// sunucusu) olduğu için iframe içeriği okunamaz, dolayısıyla 403/422 durumunda ayrıca bir toast
// gösterilemiyor — ama sayfa asla kırılmıyor/boşalmıyor, ki asıl gereksinim buydu.
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, Download, FileSpreadsheet, FileText } from 'lucide-react'
import { Button } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { buildExportUrl } from '../api/logsApi'
import type { ExportFilters } from '../api/logsApi'
import type { ExportFormat, ExportType } from '../types'

export type ExportMenuProps = {
  type: ExportType
  filters: ExportFilters
}

function triggerDownload(url: string) {
  let iframe = document.getElementById('logs-export-frame') as HTMLIFrameElement | null
  if (!iframe) {
    iframe = document.createElement('iframe')
    iframe.id = 'logs-export-frame'
    iframe.setAttribute('aria-hidden', 'true')
    iframe.style.position = 'fixed'
    iframe.style.width = '0'
    iframe.style.height = '0'
    iframe.style.border = '0'
    iframe.style.visibility = 'hidden'
    document.body.appendChild(iframe)
  }
  iframe.src = url
}

export function ExportMenu({ type, filters }: ExportMenuProps) {
  const { t } = useTranslation('logs')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!open) return
    function handleClickOutside(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  function handleExport(format: ExportFormat) {
    triggerDownload(buildExportUrl(type, format, filters))
    setOpen(false)
  }

  return (
    <div ref={containerRef} className="relative">
      <Button
        variant="secondary"
        leftIcon={<Download className="size-4" aria-hidden="true" />}
        rightIcon={<ChevronDown className="size-4" aria-hidden="true" />}
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        {t('logs:exportMenu.button')}
      </Button>

      {open && (
        <div
          role="menu"
          aria-label={t('logs:exportMenu.menuAria')}
          className="absolute right-0 top-full z-20 mt-2 w-44 overflow-hidden rounded-lg border border-border bg-surface-3 py-1 shadow-popover"
        >
          <button
            type="button"
            role="menuitem"
            onClick={() => handleExport('csv')}
            className={cn(
              'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm text-fg hover:bg-surface-2',
              'transition-colors duration-150 motion-reduce:transition-none',
            )}
          >
            <FileText className="size-4 text-fg-muted" aria-hidden="true" />
            {t('logs:exportMenu.csv')}
          </button>
          <button
            type="button"
            role="menuitem"
            onClick={() => handleExport('xlsx')}
            className={cn(
              'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm text-fg hover:bg-surface-2',
              'transition-colors duration-150 motion-reduce:transition-none',
            )}
          >
            <FileSpreadsheet className="size-4 text-fg-muted" aria-hidden="true" />
            {t('logs:exportMenu.excel')}
          </button>
        </div>
      )}
    </div>
  )
}
