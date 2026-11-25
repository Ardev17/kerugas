import { useState } from 'react'
import { CornerUpLeft, EyeOff, MoreHorizontal, Pin, ShieldAlert, Trash2 } from 'lucide-react'
import { Avatar } from '@/components/Avatar'
import { RoleIcon } from '@/components/illustrations/StaffChat'
import { LocationCard } from '@/components/vizinhos/LocationCard'
import { ReactionsSheet } from '@/components/vizinhos/ReactionsSheet'
import { useApiImage } from '@/lib/apiImage'
import { BADGE_LABEL, clockTime, splitBody } from '@/lib/vizinhos'
import type { VzMessage } from '@/types/vizinhos'

interface Props {
  message: VzMessage
  emojis: string[]
  topicLabel?: string
  highlighted: boolean
  onReply: (m: VzMessage) => void
  onMore: (m: VzMessage) => void
  onReact: (m: VzMessage, emoji: string | null) => void
  onJump: (id: number) => void
  onImage: (url: string) => void
  canReact: boolean
  /** Parar a partilha de localização (só aparece a quem partilhou). */
  onStopLocation?: (m: VzMessage) => void
  stoppingLocation?: boolean
}

/** Chat da Equipa: cor do tipo de admin. */
const roleCls: Record<string, string> = {
  SUPER_ADMIN: 'bg-dark text-white',
  PROVINCE_SUPER_ADMIN: 'bg-primary text-white',
  ADMIN: 'bg-secondary/15 text-secondary',
}

const badgeCls: Record<string, string> = {
  team: 'bg-primary text-white',
  moderator: 'bg-secondary/15 text-secondary',
  driver: 'bg-amber-100 text-amber-800',
  station: 'bg-emerald-100 text-emerald-800',
}

function Body({ message }: { message: VzMessage }) {
  if (!message.body) return null
  return (
    <p className="mt-1 whitespace-pre-wrap break-words text-sm text-dark">
      {splitBody(message.body, message.mentions).map((p, i) =>
        p.type === 'text' ? (
          <span key={i}>{p.text}</span>
        ) : (
          <span key={i} className="rounded bg-primary/10 px-1 font-medium text-primary">@{p.name}</span>
        ),
      )}
    </p>
  )
}

/** Foto da mensagem,passa pelo endereço da API (ver lib/apiImage). */
function MessageImage({ url }: { url: string }) {
  const image = useApiImage(url)
  if (image.failed) {
    return <span className="flex h-24 w-40 items-center justify-center rounded-xl bg-light px-2 text-center text-xs text-gray">Não foi possível carregar a foto</span>
  }
  if (!image.src) {
    return <span className="block h-24 w-40 animate-pulse rounded-xl bg-light" role="img" aria-label="A carregar a foto" />
  }
  return <img src={image.src} alt="Foto partilhada na mensagem" decoding="async" referrerPolicy="no-referrer" className="max-h-64 w-auto max-w-full rounded-xl bg-light object-cover" />
}

