// Who is signed in, for the handful of DTO fields that are per-viewer.
//
// `Conversation.unread_count` / `is_muted` come from *my* `conversation_user` row,
// `SavedView.is_mine` compares against *my* user id, and a DM's display name is the *other*
// party's. None of that can be answered without the session.
//
// The source is the app's own auth store (`features/auth/store.ts`), read imperatively —
// zustand stores are plain objects outside React, and this is the same pattern
// `router.tsx:317-322` uses to reach auth handlers from outside the tree. Reading it rather
// than keeping a second copy is deliberate: two copies of "who am I" drift, and the one that
// drifts is always the one nothing re-renders.
import { useAuthStore } from '@/features/auth/store'

/**
 * The signed-in user's server id, or `undefined` before `/api/me` has answered.
 *
 * Every consumer treats `undefined` as "not known yet" and degrades to a neutral value
 * (unread `0`, `is_mine` false) rather than guessing.
 */
export function sessionUserId(): number | undefined {
  const id = useAuthStore.getState().user?.id
  return typeof id === 'number' ? id : undefined
}
