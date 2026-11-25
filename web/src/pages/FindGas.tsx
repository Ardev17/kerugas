import { useState, useEffect } from 'react'
import SubscriptionNotice from '@/components/stations/SubscriptionNotice'
import { useQuery } from '@tanstack/react-query'
import { MapPin, PackagePlus, SlidersHorizontal, Bug, Copy, Check } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useGeolocation } from '@/hooks/useGeolocation'
import { stationService } from '@/services/stationService'
import { territoryService } from '@/services/territoryService'
import { analyticsService } from '@/services/analyticsService'
import { StationCard } from '@/components/StationCard'
import { ApiErrorNotice } from '@/components/ApiErrorNotice'
import { PageHero } from '@/components/fx/PageHero'

/**
 * Página /find (secção 12). Fluxo: geolocalização -> fallback província/
 * município -> filtros -> resultados. Sempre oferece "Pedir gás" como saída,
 * encontrando ou não disponibilidade (secção 231).
 */
export default function FindGas() {
  const geo = useGeolocation()
  const [provinceId, setProvinceId] = useState<number | null>(null)
  const [municipalityId, setMunicipalityId] = useState<number | null>(null)
  const [gasOnly, setGasOnly] = useState(false)

  const useGps = geo.status === 'granted' && !municipalityId

  const provincesQuery = useQuery({
    queryKey: ['provinces'],
    queryFn: territoryService.listProvinces,
  })

  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(provinceId!),
    enabled: !!provinceId,
  })

  const stationsQuery = useQuery({
    // Outubro 2026: a escolha manual de município tem prioridade sobre a
    // localização (a API dá prioridade às coordenadas quando as recebe, por
    // isso quando há município não as enviamos). Sem município, usa a
    // localização, se o cliente a deu.
    queryKey: ['stations-nearby', useGps ? geo.latitude : null, useGps ? geo.longitude : null, municipalityId, gasOnly],
    queryFn: () =>
      stationService.nearby({
        latitude: useGps ? geo.latitude ?? undefined : undefined,
        longitude: useGps ? geo.longitude ?? undefined : undefined,
        municipality_id: useGps ? undefined : municipalityId ?? undefined,
        gas_available: gasOnly || undefined,
      }),
    enabled: useGps || !!municipalityId,
  })

  const hasResults = (stationsQuery.data?.stations.length ?? 0) > 0
  // Zonas que a API deixou de fora por estarem fechadas (Novembro 2026).
  const zonasFechadas = stationsQuery.data?.blocked ?? []
  // A lista veio curta por causa de quem pede, não de onde: conta bloqueada,
  // ou falta de sessão numa zona fechada.
  const bloqueioDeConta = stationsQuery.data?.accessBlock ?? null

  useEffect(() => {
    if (geo.status === 'granted') {
      analyticsService.track('location_enabled')
    }
  }, [geo.status])

  useEffect(() => {
    if (stationsQuery.isSuccess) {
      const municipality = municipalitiesQuery.data?.find((m) => m.id === municipalityId)?.name
      analyticsService.track('station_search', {
        municipality,
        via_gps: useGps,
        gas_only: gasOnly,
        results: stationsQuery.data?.stations.length ?? 0,
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stationsQuery.data])

  return (
    <div className="mx-auto max-w-4xl px-4 py-10">
      <PageHero title="Encontrar" highlight="gás" subtitle="Veja agora que postos têm gás, como está a fila e qual fica mais perto." />

      <div className="fx-glass fx-slide-next mt-6 rounded-2xl p-4 sm:p-5">
        {geo.status !== 'granted' && (
          <button
            onClick={() => {
              // Pedir a localização devolve o controlo à localização: limpa a escolha manual.
              setProvinceId(null)
              setMunicipalityId(null)
              geo.request()
            }}
            className="fx-btn-fire flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3.5 font-semibold text-white"
          >
            <span className={geo.status === 'requesting' ? 'fx-radar' : 'inline-flex'}>
              <MapPin size={18} aria-hidden="true" />
            </span>
            {geo.status === 'requesting' ? 'A obter localização...' : 'Usar minha localização'}
          </button>
        )}

        {(geo.status === 'denied' || geo.status === 'unsupported') && geo.error && (
          <p role="alert" className="mt-3 rounded-lg bg-danger/5 px-3 py-2 text-sm text-danger">
            {geo.error}
          </p>
        )}

        {geo.status !== 'granted' && (
          <p className="mt-4 text-center text-xs text-gray">ou escolha a zona manualmente</p>
        )}

        {/* Outubro 2026: sempre visíveis,o cliente escolhe a zona logo ao
            entrar, sem ter de recusar primeiro a localização. */}
        <div className={`grid gap-3 sm:grid-cols-2 ${geo.status === 'granted' ? 'mt-4' : 'mt-2'}`}>
          <select
            aria-label="Província"
            className="fx-input rounded-xl border border-gray/20 px-3 py-2.5 text-sm"
            value={provinceId ?? ''}
            onChange={(e) => {
              setProvinceId(Number(e.target.value) || null)
              setMunicipalityId(null)
            }}
          >
            <option value="">Selecione a província</option>
            {provincesQuery.data?.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>

          <select
            aria-label="Município"
            className="fx-input rounded-xl border border-gray/20 px-3 py-2.5 text-sm"
            value={municipalityId ?? ''}
            onChange={(e) => setMunicipalityId(Number(e.target.value) || null)}
            disabled={!provinceId}
          >
            <option value="">Selecione o município</option>
            {municipalitiesQuery.data?.map((m) => (
              <option key={m.id} value={m.id}>{m.name}</option>
            ))}
          </select>
        </div>

        {provincesQuery.isError && (
          <ApiErrorNotice className="mt-3" error={provincesQuery.error} onRetry={() => void provincesQuery.refetch()} />
        )}
        {!provincesQuery.isError && municipalitiesQuery.isError && (
          <ApiErrorNotice className="mt-3" error={municipalitiesQuery.error} onRetry={() => void municipalitiesQuery.refetch()} />
        )}

        <label className="mt-4 flex items-center gap-2 text-sm text-gray">
          <SlidersHorizontal size={14} aria-hidden="true" />
          <input type="checkbox" checked={gasOnly} onChange={(e) => setGasOnly(e.target.checked)} />
          Mostrar apenas postos com gás disponível
        </label>

        <GeoDiagnostics geo={geo} />
      </div>

      <div className="mt-8">
        {stationsQuery.isLoading && <SkeletonList />}

        {stationsQuery.isError && (
          <ApiErrorNotice error={stationsQuery.error} onRetry={() => void stationsQuery.refetch()} />
        )}

        {/* Fechado: explica-se porquê, em vez do vazio genérico. */}
        {stationsQuery.isSuccess && (bloqueioDeConta || zonasFechadas.length > 0) && (
          <SubscriptionNotice
            zones={zonasFechadas}
            message={bloqueioDeConta?.message}
            needsLogin={bloqueioDeConta?.reason === 'login_required'}
            accountBlocked={bloqueioDeConta?.reason === 'user_blocked'}
          />
        )}

        {stationsQuery.isSuccess && !hasResults && !bloqueioDeConta && zonasFechadas.length === 0 && (
          <EmptyState gasOnly={gasOnly} onClear={() => setGasOnly(false)} />
        )}

        {stationsQuery.isSuccess && hasResults && (
          <div className="fx-cascade grid gap-4 sm:grid-cols-2">
            {stationsQuery.data!.stations.map((station, i) => (
              <div key={station.id} style={{ ['--i' as string]: Math.min(i, 10) }}>
                <StationCard station={station} />
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * Painel "Detalhes técnicos" da localização (Novembro 2026). Fica recolhido
 * e só aparece depois de uma tentativa,serve para perceber, sobretudo no
 * iPhone, porque é que a localização não devolve coordenadas. Mostra o
 * caminho (plugin nativo ou navegador), se o plugin nativo está mesmo
 * registado no build, o erro em bruto do sistema, a precisão e o tempo.
 */
function GeoDiagnostics({ geo }: { geo: ReturnType<typeof useGeolocation> }) {
  const [open, setOpen] = useState(false)
  const [copied, setCopied] = useState(false)
  const d = geo.diagnostics

  if (geo.status === 'idle' || !d) return null

  const report = [
    `estado: ${geo.status}`,
    `plataforma: ${d.platform}`,
    d.nativePluginAvailable === null ? null : `plugin nativo disponível: ${d.nativePluginAvailable ? 'sim' : 'NÃO'}`,
    geo.latitude !== null ? `coordenadas: ${geo.latitude}, ${geo.longitude}` : 'coordenadas: (nenhuma)',
    d.accuracyM !== null ? `precisão: ${d.accuracyM} m` : null,
    d.tookMs !== null ? `tempo: ${d.tookMs} ms` : null,
    geo.reason ? `motivo: ${geo.reason}` : null,
    d.rawError ? `erro do sistema: ${d.rawError}` : null,
    `passos: ${d.steps.join(' → ')}`,
  ].filter(Boolean).join('\n')

  const copy = () => {
    void navigator.clipboard?.writeText(report).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }

  return (
    <div className="mt-4 border-t border-gray/10 pt-3">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex items-center gap-1.5 text-xs text-gray hover:text-dark"
      >
        <Bug size={13} aria-hidden="true" />
        {open ? 'Ocultar' : 'Ver'} detalhes técnicos da localização
      </button>

      {open && (
        <div className="mt-2 rounded-lg bg-light/60 p-3">
          {d.nativePluginAvailable === false && (
            <p className="mb-2 rounded bg-danger/10 px-2 py-1.5 text-xs text-danger">
              O plugin nativo de localização não está ativo neste build do app,é esta a causa provável no iPhone.
              É preciso correr <code>npx cap sync ios</code> e reconstruir.
            </p>
          )}
          <pre className="overflow-x-auto whitespace-pre-wrap break-words text-[11px] leading-relaxed text-dark">{report}</pre>
          <button
            type="button"
            onClick={copy}
            className="mt-2 flex items-center gap-1.5 text-xs font-medium text-primary"
          >
            {copied ? <Check size={13} aria-hidden="true" /> : <Copy size={13} aria-hidden="true" />}
            {copied ? 'Copiado' : 'Copiar para enviar ao suporte'}
          </button>
        </div>
      )}
    </div>
  )
}

function SkeletonList() {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {[1, 2, 3, 4].map((i) => (
        <div key={i} className="fx-shimmer h-40 rounded-2xl" />
      ))}
    </div>
  )
}

function EmptyState({ gasOnly, onClear }: { gasOnly: boolean; onClear: () => void }) {
  return (
    <div className="fx-slide-next rounded-2xl border border-dashed border-gray/30 p-8 text-center">
      <span className="fx-radar mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary" aria-hidden="true">
        <MapPin size={24} />
      </span>
      <p className="font-medium text-dark">
        {gasOnly ? 'Não encontramos postos com gás nesta área.' : 'Não encontramos postos nesta área.'}
      </p>
      <div className="mt-4 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
        {gasOnly && (
          <button onClick={onClear} className="rounded-lg border border-gray/20 px-4 py-2 text-sm font-medium text-dark hover:bg-light">
            Limpar filtros
          </button>
        )}
        <Link
          to="/pedir-gas"
          className="fx-btn-fire flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold text-white"
        >
          <PackagePlus size={16} aria-hidden="true" />
          Pedir gás
        </Link>
      </div>
    </div>
  )
}
