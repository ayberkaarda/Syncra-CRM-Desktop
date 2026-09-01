// The two record-scoped desktop actions — `SYNCDESKTOP.md` §6.4 items 5 (quote PDF cache) and
// 8 (screenshot to ticket).
//
// They live in the connectivity bar rather than on the record page for the reason
// `DesktopPanel.tsx` records as KARAR A27: `frontend/src/router.tsx` and the feature pages stay
// byte-for-byte the web's (K1), so a desktop-only button cannot be added to `QuoteDetailPage`.
// The bar is the shell's own chrome, it is already anchored bottom-left (where it clears the
// chat composer and a table's last row — see `ConnectivityBar.tsx`), and it is where the user
// already looks for anything the desktop adds.
//
// Which record is on screen comes from the pathname (`record-context.ts`), because the chrome
// is mounted outside `RouterProvider` and has no `useParams`.
import { useCallback, useState } from 'react'

import { toast } from '@/components/ui'
import { cn } from '@/lib/cn'

import { desktopData } from '../platform/data'

import { reportForOutcome } from './attach-report'
import { errorCodeOf, errorMessage } from './errors'
import { cacheQuotePdf, openCached, quotePdfNeedsRefresh, screenshotToTicket } from './files'
import { CameraIcon, FileTextIcon } from './icons'
import { quoteIdOf, recordContextOf } from './record-context'
import { useRoutePath } from './useRoutePath'
import { useT } from './useT'

const BUTTON_CLASS = cn(
  'rounded-md p-1.5 text-fg-muted',
  'transition-colors duration-150 motion-reduce:transition-none hover:bg-surface-2 hover:text-fg',
  'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 focus-visible:ring-offset-surface-1',
)

export function RecordActions() {
  const t = useT()
  const path = useRoutePath()
  const [busy, setBusy] = useState(false)

  const quoteId = quoteIdOf(path)
  const record = recordContextOf(path)
  const ticketId = record?.kind === 'ticket' ? record.id : null

  /**
   * Cache the quote's PDF and open it in the machine's PDF viewer.
   *
   * The quote is read from the LOCAL MIRROR first, not from the network: `revision` is what
   * names the cached file and `status` is what decides `refresh`, and both have to be known
   * before the command can run — reading them over HTTP would make the offline case, which is
   * the whole reason the cache exists, impossible.
   */
  const openQuotePdf = useCallback(
    (id: number) => {
      setBusy(true)
      void (async () => {
        try {
          const quote = await desktopData.quotes.get(id)
          const cached = await cacheQuotePdf(
            id,
            quote.revision,
            quotePdfNeedsRefresh(quote.status),
          )
          await openCached(cached.path)
          toast.success(
            t(cached.from_cache ? 'desktop:files.quotePdf.opened' : 'desktop:files.quotePdf.downloaded'),
          )
        } catch (error) {
          toast.error(
            `${t('desktop:files.quotePdf.error')} ${errorMessage(t, errorCodeOf(error))}`,
          )
        } finally {
          setBusy(false)
        }
      })()
    },
    [t],
  )

  /**
   * Capture the primary screen and post it into the ticket's conversation.
   *
   * No region: §6.4 item 8's selection overlay is not this strand's, and `screenshot_to_ticket`
   * documents `None` as "the whole primary screen" for exactly this case. The verdict is
   * rendered through the same table a dropped file uses, so an offline capture says "queued"
   * rather than pretending it was posted.
   */
  const captureToTicket = useCallback(
    (id: number) => {
      setBusy(true)
      void (async () => {
        try {
          const report = reportForOutcome(await screenshotToTicket(id))
          const sentence = t(report.key, { name: report.name })
          toast[report.level](
            report.code === null ? sentence : `${sentence} ${errorMessage(t, report.code)}`,
          )
        } catch (error) {
          toast.error(
            `${t('desktop:files.screenshot.error')} ${errorMessage(t, errorCodeOf(error))}`,
          )
        } finally {
          setBusy(false)
        }
      })()
    },
    [t],
  )

  if (quoteId === null && ticketId === null) return null

  return (
    <>
      <span className="mx-0.5 h-4 w-px bg-border-subtle" aria-hidden="true" />

      {quoteId !== null && (
        <button
          type="button"
          onClick={() => openQuotePdf(quoteId)}
          disabled={busy}
          title={t('desktop:files.quotePdf.action')}
          aria-label={t('desktop:files.quotePdf.action')}
          className={BUTTON_CLASS}
        >
          <FileTextIcon className="size-3.5" />
        </button>
      )}

      {ticketId !== null && (
        <button
          type="button"
          onClick={() => captureToTicket(ticketId)}
          disabled={busy}
          title={t('desktop:files.screenshot.action')}
          aria-label={t('desktop:files.screenshot.action')}
          className={BUTTON_CLASS}
        >
          <CameraIcon className="size-3.5" />
        </button>
      )}
    </>
  )
}
