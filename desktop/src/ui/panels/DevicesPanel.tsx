// Devices — `SYNCDESKTOP.md` §7.2, fifth item; `§4.3` for the endpoints.
//
// `auth::list_devices` / `auth::revoke_device` go straight to `GET /api/me/devices` and
// `DELETE /api/me/devices/{token}` with the keychain bearer token; they are NOT engine calls
// and have no local mirror, so this is the one screen in the panel that genuinely needs the
// network. Offline it renders `desktop.errors.OFFLINE` instead of an empty table, because
// "no devices" and "could not ask" are different facts and the empty state would assert the
// wrong one.
//
// `is_current` is computed server-side (`commands::auth::DeviceSummary`) by comparing the row
// against the token the request authenticated with, which is why "this device" needs no local
// fingerprint comparison here.
import { useCallback, useEffect, useState } from 'react'

import { Badge, Button, EmptyState, TBody, THead, Table, Td, Th, Tr, Modal, toast } from '@/components/ui'

import type { DeviceSummary } from '../commands'
import { listDevices, revokeDevice } from '../commands'
import { errorCodeOf, errorMessage } from '../errors'
import { formatDateTime } from '../format'
import { DevicesIcon } from '../icons'
import { useEngineStatus } from '../useEngineStatus'
import { useIntlLocale, useT } from '../useT'

export function DevicesPanel() {
  const t = useT()
  const locale = useIntlLocale()
  const status = useEngineStatus()

  const [devices, setDevices] = useState<DeviceSummary[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [revoking, setRevoking] = useState<DeviceSummary | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    if (!status.online) {
      setDevices(null)
      setLoadError(t('desktop:errors.OFFLINE'))
      return
    }
    try {
      setDevices(await listDevices())
      setLoadError(null)
    } catch (error) {
      setDevices(null)
      setLoadError(`${t('desktop:devices.loadError')} ${errorMessage(t, errorCodeOf(error))}`)
    }
  }, [status.online, t])

  useEffect(() => {
    void load()
  }, [load])

  async function handleRevoke(device: DeviceSummary): Promise<void> {
    setBusy(true)
    try {
      await revokeDevice(device.id)
      toast.success(t('desktop:devices.revokeSuccess'))
    } catch (error) {
      toast.error(`${t('desktop:devices.revokeError')} ${errorMessage(t, errorCodeOf(error))}`)
    } finally {
      setBusy(false)
      setRevoking(null)
      await load()
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-fg-muted">{t('desktop:devices.subtitle')}</p>

      {loadError && (
        <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">{loadError}</p>
      )}

      {devices === null && !loadError && (
        <p className="text-sm text-fg-muted">{t('common:states.loading')}</p>
      )}

      {devices !== null &&
        (devices.length === 0 ? (
          <EmptyState
            icon={<DevicesIcon className="size-6" />}
            title={t('desktop:devices.empty.title')}
            description={t('desktop:devices.empty.description')}
          />
        ) : (
          <Table>
            <THead>
              <Tr>
                <Th>{t('desktop:devices.columns.name')}</Th>
                <Th>{t('desktop:devices.columns.platform')}</Th>
                <Th>{t('desktop:devices.columns.lastUsed')}</Th>
                <Th align="right">{t('desktop:devices.columns.actions')}</Th>
              </Tr>
            </THead>
            <TBody>
              {devices.map((device) => (
                <Tr key={device.id}>
                  <Td>
                    <div className="flex items-center gap-2">
                      <span className="text-fg">{device.name}</span>
                      {device.is_current && (
                        <Badge variant="primary" size="sm">
                          {t('desktop:devices.thisDevice')}
                        </Badge>
                      )}
                    </div>
                  </Td>
                  {/* `platform` is `windows` | `macos` | `linux` straight off the token row — an
                      identifier the server stores, not a translated label. */}
                  <Td className="font-mono text-xs text-fg-muted">{device.platform ?? '—'}</Td>
                  <Td className="text-fg-muted">
                    {formatDateTime(locale, device.last_used_at ?? device.created_at) ?? '—'}
                  </Td>
                  <Td align="right">
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-danger"
                      disabled={busy}
                      onClick={() => setRevoking(device)}
                    >
                      {t('desktop:devices.revoke')}
                    </Button>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        ))}

      <Modal
        open={revoking !== null}
        onClose={() => setRevoking(null)}
        title={t('desktop:devices.revokeConfirm.title')}
        description={t('desktop:devices.revokeConfirm.description')}
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRevoking(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={busy}
              onClick={() => {
                if (revoking) void handleRevoke(revoking)
              }}
            >
              {t('common:actions.confirm')}
            </Button>
          </div>
        }
      >
        {revoking && (
          // Same shape as the settings tabs' delete confirmations
          // (`features/settings/components/AutomationRulesTab.tsx:179`): bolded subject, then
          // the `confirmSuffix` that completes the sentence.
          <p className="text-sm text-fg-secondary">
            <strong className="text-fg">{revoking.name}</strong>{' '}
            {t('desktop:devices.revokeConfirm.confirmSuffix')}
          </p>
        )}
      </Modal>
    </div>
  )
}
