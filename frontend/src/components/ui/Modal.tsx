// Modal/diyalog bileşeni — odak tuzağı, Esc/backdrop ile kapanma, body scroll kilidi ile document.body'e portallanır.
// Tasarımdaki diyalog pencereleri (ör. "+ Add Employee" formu) için kullanılır.
import { createPortal } from 'react-dom'
import { forwardRef, useEffect, useId, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/cn'

export type ModalProps = {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl'
  footer?: ReactNode
  closeOnBackdrop?: boolean
  children: ReactNode
}

const sizeClasses: Record<NonNullable<ModalProps['size']>, string> = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
}

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

export const Modal = forwardRef<HTMLDivElement, ModalProps>(
  ({ open, onClose, title, description, size = 'md', footer, closeOnBackdrop = true, children }, ref) => {
    const { t } = useTranslation('common')
    const autoId = useId()
    const titleId = `${autoId}-title`
    const descriptionId = `${autoId}-description`
    const panelRef = useRef<HTMLDivElement | null>(null)
    const previouslyFocused = useRef<HTMLElement | null>(null)

    // Gecikmeli unmount: `open` false olduğunda çıkış geçişi bitene kadar DOM'da kal.
    const [mounted, setMounted] = useState(open)
    const [visible, setVisible] = useState(false)

    useEffect(() => {
      if (open) {
        setMounted(true)
        const raf = requestAnimationFrame(() => setVisible(true))
        return () => cancelAnimationFrame(raf)
      }
      setVisible(false)
      const timeout = setTimeout(() => setMounted(false), 150)
      return () => clearTimeout(timeout)
    }, [open])

    // Açılışta odağı panele taşı, kapanışta açan öğeye geri ver.
    useEffect(() => {
      if (!open) return
      previouslyFocused.current = document.activeElement as HTMLElement | null
      const panel = panelRef.current
      const firstFocusable = panel?.querySelector<HTMLElement>(FOCUSABLE_SELECTOR)
      ;(firstFocusable ?? panel)?.focus()

      return () => {
        previouslyFocused.current?.focus?.()
      }
    }, [open])

    // Açıkken body scroll'unu kilitle.
    useEffect(() => {
      if (!mounted) return
      const original = document.body.style.overflow
      document.body.style.overflow = 'hidden'
      return () => {
        document.body.style.overflow = original
      }
    }, [mounted])

    // Esc ile kapanma + Tab/Shift+Tab odak tuzağı.
    useEffect(() => {
      if (!open) return
      function handleKeyDown(event: KeyboardEvent) {
        if (event.key === 'Escape') {
          event.stopPropagation()
          onClose()
          return
        }
        if (event.key !== 'Tab') return
        const panel = panelRef.current
        if (!panel) return
        const focusables = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
        if (focusables.length === 0) {
          event.preventDefault()
          return
        }
        const first = focusables[0]
        const last = focusables[focusables.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first.focus()
        }
      }
      document.addEventListener('keydown', handleKeyDown, true)
      return () => document.removeEventListener('keydown', handleKeyDown, true)
    }, [open, onClose])

    if (!mounted) return null

    return createPortal(
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div
          className={cn(
            'absolute inset-0 bg-black/50 backdrop-blur-sm',
            'transition-opacity duration-150 motion-reduce:transition-none',
            visible ? 'opacity-100' : 'opacity-0'
          )}
          onClick={closeOnBackdrop ? onClose : undefined}
          aria-hidden="true"
        />
        <div
          ref={(node) => {
            panelRef.current = node
            if (typeof ref === 'function') ref(node)
            else if (ref) (ref as React.MutableRefObject<HTMLDivElement | null>).current = node
          }}
          role="dialog"
          aria-modal="true"
          aria-labelledby={title ? titleId : undefined}
          aria-describedby={description ? descriptionId : undefined}
          tabIndex={-1}
          className={cn(
            'relative w-full bg-surface-1 rounded-xl shadow-popover',
            'flex max-h-[90vh] flex-col',
            'transition-[opacity,transform] duration-150 motion-reduce:transition-none',
            visible ? 'opacity-100 scale-100' : 'opacity-0 scale-95',
            sizeClasses[size]
          )}
        >
          {(title || description) && (
            <div className="flex items-start justify-between gap-4 border-b border-border-subtle p-5">
              <div className="flex min-w-0 flex-col gap-1">
                {title && (
                  <h2 id={titleId} className="text-md font-medium text-fg">
                    {title}
                  </h2>
                )}
                {description && (
                  <p id={descriptionId} className="text-sm text-fg-muted">
                    {description}
                  </p>
                )}
              </div>
              <button
                type="button"
                onClick={onClose}
                aria-label={t('actions.close')}
                className={cn(
                  'shrink-0 rounded-md p-1.5 text-fg-muted hover:bg-surface-2 hover:text-fg',
                  'transition-colors duration-150 motion-reduce:transition-none',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
                )}
              >
                <X className="size-4" aria-hidden="true" />
              </button>
            </div>
          )}
          {!title && !description && (
            <button
              type="button"
              onClick={onClose}
              aria-label="Kapat"
              className={cn(
                'absolute right-4 top-4 rounded-md p-1.5 text-fg-muted hover:bg-surface-2 hover:text-fg',
                'transition-colors duration-150 motion-reduce:transition-none',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
              )}
            >
              <X className="size-4" aria-hidden="true" />
            </button>
          )}
          <div className="overflow-y-auto p-5">{children}</div>
          {footer && <div className="border-t border-border-subtle p-5">{footer}</div>}
        </div>
      </div>,
      document.body
    )
  }
)

Modal.displayName = 'Modal'
