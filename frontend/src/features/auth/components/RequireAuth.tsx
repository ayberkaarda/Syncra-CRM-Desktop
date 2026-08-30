import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ShieldAlert } from 'lucide-react'
import { EmptyState } from '../../../components/ui'
import { useAuthStore } from '../store'
import { usePermission } from '../hooks/usePermission'

type RequireAuthProps = {
  permission?: string
  children: ReactNode
}

/**
 * Route koruması: oturum durumunu bekler, kimliksizse `/login`'e yönlendirir
 * (dönüş adresini `state.from` olarak taşır) ve isteğe bağlı bir izin
 * gerekiyorsa yoksa 403 boş durumu gösterir.
 */
export function RequireAuth({ permission, children }: RequireAuthProps) {
  const { t } = useTranslation(['auth', 'common'])
  const status = useAuthStore((state) => state.status)
  const user = useAuthStore((state) => state.user)
  const location = useLocation()
  const { can } = usePermission()

  if (status === 'loading' || status === 'idle') {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-0">
        <div
          className="size-8 animate-spin motion-reduce:animate-none rounded-full border-2 border-border-strong border-t-primary"
          role="status"
          aria-label={t('common:states.loading')}
        />
      </div>
    )
  }

  if (status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  // Geçici şifreyle giriş yapılmış — logout ve `/change-password`'in kendisi
  // hariç her korumalı route'tan zorunlu değişim ekranına yönlendir (bkz.
  // docs/AUTH-FLOWS.md §4.1). `/change-password` sayfası kendi ters guard'ını
  // taşır, bu yüzden burada döngü riski yok.
  if (status === 'authenticated' && user?.must_change_password && location.pathname !== '/change-password') {
    return <Navigate to="/change-password" replace state={{ from: location }} />
  }

  if (permission && !can(permission)) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-surface-0">
        <EmptyState
          icon={<ShieldAlert className="size-6" aria-hidden="true" />}
          title={t('auth:forbidden.title')}
          description={t('auth:forbidden.description')}
        />
      </div>
    )
  }

  return <>{children}</>
}
