import { useState } from 'react'
import { Avatar } from '@/components/Avatar'
import { Sheet } from '@/components/vizinhos/Sheet'
import type { VzMessage } from '@/types/vizinhos'

/**
 * Quem reagiu a uma mensagem (Outubro 2026): qualquer participante vê
 * quantas reações há e quem reagiu, com separadores por emoji.
 */
export function ReactionsSheet({ message, onClose }: { message: VzMessage; onClose: () => void }) {
  const reactors = message.reactors ?? []
  const total = Object.values(message.reactions).reduce((a, b) => a + b, 0)
  const emojis = Object.entries(message.reactions).sort((a, b) => b[1] - a[1])
  const [tab, setTab] = useState<string | null>(null)
  const list = tab ? reactors.filter((r) => r.emoji === tab) : reactors

  return (
    <Sheet title={`${total} ${total === 1 ? 'reação' : 'reações'}`} onClose={onClose}>
      <div role="tablist" aria-label="Filtrar por reação" className="mb-3 flex flex-wrap gap-1.5">
        <button
          type="button"
          role="tab"
          aria-selected={tab === null}
          onClick={() => setTab(null)}
          className={`rounded-full px-3 py-1 text-xs font-semibold ${tab === null ? 'bg-primary text-white' : 'bg-light text-dark'}`}
        >
          Todas {total}
        </button>
        {emojis.map(([e, n]) => (
          <button
            key={e}
            type="button"
            role="tab"
            aria-selected={tab === e}
            onClick={() => setTab(e)}
            className={`rounded-full px-3 py-1 text-xs font-semibold ${tab === e ? 'bg-primary text-white' : 'bg-light text-dark'}`}
          >
            {e} {n}
          </button>
        ))}
      </div>
      {list.length === 0 ? (
        <p className="py-4 text-center text-sm text-gray">Ainda ninguém reagiu.</p>
      ) : (
        <ul className="divide-y divide-gray/10">
          {list.map((r) => (
            <li key={`${r.user_id}-${r.emoji}`} className="flex items-center gap-3 py-2">
              <Avatar name={r.name} url={r.avatar_url} size="sm" />
              <span className="min-w-0 flex-1 truncate text-sm font-medium text-dark">{r.me ? `${r.name} (você)` : r.name}</span>
              <span className="text-lg" aria-label={`reagiu com ${r.emoji}`}>{r.emoji}</span>
            </li>
          ))}
        </ul>
      )}
      {reactors.length < total && (
        <p className="mt-2 text-center text-xs text-gray">A mostrar as primeiras {reactors.length} pessoas.</p>
      )}
    </Sheet>
  )
}
