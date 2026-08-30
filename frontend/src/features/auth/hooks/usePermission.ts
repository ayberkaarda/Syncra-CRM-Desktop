// Bu yalnızca UI görünürlüğü içindir; asıl yetki kontrolü daima backend'dedir.
import { useAuthStore } from '../store'

const SUPER_ADMIN_ROLE = 'Super Admin'

export function usePermission() {
  const user = useAuthStore((state) => state.user)

  const isSuperAdmin = () => user?.role?.name === SUPER_ADMIN_ROLE

  const can = (permission: string) => isSuperAdmin() || (user?.permissions.includes(permission) ?? false)

  const canAny = (permissions: string[]) => isSuperAdmin() || permissions.some((permission) => can(permission))

  const canAll = (permissions: string[]) => isSuperAdmin() || permissions.every((permission) => can(permission))

  const hasRole = (role: string) => isSuperAdmin() || user?.role?.name === role

  return { can, canAny, canAll, hasRole, isSuperAdmin }
}
