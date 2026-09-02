// Platform registry — `SYNCDESKTOP.md` §7.1. Defaults to `webPlatform`; NEVER imports
// `desktop.ts` (karar A3/D-1, `docs/DESKTOP-ARCHITECTURE.md` §11) so Tauri code cannot leak into
// the web bundle. A future desktop entry calls `setPlatform(desktopPlatform)` before rendering.
import { createContext, createElement, useContext, type ReactNode } from 'react'
import { webPlatform } from './web'
import type { Platform } from './types'

export type { Platform } from './types'

let currentPlatform: Platform = webPlatform

export function setPlatform(platform: Platform) {
  currentPlatform = platform
}

export function getPlatform(): Platform {
  return currentPlatform
}

const PlatformContext = createContext<Platform>(webPlatform)

/** Reads the platform set at entry time (before first render) — not reactive to later swaps. */
export function PlatformProvider({ children }: { children: ReactNode }) {
  return createElement(PlatformContext.Provider, { value: getPlatform() }, children)
}

export function usePlatform(): Platform {
  return useContext(PlatformContext)
}
