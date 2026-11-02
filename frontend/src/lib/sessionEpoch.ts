/**
 * Which sign-in the client is currently in, as a number that only goes up.
 *
 * `lib/axios.ts` stamps every request with the value current when it was sent, and acts on a
 * 401 only if the stamp still matches. Without that, a slow request from user A that answers
 * 401 after user B signed in would clear B's auth store and send B to `/login`.
 *
 * The auth store (`features/auth/store.ts`) advances it whenever the signed-in identity
 * changes: on every `clear()` (logout, 401, deactivation) and whenever `setUser` replaces the
 * user with a different one (login, or the first `/api/me`). Kept in its own module so the
 * store and `lib/axios.ts` can share it without either importing the other.
 */
let epoch = 0

/** The sign-in requests sent now belong to. */
export function currentSessionEpoch(): number {
  return epoch
}

/** A different sign-in begins; requests sent before this call belong to the previous one. */
export function advanceSessionEpoch(): void {
  epoch += 1
}
