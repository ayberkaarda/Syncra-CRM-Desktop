// Online kullanıcılar listesi — TanStack Query ile `/api/presence/online`'ı
// çeker. Tazeleme OLAY TABANLI: `presence-online` kanalındaki katılma/ayrılma
// `usePresence()` üzerinden izlenir (aynı kanala ikinci bir Echo join/leave
// AÇMAZ — `Echo.leave()` referans saymadan kanalı tamamen kapattığı için iki
// bağımsız hook aynı kanala katılıp ayrılsaydı biri diğerinin dinleyicilerini
// altından çekerdi). Üyelik imzası (id kümesi) değiştiğinde query invalidate
// edilir; `staleTime` yalnızca Echo hiç bağlanamazsa devreye giren bir
// güvenlik ağıdır — düzenli `refetchInterval` YOKTUR.
import { useEffect, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchOnlineUsers } from '../api/presenceApi'
import type { OnlineUsersResponse } from '../api/presenceApi'
import { usePresence } from '../../../hooks/usePresence'

export const onlineUsersKey = ['presence', 'online'] as const

function membershipSignature(memberIds: number[]): string {
  return [...memberIds].sort((a, b) => a - b).join(',')
}

export type UseOnlineUsersResult = {
  users: OnlineUsersResponse['data']
  meta: OnlineUsersResponse['meta'] | undefined
  isLoading: boolean
  isError: boolean
}

export function useOnlineUsers(): UseOnlineUsersResult {
  const queryClient = useQueryClient()
  const { members } = usePresence()
  const signature = membershipSignature(members.map((member) => member.id))
  const previousSignature = useRef<string | null>(null)

  const query = useQuery({
    queryKey: onlineUsersKey,
    queryFn: fetchOnlineUsers,
    // Güvenlik ağı: normalde invalidate join/leave olaylarıyla tetiklenir.
    staleTime: 5 * 60_000,
  })

  useEffect(() => {
    if (previousSignature.current === null) {
      // İlk `here` anlık görüntüsü — REST çağrısı zaten ilk boyayı sağlıyor,
      // gereksiz bir ikinci istek atmaya gerek yok.
      previousSignature.current = signature
      return
    }
    if (previousSignature.current !== signature) {
      previousSignature.current = signature
      void queryClient.invalidateQueries({ queryKey: onlineUsersKey })
    }
  }, [signature, queryClient])

  return {
    users: query.data?.data ?? [],
    meta: query.data?.meta,
    isLoading: query.isLoading,
    isError: query.isError,
  }
}
