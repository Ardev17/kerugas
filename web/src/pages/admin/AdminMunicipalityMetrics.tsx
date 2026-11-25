import { useMemo, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Fuel, Search, MapPin } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { territoryService } from '@/services/territoryService'
import { useAuth } from '@/hooks/useAuth'
import { hasSuperPowers, isProvinceSuperAdmin } from '@/lib/roles'

type PeriodKey = 'today' | '7d' | '30d' | 'custom'

const PERIODS: { key: PeriodKey; label: string }[] = [
  { key: 'today', label: 'Hoje' },
  { key: '7d', label: '7 dias' },
  { key: '30d', label: '30 dias' },
  { key: 'custom', label: 'Escolher datas' },
]

/**
 * Métricas por município (Novembro 2026): só o Super Admin geral e o
 * Provincial. O servidor também recusa (403) qualquer outro papel,esta
 * guarda só evita mostrar a página a quem chegar por link. Os postos que
 * mais tempo têm gás e os municípios com mais consultas (web + app).
 */
export default function AdminMunicipalityMetrics() {
  const { user } = useAuth()
  const canSee = hasSuperPowers(user)
  const isGlobal = canSee && !isProvinceSuperAdmin(user)

  const [period, setPeriod] = useState<PeriodKey>('7d')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [provinceId, setProvinceId] = useState<number | ''>('')
  const [municipalityId, setMunicipalityId] = useState<number | ''>('')

  const provincesQuery = useQuery({ queryKey: ['territory-provinces'], queryFn: territoryService.listProvinces, enabled: isGlobal })
  const municipalitiesQuery = useQuery({
    queryKey: ['territory-municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(Number(provinceId)),
    enabled: isGlobal && provinceId !== '',
  })

  const customReady = period !== 'custom' || (from !== '' && to !== '')
  const params = useMemo(() => ({
    period,
    ...(period === 'custom' ? { from, to } : {}),
    ...(provinceId !== '' ? { province_id: Number(provinceId) } : {}),
    ...(municipalityId !== '' ? { municipality_id: Number(municipalityId) } : {}),
  }), [period, from, to, provinceId, municipalityId])

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['municipality-metrics', params],
    queryFn: () => adminService.municipalityMetrics(params),
    enabled: canSee && customReady,
    staleTime: 30_000,
  })

  if (user && !canSee) return <Navigate to="/admin" replace />

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-dark">Métricas por município</h1>
        <p className="text-sm text-gray">
          {isProvinceSuperAdmin(user)
            ? <>Província de <strong className="text-dark">{user?.province ?? '—'}</strong>.</>
            : 'Toda a plataforma,pode filtrar por província e município.'}
          {' '}Postos que mais tempo têm gás e municípios com mais consultas (site e app).
        </p>
      </div>

      {/* Filtros */}
      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray/15 bg-white p-4">
        <div>
          <span className="mb-1 block text-xs font-semibold text-dark">Período</span>
          <div role="group" aria-label="Período" className="flex rounded-lg border border-gray/20 p-0.5">
            {PERIODS.map((p) => (
              <button
                key={p.key}
                type="button"
                onClick={() => setPeriod(p.key)}
                aria-pressed={period === p.key}
                className={`rounded-md px-3 py-1.5 text-sm font-medium ${period === p.key ? 'bg-primary text-white' : 'text-gray hover:text-dark'}`}
              >
                {p.label}
              </button>
            ))}
          </div>
        </div>

        {period === 'custom' && (
          <div className="flex items-end gap-2">
            <label className="text-xs font-semibold text-dark">
              De
              <input type="date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm" />
            </label>
            <label className="text-xs font-semibold text-dark">
              Até
              <input type="date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm" />
            </label>
          </div>
        )}

        {isGlobal && (
          <>
            <label className="text-xs font-semibold text-dark">
              Província
              <select
                value={provinceId}
                onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : ''); setMunicipalityId('') }}
                className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm"
              >
                <option value="">Todas</option>
                {(provincesQuery.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </label>
            <label className="text-xs font-semibold text-dark">
              Município
              <select
                value={municipalityId}
                onChange={(e) => setMunicipalityId(e.target.value ? Number(e.target.value) : '')}
                disabled={provinceId === ''}
                className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm disabled:bg-light"
              >
                <option value="">Todos</option>
                {(municipalitiesQuery.data ?? []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </label>
          </>
        )}
      </div>

      {period === 'custom' && !customReady && <p className="text-sm text-gray">Escolha as duas datas para ver as métricas.</p>}
      {isLoading && customReady && <p className="text-gray">A carregar métricas...</p>}
      {isError && (
        <div role="alert" className="rounded-xl border border-danger/30 bg-danger/5 p-4 text-sm text-dark">
          Não foi possível carregar as métricas.{' '}
          <button type="button" onClick={() => void refetch()} className="font-semibold text-primary underline">Tentar novamente</button>
        </div>
      )}

      {data && (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <Stat label="Municípios" value={String(data.totals.municipalities)} />
            <Stat label="Postos" value={String(data.totals.stations)} />
            <Stat label="Consultas no período" value={data.totals.requests.toLocaleString('pt-PT')} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            {/* Postos que mais disponibilizam gás */}
            <section className="rounded-xl border border-gray/15 bg-white p-4">
              <h2 className="flex items-center gap-2 text-sm font-bold text-dark"><Fuel size={16} className="text-primary" /> Postos que mais disponibilizam gás</h2>
              <p className="mt-0.5 text-xs text-gray">Tempo total com gás disponível no período.</p>
              {data.top_gas_availability.length === 0 ? (
                <p className="mt-3 text-sm text-gray">Sem registos de estado neste período.</p>
              ) : (
                <ol className="mt-3 space-y-2">
                  {data.top_gas_availability.slice(0, 15).map((s, i) => (
                    <li key={s.station_id} className="flex items-center gap-3 text-sm">
                      <span className="w-5 text-right font-semibold text-gray">{i + 1}</span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-medium text-dark">{s.station}{s.code ? <span className="font-normal text-gray"> · nº {s.code}</span> : null}</span>
                        <span className="block truncate text-xs text-gray">{s.municipality}</span>
                      </span>
                      <span className="shrink-0 text-right">
                        <span className="block font-semibold text-dark">{s.label}</span>
                        <span className="block text-xs text-gray">{s.percent}%</span>
                      </span>
                    </li>
                  ))}
                </ol>
              )}
            </section>

            {/* Municípios com mais requisições */}
            <section className="rounded-xl border border-gray/15 bg-white p-4">
              <h2 className="flex items-center gap-2 text-sm font-bold text-dark"><Search size={16} className="text-primary" /> Municípios com mais requisições</h2>
              <p className="mt-0.5 text-xs text-gray">Consultas de gás pelo site e pela app.</p>
              {data.top_requests.every((m) => m.requests === 0) ? (
                <p className="mt-3 text-sm text-gray">Sem consultas neste período.</p>
              ) : (
                <ol className="mt-3 space-y-2">
                  {data.top_requests.filter((m) => m.requests > 0).slice(0, 15).map((m, i) => (
                    <li key={m.municipality_id} className="flex items-center gap-3 text-sm">
                      <span className="w-5 text-right font-semibold text-gray">{i + 1}</span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-medium text-dark">{m.municipality}</span>
                        <span className="block truncate text-xs text-gray">{m.province}</span>
                      </span>
                      <span className="shrink-0 font-semibold text-dark">{m.requests.toLocaleString('pt-PT')}</span>
                    </li>
                  ))}
                </ol>
              )}
            </section>
          </div>

          {/* Tabela por município */}
          <section className="overflow-hidden rounded-xl border border-gray/15 bg-white">
            <h2 className="flex items-center gap-2 border-b border-gray/10 p-4 text-sm font-bold text-dark"><MapPin size={16} className="text-primary" /> Resumo por município</h2>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray/10 text-left text-xs text-gray">
                    <th className="p-3 font-semibold">Município</th>
                    <th className="p-3 font-semibold">Postos</th>
                    <th className="p-3 font-semibold">Tempo com gás</th>
                    <th className="p-3 font-semibold">Média por posto</th>
                    <th className="p-3 font-semibold">Consultas</th>
                  </tr>
                </thead>
                <tbody>
                  {data.municipalities.map((m) => (
                    <tr key={m.municipality_id} className="border-b border-gray/5 last:border-0">
                      <td className="p-3">
                        <span className="font-medium text-dark">{m.municipality}</span>
                        {data.scope.type === 'national' && <span className="block text-xs text-gray">{m.province}</span>}
                      </td>
                      <td className="p-3 text-gray">{m.stations_count}</td>
                      <td className="p-3 font-medium text-dark">{m.label_with_gas}</td>
                      <td className="p-3 text-gray">{m.avg_label_per_station}</td>
                      <td className="p-3 font-medium text-dark">{m.requests.toLocaleString('pt-PT')}</td>
                    </tr>
                  ))}
                  {data.municipalities.length === 0 && (
                    <tr><td colSpan={5} className="p-4 text-center text-gray">Sem municípios no âmbito escolhido.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <p className="text-xs text-gray">{label}</p>
      <p className="mt-1 text-2xl font-extrabold text-dark">{value}</p>
    </div>
  )
}
