// Tarih aralığı seçici — Dashboard ve Raporlar arasında paylaşılır (bkz. görev tanımı: "dashboard
// ile paylaşılabilir; nereye koyduğunu raporla" — burada, `features/reports/components/` altında,
// çünkü ilk müşterisi Raporlar'ın dört sekmesiydi; Dashboard sayfası bunu relative import ile
// kullanır). `interaction.md` "Filters & time ranges" sözleşmesi: presetler satır olarak
// listelenir (takvim ızgarasıyla uğraşmak yerine), seçili preset 16px kalın onay işaretiyle
// işaretlenir, özel aralık alttaki ince çizgi ayıracın ARKASINDA durur.
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Calendar, Check, ChevronDown } from 'lucide-react'
import { Button, Input } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { dateRangePresets, formatDateLabel, matchPreset } from '../utils'

export type DateRangeFilterProps = {
  from: string
  to: string
  onChange: (range: { from: string; to: string }) => void
}

export function DateRangeFilter({ from, to, onChange }: DateRangeFilterProps) {
  const { t } = useTranslation('reports')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const activePreset = matchPreset(from, to)
  const DATE_RANGE_PRESETS = dateRangePresets(t)

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

  const label = activePreset
    ? (DATE_RANGE_PRESETS.find((p) => p.key === activePreset)?.label ?? '')
    : `${formatDateLabel(from)} – ${formatDateLabel(to)}`

  return (
    <div ref={containerRef} className="relative">
      <Button
        variant="secondary"
        leftIcon={<Calendar className="size-4" aria-hidden="true" />}
        rightIcon={<ChevronDown className="size-4" aria-hidden="true" />}
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        {label}
      </Button>

      {open && (
        <div
          role="menu"
          aria-label={t('reports:dateRange.menuAria')}
          // KONUMLANDIRMA KARARI: `left-0` yerine `right-0` — bileşen iki yerde kullanılıyor:
          // Dashboard'da tetikleyici satırın SAĞ ucunda (justify-between ile başlığın karşısında),
          // Raporlar'da ise SOL ucunda (Dışa Aktar butonunun karşısında). Ölçüm: 1440x900'de
          // Dashboard tetikleyicisi x≈1234-1289, `left-0` ile panel (w-72=288px) 1522'ye uzayıp
          // görünür alanı (1424) 98px aşıyordu, "Bitiş" alanı kesiliyordu. `right-0` panelin sağ
          // kenarını tetikleyicinin sağ kenarına kenetler → Dashboard'da artık taşmıyor; Raporlar'da
          // tetikleyici sola yakın olsa da (x≈260-415) panel sola doğru 127'ye kadar açılıyor, hâlâ
          // 0'ın sağında, ekrandan taşmıyor (regresyon yok, doğrulandı). `max-w-[calc(100vw-2rem)]`
          // ekstra güvenlik: çok dar ekranda w-72 sabit genişliği viewport'u aşarsa panel yine de
          // görünür alana sıkışsın diye (bu repo mobil öncelikli değil ama ucuz bir kilit).
          className="absolute right-0 top-full z-20 mt-2 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-border bg-surface-3 shadow-popover"
        >
          <div className="py-1">
            {DATE_RANGE_PRESETS.map((preset) => {
              const isActive = activePreset === preset.key
              return (
                <button
                  key={preset.key}
                  type="button"
                  role="menuitemradio"
                  aria-checked={isActive}
                  onClick={() => {
                    onChange(preset.range())
                    setOpen(false)
                  }}
                  className={cn(
                    'flex w-full items-center justify-between gap-2.5 px-3 py-2 text-left text-sm text-fg hover:bg-surface-2',
                    'transition-colors duration-150 motion-reduce:transition-none',
                  )}
                >
                  {preset.label}
                  {isActive && <Check className="size-4 text-primary" aria-hidden="true" />}
                </button>
              )
            })}
          </div>

          <div className="flex items-end gap-2 border-t border-border-subtle p-3">
            <div className="w-full">
              <Input
                type="date"
                label={t('reports:dateRange.fromLabel')}
                value={from}
                max={to}
                onChange={(e) => onChange({ from: e.target.value, to })}
              />
            </div>
            <div className="w-full">
              <Input
                type="date"
                label={t('reports:dateRange.toLabel')}
                value={to}
                min={from}
                onChange={(e) => onChange({ from, to: e.target.value })}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
