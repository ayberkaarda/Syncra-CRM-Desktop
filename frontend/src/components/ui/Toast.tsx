// Sonner tabanlı bildirim (toast) sarmalayıcısı — temamıza göre stillenir, sağ üstte konumlanır.
// `Toaster`'ı uygulama kökünde bir kez, `toast(...)` çağrılarını istediğin yerden kullan.
import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react'
import { Toaster as SonnerToaster, toast } from 'sonner'
import { useTheme } from '../../hooks/useTheme'

export function Toaster() {
  const { resolvedTheme } = useTheme()

  return (
    <SonnerToaster
      theme={resolvedTheme}
      position="top-right"
      closeButton
      icons={{
        success: <CircleCheck className="size-4 text-success" aria-hidden="true" />,
        error: <CircleAlert className="size-4 text-danger" aria-hidden="true" />,
        warning: <TriangleAlert className="size-4 text-warning" aria-hidden="true" />,
        info: <Info className="size-4 text-primary" aria-hidden="true" />,
      }}
      toastOptions={{
        unstyled: false,
        classNames: {
          toast: 'bg-surface-3! text-fg! border border-border! rounded-lg! shadow-popover!',
          title: 'text-fg!',
          description: 'text-fg-muted!',
          closeButton: 'bg-surface-2! border-border! text-fg-muted!',
          actionButton: 'bg-primary! text-primary-fg!',
          cancelButton: 'bg-surface-2! text-fg-muted!',
          success: 'border-success!',
          error: 'border-danger!',
          warning: 'border-warning!',
          info: 'border-primary!',
        },
      }}
    />
  )
}

export { toast }
