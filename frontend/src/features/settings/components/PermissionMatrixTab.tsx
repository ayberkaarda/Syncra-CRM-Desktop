// Rol / İzin Matrisi sekmesi — roller × izinler tablosu, modüle göre satır grupları.
//
// 2. TUR DÜZELTME: Gerçek backend yanıtında `matrix: Record<number,string[]>` diye bir alan
// YOK. Her rol kendi `permissions: string[]` dizisini taşıyor; satır grupları `permission.group`
// değil `modules: {key, permissions: string[]}[]` dizisinden geliyor. Bu dosya o gerçek şekle
// göre yazıldı — kendi grupla(ma)mızı yeniden hesaplamıyoruz, sunucunun `modules` sırasını ve
// içeriğini birebir kullanıyoruz.
//
// SALT-OKUNUR SÜTUN: `is_super_admin` DEĞİL `is_editable: false` bayrağı esas alınır (daha
// genel — ileride başka korumalı bir rol çıkarsa da çalışır). Böyle bir rolün `permissions`
// dizisi boş gelebilir (`Gate::before` ile zaten tüm izinlere sahip, KASITLI) — bu sütun her
// zaman "tüm izinler işaretli" + devre dışı gösterilir, boş diziye bakıp boş GÖSTERİLMEZ.
//
// Büyük matris tablosu yatayda taşabilir — `Table` bileşeni kendi `overflow-x-auto`
// kapsayıcısını zaten sarmalıyor (bkz. `components/ui/Table.tsx`), sayfa gövdesi kaymaz. İlk
// sütun (izin adı) `sticky left-0` ile kaydırma sırasında sabit kalır.
import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { ShieldCheck, Users } from 'lucide-react'
import { Badge, Button, Checkbox, Skeleton, Table, TBody, Td, THead, Th, Tr } from '../../../components/ui'
import { roleLabel } from '../../users/utils/roleMeta'
import { usePermissionMatrix, useUpdateRolePermissions } from '../hooks/usePermissionMatrix'
import type { PermissionMatrixRole } from '../types'