/** Uma mensagem dos Vizinhos KeruGás. */
export function MessageCard({ message: m, emojis, topicLabel, highlighted, onReply, onMore, onReact, onJump, onImage, canReact, onStopLocation, stoppingLocation }: Props) {
  const [showReactors, setShowReactors] = useState(false)
  // Mensagens removidas/em revisão para quem não modera: só um aviso.
  if (!m.author && m.status !== 'visible') {
    return (
      <li id={`vz-msg-${m.id}`} className="flex items-center gap-2 rounded-xl bg-light px-3 py-2 text-xs italic text-gray">
        {m.status === 'hidden' ? <EyeOff size={14} aria-hidden="true" /> : <ShieldAlert size={14} aria-hidden="true" />}
        {m.status === 'hidden' ? 'Mensagem escondida até a moderação a rever.' : 'Mensagem removida pela moderação.'}
      </li>
    )
  }

  const reactions = Object.entries(m.reactions)
  const totalReactions = reactions.reduce((a, [, n]) => a + n, 0)
  const flagged = m.status !== 'visible'

  return (
    <li
      id={`vz-msg-${m.id}`}
      className={`fx-msg-in rounded-2xl border p-3 transition-colors ${highlighted ? 'border-primary bg-primary/10' : m.mentions_me ? 'border-primary/40 bg-primary/5' : 'border-gray/15 bg-white'} ${flagged ? 'opacity-80' : ''}`}
    >
      {m.author && (
        <div className="flex items-start gap-2.5">
          <Avatar name={m.author.name} url={m.author.avatar_url} size="sm" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
              <span className="truncate text-sm font-semibold text-dark">{m.author.name}</span>
              {m.author.type_label && (
                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${roleCls[m.author.role ?? ''] ?? 'bg-light text-dark'}`}>
                  <RoleIcon role={m.author.role} size={11} />{m.author.type_label}
                </span>
              )}
              {!m.author.type_label && m.author.badge && (
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${badgeCls[m.author.badge]}`}>{BADGE_LABEL[m.author.badge]}</span>
              )}
              {m.pinned && <Pin size={12} className="text-amber-600" aria-label="Aviso fixado" />}
            </div>
            <p className="text-[11px] text-gray">
              {clockTime(m.created_at)}
              {m.author.neighborhood ? ` · ${m.author.neighborhood}` : ''}
              {m.author.province ? ` · ${m.author.province}` : ''}
              {m.author.municipality ? ` · ${m.author.municipality}` : ''}
              {m.neighborhood ? ` · para ${m.neighborhood.name}` : ''}
              {m.edited ? ' · editada' : ''}
            </p>
          </div>
          <button
            type="button"
            onClick={() => onMore(m)}
            aria-label="Mais ações"
            className="-mr-1 rounded-full p-1.5 text-gray hover:bg-light"
          >
            <MoreHorizontal size={18} />
          </button>
        </div>
      )}

      {flagged && (
        <p className="mt-2 flex items-center gap-1.5 rounded-lg bg-danger/10 px-2 py-1 text-xs text-danger">
          {m.status === 'deleted' ? <Trash2 size={12} aria-hidden="true" /> : <ShieldAlert size={12} aria-hidden="true" />}
          {m.status === 'deleted' && 'Apagada pelo autor'}
          {m.status === 'removed' && `Removida pela moderação${m.removal_reason ? `: ${m.removal_reason}` : ''}`}
          {m.status === 'hidden' && 'Escondida (3 denúncias),a aguardar revisão'}
        </p>
      )}

      {m.reply_to && (
        <button
          type="button"
          onClick={() => m.reply_to?.available && onJump(m.reply_to.id)}
          className="mt-2 block w-full rounded-lg border-l-4 border-primary/50 bg-light px-2.5 py-1.5 text-left text-xs"
        >
          {m.reply_to.available ? (
            <>
              <span className="font-semibold text-dark">{m.reply_to.author_name}</span>
              <span className="block truncate text-gray">{m.reply_to.excerpt}</span>
            </>
          ) : (
            <span className="italic text-gray">Mensagem já não disponível</span>
          )}
        </button>
      )}

      {topicLabel && (
        <span className="mt-2 inline-block rounded-full bg-light px-2 py-0.5 text-[11px] font-medium text-gray">{topicLabel}</span>
      )}

      <Body message={m} />

      {m.location && <LocationCard message={m} onStop={onStopLocation} stopping={stoppingLocation} />}

      {m.image_url && (
        <button type="button" onClick={() => m.image_url && onImage(m.image_url)} className="mt-2 block overflow-hidden rounded-xl" aria-label="Ver foto em ecrã inteiro">
          <MessageImage url={m.image_url} />
        </button>
      )}
      {m.has_image && !m.image_url && m.status !== 'visible' && <p className="mt-2 text-xs italic text-gray">[Tinha uma foto,já não é mostrada]</p>}

      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        {reactions.map(([emoji, n]) => (
          <button
            key={emoji}
            type="button"
            disabled={!canReact || m.status !== 'visible'}
            onClick={() => onReact(m, m.my_reaction === emoji ? null : emoji)}
            aria-pressed={m.my_reaction === emoji}
            className={`rounded-full border px-2 py-0.5 text-xs transition-transform hover:scale-110 ${m.my_reaction === emoji ? 'fx-react-pop border-primary bg-primary/10 text-primary' : 'border-gray/20 text-dark'}`}
          >
            {emoji} {n}
          </button>
        ))}
        {canReact && m.status === 'visible' && (
          <span className="flex items-center gap-0.5">
            {emojis.filter((e) => !(e in m.reactions)).map((e) => (
              <button key={e} type="button" onClick={() => onReact(m, e)} aria-label={`Reagir com ${e}`} className="rounded-full px-1.5 py-0.5 text-sm opacity-60 transition-transform hover:scale-125 hover:bg-light hover:opacity-100">
                {e}
              </button>
            ))}
          </span>
        )}
        {totalReactions > 0 && m.reactors && (
          <button
            type="button"
            onClick={() => setShowReactors(true)}
            aria-label={`Ver quem reagiu (${totalReactions})`}
            className="rounded-full px-2 py-0.5 text-[11px] font-medium text-gray underline-offset-2 hover:bg-light hover:underline"
          >
            {totalReactions === 1 ? '1 reação' : `${totalReactions} reações`}
          </button>
        )}
        {m.status === 'visible' && canReact && (
          <button type="button" onClick={() => onReply(m)} className="ml-auto flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium text-primary hover:bg-primary/10">
            <CornerUpLeft size={13} aria-hidden="true" /> Responder
          </button>
        )}
      </div>
      {showReactors && <ReactionsSheet message={m} onClose={() => setShowReactors(false)} />}
    </li>
  )
}
