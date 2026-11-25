import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import SubscriptionNotice from '@/components/stations/SubscriptionNotice'
import { Heart } from 'lucide-react'
import { Link } from 'react-router-dom'
import { favoriteService } from '@/services/favoriteService'
import { StationCard } from '@/components/StationCard'
import { PageHero } from '@/components/fx/PageHero'

/**
 * Etapa 10,Favoritos. Lista os postos que o utilizador guardou,
 * reutilizando o StationCard já usado em /find (consistência visual).
 */
export default function Favorites() {
  const queryClient = useQueryClient()

  const favoritesQuery = useQuery({
    queryKey: ['favorites'],
    queryFn: favoriteService.list,
  })

  const removeMutation = useMutation({
    mutationFn: (stationId: number) => favoriteService.remove(stationId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['favorites'] }),
  })

  const lista = favoritesQuery.data?.favorites ?? []
  const escondidos = favoritesQuery.data?.hiddenCount ?? 0
  const zonas = favoritesQuery.data?.blocked ?? []
  const bloqueioDeConta = favoritesQuery.data?.accessBlock ?? null

  return (
    <div className="mx-auto max-w-4xl px-4 py-10">
      <PageHero title="Favoritos" subtitle="Os postos que guardou para acesso rápido." flame={false} />

      {favoritesQuery.isLoading && (
        <div className="mt-8 grid gap-4 sm:grid-cols-2">
          {[1, 2].map((i) => <div key={i} className="fx-shimmer h-40 rounded-2xl" />)}
        </div>
      )}

      {favoritesQuery.isSuccess && escondidos > 0 && (
        <div className="mt-8">
          <SubscriptionNotice
            zones={zonas}
            message={bloqueioDeConta?.message}
            needsLogin={bloqueioDeConta?.reason === 'login_required'}
            accountBlocked={bloqueioDeConta?.reason === 'user_blocked'}
          />
          <p className="mt-3 text-center text-xs text-gray">
            {escondidos === 1
              ? 'Um dos seus favoritos não está a ser mostrado.'
              : `${escondidos} dos seus favoritos não estão a ser mostrados.`}{' '}
            Continuam guardados,voltam assim que a consulta for reposta.
          </p>
        </div>
      )}

      {favoritesQuery.isSuccess && lista.length === 0 && !escondidos && (
        <div className="fx-slide-next mt-8 rounded-2xl border border-dashed border-gray/30 p-8 text-center">
          <span className="fx-bubble mx-auto h-14 w-14" style={{ ['--bubble-a' as string]: '#E11D48', ['--bubble-b' as string]: '#FB7185' }} aria-hidden="true">
            <Heart size={26} className="fx-heartbeat fill-white" />
          </span>
          <p className="mt-3 font-medium text-dark">Ainda não tem postos favoritos.</p>
          <p className="mt-1 text-sm text-gray">Guarde um posto a partir da página de detalhes para o ver aqui.</p>
          <Link to="/encontrar-gas" className="fx-btn-fire mt-5 inline-block rounded-xl px-5 py-2.5 text-sm font-semibold text-white">
            Encontrar postos
          </Link>
        </div>
      )}

      {favoritesQuery.isSuccess && lista.length > 0 && (
        <div className="fx-cascade mt-8 grid gap-4 sm:grid-cols-2">
          {lista.map((fav, i) => (
            <div key={fav.id} style={{ ['--i' as string]: Math.min(i, 10) }} className="relative">
              <StationCard station={fav.station} />
              <button
                onClick={() => removeMutation.mutate(fav.station.id)}
                className="absolute -right-2 -top-2 z-10 rounded-full bg-white p-2 text-danger shadow-lg ring-2 ring-danger/20 transition-transform hover:scale-110"
                aria-label={`Remover ${fav.station.name} dos favoritos`}
              >
                <Heart size={16} className="fx-heartbeat fill-danger" />
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
