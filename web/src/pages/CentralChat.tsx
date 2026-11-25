import { Link, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Headset } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { RoomChat } from '@/components/roomchat/RoomChat'
import { useAuth } from '@/hooks/useAuth'

/**
 * Conversa com a Central (Outubro 2026): canal direto entre o utilizador e
 * a direção do KeruGás. A Central começa a conversa; o utilizador responde.
 */
export default function CentralChat() {
  const { user } = useAuth()
  const [params] = useSearchParams()
  const queryClient = useQueryClient()
  const focusId = Number(params.get('mensagem')) || undefined

  if (!user) return null

  return (
    <div className="mx-auto max-w-2xl px-4 py-6">
      <Link to="/perfil" className="mb-3 inline-flex items-center gap-1 text-sm text-gray hover:text-dark"><ArrowLeft size={15} /> Voltar</Link>
      <RoomChat
        type="central"
        roomId={user.id}
        focusId={focusId}
        header={() => (
          <header className="flex items-start gap-3 rounded-2xl bg-gradient-to-br from-primary to-secondary p-4 text-white">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/15"><Headset size={22} aria-hidden="true" /></span>
            <div className="min-w-0">
              <h1 className="text-lg font-bold">Conversa com a Central</h1>
              <p className="text-sm text-white/90">Mensagens diretas da direção do KeruGás para si. Pode responder aqui.</p>
            </div>
          </header>
        )}
        emptyTitle="Ainda não há mensagens da Central"
        emptyText="Quando a direção do KeruGás lhe escrever, a conversa aparece aqui."
        readOnlyText="Pode responder assim que a Central lhe escrever."
        placeholder="Escreva a sua resposta à Central…"
        onSent={() => void queryClient.invalidateQueries({ queryKey: ['central-me'] })}
      />
    </div>
  )
}
