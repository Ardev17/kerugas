import { useState } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowLeft, Loader2, MessageSquareQuote, ShieldCheck, Star, TrendingDown, TrendingUp } from 'lucide-react'
import { driverService } from '@/services/driverService'
import type { DriverRatingsSummary } from '@/types'

const STARS = [5, 4, 3, 2, 1] as const

const fmt = (n: number) => n.toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

function Stars({ value, size = 14 }: { value: number; size?: number }) {
  const full = Math.round(value)
  return (
    <span className="inline-flex items-center gap-0.5" role="img" aria-label={`${fmt(value)} de 5 estrelas`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <Star key={i} size={size} aria-hidden="true" className={i <= full ? 'fill-amber-400 text-amber-400' : 'text-gray/30'} />
      ))}
    </span>
  )
}

function formatDay(day: string): string {
  // "AAAA-MM-DD" lido como data local (sem fuso), para nunca mudar de dia
  const [y, m, d] = day.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' })
}

function Summary({ summary, stars, onStars }: { summary: DriverRatingsSummary; stars: number | undefined; onStars: (s: number | undefined) => void }) {
  const { average, count, with_comment, distribution, last_30_days: recent } = summary
  const max = Math.max(1, ...STARS.map((s) => distribution[String(s)] ?? 0))
  const trend = average != null && recent.average != null && recent.count > 0 ? recent.average - average : null

  return (
    <section aria-label="Resumo das avaliações" className="mt-4 rounded-2xl border border-gray/15 bg-white p-4">
      <div className="flex items-center gap-5">
        <div className="text-center">
          <p className="text-4xl font-bold tabular-nums text-dark">{average != null ? fmt(average) : '—'}</p>
          {average != null && <Stars value={average} />}
          <p className="mt-1 text-xs text-gray">
            {count} {count === 1 ? 'avaliação' : 'avaliações'}
          </p>
        </div>

        <ul className="min-w-0 flex-1 space-y-1">
          {STARS.map((s) => {
            const n = distribution[String(s)] ?? 0
            const active = stars === s
            return (
              <li key={s}>
                <button
                  type="button"
                  onClick={() => onStars(active ? undefined : s)}
                  aria-pressed={active}
                  aria-label={`${s} estrelas: ${n}`}
                  disabled={n === 0 && !active}
                  className={`flex w-full items-center gap-2 rounded-md px-1 py-0.5 text-xs disabled:opacity-50 ${active ? 'bg-amber-400/15' : 'hover:bg-light'}`}
                >
                  <span className="w-3 text-right font-medium tabular-nums text-dark">{s}</span>
                  <Star size={11} aria-hidden="true" className="shrink-0 fill-amber-400 text-amber-400" />
                  <span className="h-2 flex-1 overflow-hidden rounded-full bg-gray/10">
                    <span className="block h-full rounded-full bg-amber-400" style={{ width: `${(n / max) * 100}%` }} />
                  </span>
                  <span className="w-6 text-right tabular-nums text-gray">{n}</span>
                </button>
              </li>
            )
          })}
        </ul>
      </div>

      {(recent.count > 0 || with_comment > 0) && (
        <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t border-gray/10 pt-3 text-xs text-gray">
          {recent.count > 0 && recent.average != null && (
            <span className="flex items-center gap-1">
              {trend != null && trend >= 0.05 && <TrendingUp size={14} className="text-success" aria-hidden="true" />}
              {trend != null && trend <= -0.05 && <TrendingDown size={14} className="text-danger" aria-hidden="true" />}
              Últimos 30 dias: <strong className="text-dark">{fmt(recent.average)}</strong> ({recent.count})
            </span>
          )}
          {with_comment > 0 && (
            <span className="flex items-center gap-1">
              <MessageSquareQuote size={14} aria-hidden="true" /> {with_comment} com comentário
            </span>
          )}
        </div>
      )}
    </section>
  )
}

