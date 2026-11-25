import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/hooks/useAuth'
import { isManagement } from '@/lib/roles'
import { roomChatService } from '@/services/roomChatService'

/**
 * "Conversa com a Central" (Outubro 2026): o botão só aparece ao utilizador
 * depois de a direção lhe escrever. Verifica de vez em quando, sem pressa.
 */
export function useCentralChat() {
  const { user, isAuthenticated } = useAuth()
  const enabled = isAuthenticated && !!user && !isManagement(user)
  const query = useQuery({
    queryKey: ['central-me'],
    queryFn: roomChatService.centralMe,
    enabled,
    staleTime: 60_000,
    refetchInterval: 180_000,
    retry: false,
  })

  return { available: enabled && !!query.data?.available, unread: query.data?.unread ?? 0 }
}
