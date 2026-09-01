// The desktop-only screens, in one modal panel.
//
// ## KARAR — these are NOT router routes, and that is deliberate
//
// `frontend/src/router.tsx` builds `createBrowserRouter([...])` at module scope and exports the
// finished router; `App.tsx` hands it to `RouterProvider`. React Router 7.18 offers no
// supported way to add a route to an ALREADY BUILT data router: `patchRoutesOnNavigation` is a
// creation-time option (it is not in the router object's public surface), and the only runtime
// hook, `router._internalSetRoutes`, is underscore-prefixed, absent from the published typings
// and exists for HMR. Building the desktop screens on it would make a react-router patch
// release able to blank three screens with no compile error.
//
// A route would also not be reachable: navigation to it lives in
// `frontend/src/components/layout/Sidebar.tsx`, which is equally outside this strand.
//
// So the desktop surface is mounted as SHELL CHROME around `App` instead — a fixed connectivity
// bar plus this panel — which needs zero `frontend/**` edits, works on every route including
// `/login`, and keeps `router.tsx` byte-for-byte the web's (K1: "UI yeniden yazımı yok").
import { useState } from 'react'

import { Badge, Modal, Tab, TabList, TabPanel, Tabs } from '@/components/ui'

import { ConflictInbox } from './panels/ConflictInbox'
import { DesktopPreferences } from './panels/DesktopPreferences'
import { DevicesPanel } from './panels/DevicesPanel'
import { PendingRecords } from './panels/PendingRecords'
import { StorageSettings } from './panels/StorageSettings'
import { useEngineStatus } from './useEngineStatus'
import { useT } from './useT'

export type DesktopPanelTab = 'pending' | 'conflicts' | 'storage' | 'devices' | 'preferences'

export interface DesktopPanelProps {
  open: boolean
  onClose: () => void
  /** Which tab to land on — the connectivity bar picks the one the current state is about. */
  initialTab?: DesktopPanelTab
}

export function DesktopPanel({ open, onClose, initialTab = 'pending' }: DesktopPanelProps) {
  const t = useT()
  const status = useEngineStatus()
  const [tab, setTab] = useState<DesktopPanelTab>(initialTab)

  const labels: Record<DesktopPanelTab, string> = {
    pending: t('desktop:sync.pendingChanges', { count: status.pending }),
    conflicts: t('desktop:conflicts.title'),
    storage: t('desktop:storage.title'),
    devices: t('desktop:devices.title'),
    preferences: t('desktop:preferences.title'),
  }

  return (
    <Modal open={open} onClose={onClose} title={labels[tab]} size="xl">
      <Tabs value={tab} onValueChange={(value) => setTab(value as DesktopPanelTab)}>
        <TabList className="mb-4">
          <Tab value="pending">
            <span className="flex items-center gap-2">
              {labels.pending}
              {status.pending > 0 && (
                <Badge variant="warning" size="sm">
                  {status.pending}
                </Badge>
              )}
            </span>
          </Tab>
          <Tab value="conflicts">
            <span className="flex items-center gap-2">
              {labels.conflicts}
              {status.conflicts > 0 && (
                <Badge variant="danger" size="sm">
                  {status.conflicts}
                </Badge>
              )}
            </span>
          </Tab>
          <Tab value="storage">{labels.storage}</Tab>
          <Tab value="devices">{labels.devices}</Tab>
          <Tab value="preferences">{labels.preferences}</Tab>
        </TabList>

        {/* `TabPanel` renders nothing while inactive (`components/ui/Tabs.tsx`), so each screen
            mounts when its tab is opened and its load runs then — no screen polls a command it
            is not showing. */}
        <TabPanel value="pending">
          <PendingRecords />
        </TabPanel>
        <TabPanel value="conflicts">
          <ConflictInbox />
        </TabPanel>
        <TabPanel value="storage">
          <StorageSettings />
        </TabPanel>
        <TabPanel value="devices">
          <DevicesPanel />
        </TabPanel>
        {/* §6.4's configurable shell behaviour: the quick-capture hotkey, and (from F5-4/F5-6)
            close-to-tray and clipboard capture. Separate from `storage` because it saves
            through a different path — see the module doc. */}
        <TabPanel value="preferences">
          <DesktopPreferences />
        </TabPanel>
      </Tabs>
    </Modal>
  )
}
