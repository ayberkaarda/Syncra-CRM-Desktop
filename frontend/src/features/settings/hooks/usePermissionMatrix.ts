// Rol / izin matrisi için TanStack Query hook'ları.
//
// GERÇEK ŞEKİL (2. tur düzeltme): `matrix: Record<number,string[]>` diye bir alan YOK — her
// rol kendi `permissions: string[]` dizisini taşır (bkz. `types.ts`). Bu yüzden
// `useUpdateRolePermissions` matris yerine `roles` dizisindeki tek bir rolü günceller.
//
// İyimser güncelleme: checkbox tıklanır tıklanmaz ilgili rolün `permissions` dizisi cache'te
// güncellenir, istek başarısız olursa önceki hâl geri yüklenir. `PATCH` yanıtı tüm matris
// DEĞİL yalnızca güncellenen rol (`RoleResource`) olduğu için `onSuccess`'te SADECE o rolün
// `permissions` dizisi tazelenir, tüm matris invalidate EDİLMEZ.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import { extractUnknownPermissions, fetchPermissionMatrix, getErrorCode, settingsKeys, updateRolePermissionsRequest } from '../api'
import type { PermissionMatrix } from '../types'

export function usePermissionMatrix() {
  return useQuery({
    queryKey: settingsKeys.permissionMatrix,
    queryFn: fetchPermissionMatrix,
  })
}

function toastForRoleError(error: unknown) {
  const code = getErrorCode(error)

  if (code === 'ROLE_NOT_EDITABLE') {
    toast.error(i18n.t('settings:toast.roleNotEditable'))
    return
  }
  if (code === 'CANNOT_REVOKE_OWN_SETTINGS_ACCESS') {
    toast.error(i18n.t('settings:toast.cannotRevokeOwnAccess'))
    return
  }
  if (code === 'UNKNOWN_PERMISSION') {
    const unknown = extractUnknownPermissions(error)
    toast.error(
      unknown && unknown.length > 0
        ? i18n.t('settings:toast.unknownPermissions', { list: unknown.join(', ') })
        : getErrorMessage(error)
    )
    return
  }
  toast.error(getErrorMessage(error))
}

export function useUpdateRolePermissions() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ roleId, permissions }: { roleId: number; permissions: string[] }) =>
      updateRolePermissionsRequest(roleId, permissions),
    onMutate: async ({ roleId, permissions }) => {
      await queryClient.cancelQueries({ queryKey: settingsKeys.permissionMatrix })
      const previous = queryClient.getQueryData<PermissionMatrix>(settingsKeys.permissionMatrix)
      if (previous) {
        queryClient.setQueryData<PermissionMatrix>(settingsKeys.permissionMatrix, {
          ...previous,
          roles: previous.roles.map((role) => (role.id === roleId ? { ...role, permissions } : role)),
        })
      }
      return { previous }
    },
    onError: (error, _vars, context) => {
      if (context?.previous) queryClient.setQueryData(settingsKeys.permissionMatrix, context.previous)
      toastForRoleError(error)
    },
    onSuccess: (role) => {
      queryClient.setQueryData<PermissionMatrix>(settingsKeys.permissionMatrix, (current) => {
        if (!current) return current
        return {
          ...current,
          roles: current.roles.map((r) => (r.id === role.id ? { ...r, permissions: role.permissions } : r)),
        }
      })
    },
  })
}