/**
 * As avaliações do condutor (Outubro 2026): o resumo (média, estrelas de 5
 * a 1, tendência dos últimos 30 dias) e cada avaliação com o comentário.
 * Para proteger quem avalia, nunca aparece o nome, o pedido nem a hora —
 * só o dia (o servidor nem sequer os envia).
 */
export default function DriverRatings() {
  const [stars, setStars] = useState<number | undefined>(undefined)

  const query = useInfiniteQuery({
    queryKey: ['driver-ratings', stars ?? 'all'],
    queryFn: ({ pageParam }) => driverService.getRatings(pageParam, stars),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
    // ao trocar o filtro, o resumo fica no ecrã enquanto a lista nova chega
    placeholderData: (previous) => previous,
  })

  const summary = query.data?.pages[0]?.summary
  const ratings = query.data?.pages.flatMap((p) => p.data) ?? []

  return (
    <div className="mx-auto max-w-lg px-4 py-6">
      <Link to="/driver" className="inline-flex items-center gap-1 text-sm text-gray hover:text-dark">
        <ArrowLeft size={16} /> Painel do condutor
      </Link>
      <h1 className="mt-2 text-xl font-bold text-dark">As minhas avaliações</h1>

      {query.isLoading && (
        <div className="flex justify-center py-16 text-gray">
          <Loader2 className="animate-spin" size={24} aria-label="A carregar" />
        </div>
      )}

      {query.isError && (
        <p role="alert" className="mt-6 rounded-lg bg-danger/10 p-3 text-sm text-danger">
          Não foi possível carregar as avaliações. Verifique a ligação e tente de novo.
        </p>
      )}

      {summary && summary.count === 0 && (
        <div className="mt-10 flex flex-col items-center text-center">
          <Star size={40} className="text-gray/40" />
          <p className="mt-3 font-medium text-dark">Ainda não tem avaliações</p>
          <p className="mt-1 text-sm text-gray">Depois de cada entrega, o cliente pode avaliar o seu serviço. As avaliações aparecem aqui.</p>
        </div>
      )}

      {summary && summary.count > 0 && (
        <>
          <Summary summary={summary} stars={stars} onStars={setStars} />

          <div className="mt-5 flex items-center justify-between">
            <h2 className="text-sm font-semibold text-dark">
              {stars ? `Avaliações de ${stars} ${stars === 1 ? 'estrela' : 'estrelas'}` : 'Todas as avaliações'}
            </h2>
            {stars && (
              <button type="button" onClick={() => setStars(undefined)} className="text-xs font-medium text-primary">
                Ver todas
              </button>
            )}
          </div>

          <ul className="mt-2 space-y-2">
            {ratings.map((r) => (
              <li key={r.id} className="rounded-xl border border-gray/15 bg-white p-3">
                <div className="flex items-center justify-between gap-2">
                  <Stars value={r.rating} />
                  <time dateTime={r.date} className="text-xs text-gray">{formatDay(r.date)}</time>
                </div>
                {r.comment ? (
                  <p className="mt-1.5 whitespace-pre-line break-words text-sm text-dark">“{r.comment}”</p>
                ) : (
                  <p className="mt-1.5 text-xs italic text-gray">Sem comentário</p>
                )}
              </li>
            ))}
          </ul>

          {query.hasNextPage && (
            <button
              onClick={() => void query.fetchNextPage()}
              disabled={query.isFetchingNextPage}
              className="mt-4 w-full rounded-lg border border-gray/20 py-2.5 text-sm font-medium text-dark disabled:opacity-60"
            >
              {query.isFetchingNextPage ? 'A carregar...' : 'Ver mais avaliações'}
            </button>
          )}

          <p className="mt-6 flex gap-2 rounded-lg bg-light p-3 text-xs text-gray">
            <ShieldCheck size={16} className="shrink-0 text-primary" aria-hidden="true" />
            Para proteger quem avalia, não mostramos o nome do cliente nem o pedido,só o dia da avaliação.
          </p>
        </>
      )}
    </div>
  )
}
