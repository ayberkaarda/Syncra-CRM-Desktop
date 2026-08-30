// Global Ctrl+K / Cmd+K kısayolu — Faz 14 / İz F / C1.
//
// KISAYOL HİJYENİ (görev tanımı): input/textarea/select/contenteditable odaktayken VEYA başka
// bir modal açıkken kısayol ÇAKIŞMAMALI. İkisi de burada kontrol edilir; dinleyici `AppLayout`
// unmount olduğunda temizlenir (bkz. bu hook'un `useEffect` cleanup'ı).
import { useEffect } from 'react'

function isEditableTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target.isContentEditable) return true
  const tag = target.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
}

/**
 * Komut paleti kendisi de `role="dialog"` render eder (bkz. `CommandPalette.tsx`); bu kontrol
 * palet AÇILMADAN ÖNCE çağrıldığı için mevcut bir `[role="dialog"]` her zaman BAŞKA bir modaldır
 * (form modalı, silme onayı vb. — bkz. `components/ui/Modal.tsx`). Üstüne ikinci bir katman
 * açmak Esc/odak davranışını karıştırır; bu yüzden böyle bir modal açıkken kısayol yok sayılır.
 */
function isAnotherDialogOpen(): boolean {
  return document.querySelector('[role="dialog"]') !== null
}

export function useCommandPaletteShortcut(onOpen: () => void): void {
  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      const isShortcut = (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k'
      if (!isShortcut) return
      if (isEditableTarget(event.target)) return
      if (isAnotherDialogOpen()) return

      event.preventDefault()
      onOpen()
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [onOpen])
}
