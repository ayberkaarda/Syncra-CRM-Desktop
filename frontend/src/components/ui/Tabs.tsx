// Sekme bileşik bileşeni — Tabs/TabList/Tab/TabPanel. `underline` (NOTES/ALERTS/CHAT) ve
// `segment` (Week/Month/Year pill grubu) varyantları, ok tuşlarıyla klavye gezinmesi.
import { createContext, forwardRef, useContext, useId, useMemo, useRef } from 'react'
import type { HTMLAttributes, KeyboardEvent, ReactNode } from 'react'
import { cn } from '../../lib/cn'

export type TabsVariant = 'underline' | 'segment'

type TabsContextValue = {
  value: string
  onValueChange: (value: string) => void
  variant: TabsVariant
  idPrefix: string
  registerTab: (value: string) => void
  getTabValues: () => string[]
}

const TabsContext = createContext<TabsContextValue | null>(null)

function useTabsContext(component: string): TabsContextValue {
  const ctx = useContext(TabsContext)
  if (!ctx) throw new Error(`${component}, bir <Tabs> içinde kullanılmalı`)
  return ctx
}

export type TabsProps = {
  value: string
  onValueChange: (value: string) => void
  variant?: TabsVariant
  children: ReactNode
}

export function Tabs({ value, onValueChange, variant = 'underline', children }: TabsProps) {
  const idPrefix = useId()
  const orderRef = useRef<string[]>([])

  const contextValue = useMemo<TabsContextValue>(
    () => ({
      value,
      onValueChange,
      variant,
      idPrefix,
      registerTab: (tabValue) => {
        if (!orderRef.current.includes(tabValue)) orderRef.current.push(tabValue)
      },
      getTabValues: () => orderRef.current,
    }),
    [value, onValueChange, variant, idPrefix]
  )

  return <TabsContext.Provider value={contextValue}>{children}</TabsContext.Provider>
}

export type TabListProps = HTMLAttributes<HTMLDivElement>

export const TabList = forwardRef<HTMLDivElement, TabListProps>(({ className, ...props }, ref) => {
  const { variant } = useTabsContext('TabList')

  return (
    <div
      ref={ref}
      role="tablist"
      className={cn(
        'flex items-center',
        variant === 'underline' && 'gap-6 border-b border-border-subtle',
        variant === 'segment' && 'gap-1 rounded-md bg-surface-2 p-1',
        className
      )}
      {...props}
    />
  )
})

TabList.displayName = 'TabList'

export type TabProps = {
  value: string
  children: ReactNode
} & Omit<HTMLAttributes<HTMLButtonElement>, 'children'>

export const Tab = forwardRef<HTMLButtonElement, TabProps>(({ className, value, children, ...props }, ref) => {
  const { value: activeValue, onValueChange, variant, idPrefix, registerTab, getTabValues } = useTabsContext('Tab')
  registerTab(value)

  const isActive = value === activeValue
  const tabId = `${idPrefix}-tab-${value}`
  const panelId = `${idPrefix}-panel-${value}`

  function handleKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    const values = getTabValues()
    const currentIndex = values.indexOf(value)
    let nextIndex: number

    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        nextIndex = (currentIndex + 1) % values.length
        break
      case 'ArrowLeft':
      case 'ArrowUp':
        nextIndex = (currentIndex - 1 + values.length) % values.length
        break
      case 'Home':
        nextIndex = 0
        break
      case 'End':
        nextIndex = values.length - 1
        break
      default:
        return
    }

    event.preventDefault()
    const nextValue = values[nextIndex]
    onValueChange(nextValue)
    const nextTab = document.getElementById(`${idPrefix}-tab-${nextValue}`)
    nextTab?.focus()
  }

  return (
    <button
      ref={ref}
      type="button"
      role="tab"
      id={tabId}
      aria-selected={isActive}
      aria-controls={panelId}
      tabIndex={isActive ? 0 : -1}
      onClick={() => onValueChange(value)}
      onKeyDown={handleKeyDown}
      className={cn(
        'inline-flex items-center gap-1.5 whitespace-nowrap font-medium',
        'transition-colors duration-150 motion-reduce:transition-none',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1 rounded-sm',
        variant === 'underline' &&
          cn(
            'pb-3 text-sm border-b-2 -mb-px',
            isActive ? 'border-primary text-primary' : 'border-transparent text-fg-muted hover:text-fg'
          ),
        variant === 'segment' &&
          cn(
            'rounded-md px-3 py-1.5 text-sm',
            isActive ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:text-fg'
          ),
        className
      )}
      {...props}
    >
      {children}
    </button>
  )
})

Tab.displayName = 'Tab'

export type TabPanelProps = {
  value: string
  children: ReactNode
} & Omit<HTMLAttributes<HTMLDivElement>, 'children'>

export const TabPanel = forwardRef<HTMLDivElement, TabPanelProps>(({ className, value, children, ...props }, ref) => {
  const { value: activeValue, idPrefix } = useTabsContext('TabPanel')
  const isActive = value === activeValue
  const tabId = `${idPrefix}-tab-${value}`
  const panelId = `${idPrefix}-panel-${value}`

  if (!isActive) return null

  return (
    <div ref={ref} role="tabpanel" id={panelId} aria-labelledby={tabId} tabIndex={0} className={cn(className)} {...props}>
      {children}
    </div>
  )
})

TabPanel.displayName = 'TabPanel'
