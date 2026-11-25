import { MessagesSquare } from 'lucide-react'
import { RoomChat } from '@/components/roomchat/RoomChat'

/**
 * Conversa dos condutores de um município (Outubro 2026): condutores do
 * município e admins da província. Menções, fotos, localização e reações,
 * como nos Vizinhos.
 */
export function DriversRoomChat({ municipalityId, municipalityLabel, focusId }: { municipalityId: number; municipalityLabel?: string | null; focusId?: number }) {
  return (
    <section id="conversa-condutores" aria-label="Conversa dos condutores" className="scroll-mt-20 rounded-2xl border border-gray/15 bg-light/60 p-3 sm:p-4">
      <RoomChat
        type="drivers"
        roomId={municipalityId}
        focusId={focusId}
        header={() => (
          <div className="flex items-start gap-3 rounded-xl bg-white p-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><MessagesSquare size={20} aria-hidden="true" /></span>
            <div className="min-w-0">
              <p className="text-sm font-semibold text-dark">Conversa dos condutores{municipalityLabel ? ` · ${municipalityLabel}` : ''}</p>
              <p className="text-xs text-gray">Pergunte por locais e referências, partilhe a localização ou uma foto. Use @ para mencionar um colega.</p>
            </div>
          </div>
        )}
        emptyTitle="Ainda não há mensagens neste município"
        emptyText="Seja o primeiro a escrever aos colegas."
        placeholder="Ex.: Alguém conhece a Rua das Palmeiras? Use @ para mencionar"
      />
    </section>
  )
}