function titleizeModule(moduleKey: string): string {
  return moduleKey.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

export function PermissionMatrixTab() {
  const { t } = useTranslation(['settings', 'common', 'enums'])
  const { data, isLoading, isError, refetch } = usePermissionMatrix()
  const updatePermissions = useUpdateRolePermissions()

  const roles = data?.roles ?? []
  const modules = data?.modules ?? []
  const permissionByName = new Map((data?.permissions ?? []).map((permission) => [permission.name, permission]))

  function hasPermission(role: PermissionMatrixRole, permissionName: string): boolean {
    // Salt-okunur rol: her zaman "hepsine sahip" gösterilir, gerçek `permissions` dizisine
    // (boş olabilir, KASITLI) bakılmaz.
    if (!role.is_editable) return true
    return role.permissions.includes(permissionName)
  }

  function togglePermission(role: PermissionMatrixRole, permissionName: string) {
    if (!role.is_editable) return
    const current = role.permissions
    const next = current.includes(permissionName)
      ? current.filter((name) => name !== permissionName)
      : [...current, permissionName]
    updatePermissions.mutate({ roleId: role.id, permissions: next })
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        {Array.from({ length: 8 }).map((_, i) => (
          <Skeleton key={i} variant="rect" height={36} />
        ))}
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('settings:permissions.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  const columnCount = roles.length + 1

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-fg-muted">{t('settings:permissions.description')}</p>

      <Table>
        <THead>
          <Tr>
            <Th className="sticky left-0 z-10 bg-surface-1">{t('settings:permissions.columns.permission')}</Th>
            {roles.map((role) => (
              <Th key={role.id} align="center">
                <span className="inline-flex flex-col items-center gap-0.5">
                  <span className="inline-flex items-center gap-1.5">
                    {roleLabel(role.name, t)}
                    {!role.is_editable && <ShieldCheck className="size-3.5 text-primary" aria-hidden="true" />}
                  </span>
                  <span className="inline-flex items-center gap-1 text-[11px] font-normal normal-case text-fg-muted">
                    <Users className="size-3" aria-hidden="true" />
                    {role.users_count}
                  </span>
                </span>
              </Th>
            ))}
          </Tr>
        </THead>
        <TBody>
          {modules.map((module) => (
            <Fragment key={module.key}>
              <Tr className="hover:bg-transparent">
                <Td
                  colSpan={columnCount}
                  className="sticky left-0 z-10 bg-surface-2 text-xs font-semibold uppercase tracking-wide text-fg-muted"
                >
                  {titleizeModule(module.key)}
                </Td>
              </Tr>
              {module.permissions.map((permissionName) => (
                <Tr key={permissionName}>
                  <Td className="sticky left-0 z-10 bg-surface-1 font-mono text-xs">
                    {permissionName}
                    {permissionByName.get(permissionName)?.action && (
                      <span className="ml-2 font-sans text-fg-disabled">
                        ({permissionByName.get(permissionName)?.action})
                      </span>
                    )}
                  </Td>
                  {roles.map((role) => (
                    <Td key={role.id} align="center">
                      {/* KÖK NEDEN + DÜZELTME: `Td align="center"` zaten doğru `text-center` sınıfını
                          taşıyor (bkz. `components/ui/Table.tsx`) — sorun orada değil. `Checkbox`
                          bileşeninin kökü `<div className="flex flex-col gap-1">`; bu blok, varsayılan
                          `align-items: stretch` yüzünden tek çocuğu olan `<label className="inline-flex
                          items-center gap-2">` öğesini hücrenin TAM genişliğine geriyor. Genişleyen bu
                          `label` ise kendi içinde `justify-center` TAŞIMADIĞI için kutucuğu sola
                          (`justify-start`) yaslıyor — kayma sütun genişliğiyle orantılı büyüyor (Ölçüm:
                          Super Admin −51px, Admin −18px, Destek Temsilcisi −56px, ...). `Checkbox` birçok
                          yerde paylaşılıyor (QuoteItemsEditor, ConversionTab, SourceAnalysisTab,
                          UserPerformanceTab, SalesPerformanceTab, ContactsPage) — bileşeni burada
                          değiştirmek regresyon riski taşır, bu yüzden düzeltme yalnızca bu sekmeye özel:
                          `flex justify-center` sarmalayıcı, Checkbox'ın kök div'ini flex öğesi yapıp ana
                          eksende (yatay) ortalıyor; Checkbox'ın kendi iç `flex-col` yapısı bu sarmalayıcı
                          içinde artık gerilmiyor (stretch yalnızca çapraz eksende, yani dikeyde etkili). */}
                      <div className="flex justify-center">
                        {!role.is_editable ? (
                          <Checkbox
                            checked
                            disabled
                            aria-label={t('settings:permissions.ariaAlwaysGranted', { role: roleLabel(role.name, t), permission: permissionName })}
                          />
                        ) : (
                          <Checkbox
                            checked={hasPermission(role, permissionName)}
                            onChange={() => togglePermission(role, permissionName)}
                            disabled={updatePermissions.isPending && updatePermissions.variables?.roleId === role.id}
                            aria-label={t('settings:permissions.ariaToggle', { role: roleLabel(role.name, t), permission: permissionName })}
                          />
                        )}
                      </div>
                    </Td>
                  ))}
                </Tr>
              ))}
            </Fragment>
          ))}
        </TBody>
      </Table>

      <div className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Badge variant="neutral" size="sm">
          <ShieldCheck className="size-3" aria-hidden="true" /> {t('settings:permissions.readOnlyBadge')}
        </Badge>
        <span>{t('settings:permissions.readOnlyNote')}</span>
      </div>
    </div>
  )
}
