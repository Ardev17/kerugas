import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MapContainer, TileLayer, CircleMarker, Tooltip, useMap } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { adminService } from '@/services/adminService'
import { territoryService } from '@/services/territoryService'
import { useAuth } from '@/hooks/useAuth'
import { isGlobalSuperAdmin } from '@/lib/roles'
import type { DemandOverview } from '@/types'
import { RAMP, rampIndex } from '@/lib/heat'

const ANGOLA_CENTER: [number, number] = [-11.2027, 17.8739]
type Days = 7 | 30 | 90
const PERIODS: Days[] = [7, 30, 90]

/**
 * Mapa de calor da procura (Outubro 2026): de onde vêm os pedidos e a que
 * horas,para decidir onde ter condutores e de que postos carregar. Inclui
 * pedidos cancelados (também são procura). Os pontos estão agrupados em
 * células de ~110 m: nunca mostram a morada exata de um cliente.
 */
export default function AdminDemand() {
  const { user } = useAuth()
  const isGlobal = isGlobalSuperAdmin(user)
  const [days, setDays] = useState<Days>(30)
  const [provinceId, setProvinceId] = useState<number | ''>('')
  const [municipalityId, setMunicipalityId] = useState<number | ''>('')
  const [showStations, setShowStations] = useState(true)

  const effectiveProvince = isGlobal ? (provinceId || undefined) : (user?.province_id ?? undefined)

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces, enabled: isGlobal })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', effectiveProvince],
    queryFn: () => territoryService.listMunicipalities(effectiveProvince!),
    enabled: !!effectiveProvince,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin-demand', days, provinceId, municipalityId],
    queryFn: () => adminService.demand({
      days,
      province_id: isGlobal && provinceId ? provinceId : undefined,
      municipality_id: municipalityId || undefined,
    }),
  })

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-dark">Procura</h1>
        <p className="text-sm text-gray">
          De onde vêm os pedidos e a que horas,para decidir onde ter condutores e de que postos carregar.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Filtros">
        <div role="group" aria-label="Período" className="flex rounded-lg border border-gray/20 bg-white p-0.5">
          {PERIODS.map((p) => (
            <button
              key={p}
              type="button"
              aria-pressed={days === p}
              onClick={() => setDays(p)}
              className={`rounded-md px-3 py-1.5 text-sm font-medium ${days === p ? 'bg-primary text-white' : 'text-gray hover:text-dark'}`}
            >
              {p} dias
            </button>
          ))}
        </div>
        {isGlobal && (
          <select
            aria-label="Província"
            value={provinceId}
            onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : ''); setMunicipalityId('') }}
            className="rounded-lg border border-gray/20 bg-white px-3 py-2 text-sm"
          >
            <option value="">Todas as províncias</option>
            {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        )}
        <select
          aria-label="Município"
          value={municipalityId}
          disabled={!effectiveProvince}
          onChange={(e) => setMunicipalityId(e.target.value ? Number(e.target.value) : '')}
          className="rounded-lg border border-gray/20 bg-white px-3 py-2 text-sm disabled:opacity-50"
        >
          <option value="">Todos os municípios</option>
          {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
        <label className="flex items-center gap-1.5 text-sm text-gray">
          <input type="checkbox" checked={showStations} onChange={(e) => setShowStations(e.target.checked)} /> Mostrar postos
        </label>
      </div>

      {isLoading && <p className="text-gray">A carregar a procura...</p>}
      {isError && <p role="alert" className="text-sm text-danger">Não foi possível carregar a procura.</p>}
      {data && <DemandBody data={data} showStations={showStations} />}
    </div>
  )
}

function DemandBody({ data, showStations }: { data: DemandOverview; showStations: boolean }) {
  const t = data.totals
  return (
    <>
      <section aria-label="Resumo" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Tile label="Pedidos" value={String(t.orders)} hint={`${t.delivered} entregues · ${t.cancelled} cancelados`} />
        <Tile label="Garrafas pedidas" value={String(t.bottles)} />
        <Tile label="Hora de pico" value={data.peak.hour !== null ? `${String(data.peak.hour).padStart(2, '0')}h` : '—'} hint={data.peak.weekday ? `Dia com mais pedidos: ${data.peak.weekday}` : undefined} />
        <Tile label="Com localização no mapa" value={t.location_rate !== null ? `${t.location_rate}%` : '—'} hint={`${t.with_location} de ${t.orders} pedidos`} />
      </section>

      <div className="rounded-xl border border-gray/15 bg-white p-4">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <p className="font-semibold text-dark">Mapa de calor</p>
          <Legend max={Math.max(0, ...data.points.map((p) => p.count))} unit="pedidos por zona" />
        </div>
        {data.points.length === 0 ? (
          <p className="py-10 text-center text-sm text-gray">Ainda não há pedidos com localização neste período. Os bairros abaixo cobrem também os pedidos sem GPS.</p>
        ) : (
          <div className="h-[60vh] overflow-hidden rounded-lg border border-gray/10">
            <HeatMap data={data} showStations={showStations} />
          </div>
        )}
      </div>

      <div className="rounded-xl border border-gray/15 bg-white p-4">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <p className="font-semibold text-dark">Dia da semana × hora</p>
          <Legend max={Math.max(0, ...data.weekday_hour.grid.flat())} unit="pedidos" />
        </div>
        <WeekHourGrid weekdays={data.weekday_hour.weekdays} grid={data.weekday_hour.grid} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="rounded-xl border border-gray/15 bg-white p-4">
          <p className="mb-3 font-semibold text-dark">Bairros com mais pedidos</p>
          {data.neighborhoods.length === 0 ? (
            <p className="text-sm text-gray">Sem bairros indicados neste período.</p>
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-gray/10 text-xs uppercase tracking-wide text-gray">
                  <th className="pb-2 pr-3">Bairro</th><th className="pb-2 pr-3">Município</th><th className="pb-2 pr-3 text-right">Pedidos</th><th className="pb-2 text-right">Garrafas</th>
                </tr>
              </thead>
              <tbody>
                {data.neighborhoods.map((n) => (
                  <tr key={`${n.neighborhood}-${n.municipality}`} className="border-b border-gray/5 last:border-0">
                    <td className="py-1.5 pr-3 text-dark">{n.neighborhood}</td>
                    <td className="py-1.5 pr-3 text-gray">{n.municipality ?? '—'}</td>
                    <td className="py-1.5 pr-3 text-right tabular-nums text-dark">{n.orders}</td>
                    <td className="py-1.5 text-right tabular-nums text-gray">{n.bottles}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
        <div className="rounded-xl border border-gray/15 bg-white p-4">
          <p className="mb-3 font-semibold text-dark">Municípios</p>
          {data.municipalities.length === 0 ? (
            <p className="text-sm text-gray">Sem pedidos neste período.</p>
          ) : (
            <ul className="space-y-2">
              {data.municipalities.map((m) => {
                const max = data.municipalities[0].orders || 1
                return (
                  <li key={m.municipality} className="text-sm" title={`${m.municipality}: ${m.orders} pedidos, ${m.delivered} entregues`}>
                    <div className="flex justify-between text-dark"><span>{m.municipality}</span><span className="tabular-nums">{m.orders}</span></div>
                    <div className="mt-1 h-2 rounded-full bg-light">
                      <div className="h-2 rounded-full" style={{ width: `${(m.orders / max) * 100}%`, background: RAMP[2] }} />
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      </div>
    </>
  )
}

function HeatMap({ data, showStations }: { data: DemandOverview; showStations: boolean }) {
  const max = Math.max(1, ...data.points.map((p) => p.count))
  return (
    <MapContainer center={ANGOLA_CENTER} zoom={6} className="h-full w-full">
      <TileLayer attribution="&copy; OpenStreetMap contributors" url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
      <FitToPoints points={data.points} />
      {data.points.map((p) => {
        const idx = Math.max(0, rampIndex(p.count, max))
        return (
          <CircleMarker
            key={`${p.lat}|${p.lng}`}
            center={[p.lat, p.lng]}
            radius={6 + Math.sqrt(p.count / max) * 16}
            pathOptions={{ color: '#ffffff', weight: 2, fillColor: RAMP[idx], fillOpacity: 0.55 + idx * 0.1 }}
          >
            <Tooltip>{p.count} pedido(s) · {p.delivered} entregue(s)</Tooltip>
          </CircleMarker>
        )
      })}
      {showStations && data.stations.map((s) => (
        <CircleMarker key={`st-${s.id}`} center={[s.lat, s.lng]} radius={5} pathOptions={{ color: '#ffffff', weight: 2, fillColor: '#334155', fillOpacity: 1 }}>
          <Tooltip>Posto: {s.name}</Tooltip>
        </CircleMarker>
      ))}
    </MapContainer>
  )
}

/** Enquadra o mapa nos pontos de procura sempre que mudam. */
function FitToPoints({ points }: { points: DemandOverview['points'] }) {
  const map = useMap()
  const key = useMemo(() => points.map((p) => `${p.lat},${p.lng}`).join(';'), [points])
  useEffect(() => {
    if (points.length === 0) return
    const lats = points.map((p) => p.lat)
    const lngs = points.map((p) => p.lng)
    map.fitBounds([[Math.min(...lats), Math.min(...lngs)], [Math.max(...lats), Math.max(...lngs)]], { padding: [30, 30], maxZoom: 15 })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, map])
  return null
}

function WeekHourGrid({ weekdays, grid }: { weekdays: string[]; grid: number[][] }) {
  const max = Math.max(0, ...grid.flat())
  return (
    <div className="overflow-x-auto">
      <table className="border-separate" style={{ borderSpacing: 2 }} aria-label="Pedidos por dia da semana e hora">
        <thead>
          <tr>
            <th />
            {Array.from({ length: 24 }, (_, h) => (
              <th key={h} className="w-7 text-center text-[10px] font-normal text-gray">{h % 3 === 0 ? `${h}h` : ''}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {grid.map((row, d) => (
            <tr key={weekdays[d]}>
              <th className="pr-2 text-right text-xs font-normal text-gray">{weekdays[d]}</th>
              {row.map((v, h) => {
                const idx = rampIndex(v, max)
                return (
                  <td
                    key={h}
                    title={`${weekdays[d]}, ${String(h).padStart(2, '0')}h: ${v} pedido(s)`}
                    aria-label={`${weekdays[d]} ${h}h: ${v}`}
                    className="h-6 w-7 rounded"
                    style={{ background: idx < 0 ? 'rgba(148,163,184,0.12)' : RAMP[idx] }}
                  />
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function Legend({ max, unit }: { max: number; unit: string }) {
  if (max <= 0) return null
  const step = max / RAMP.length
  return (
    <div className="flex items-center gap-1 text-xs text-gray" aria-label={`Escala: 1 a ${max} ${unit}`}>
      <span>Menos</span>
      {RAMP.map((c, i) => (
        <span key={c} className="inline-block h-3 w-6 rounded-sm" style={{ background: c }} title={`até ${Math.ceil(step * (i + 1))} ${unit}`} />
      ))}
      <span>Mais ({max})</span>
    </div>
  )
}

function Tile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <p className="text-xs text-gray">{label}</p>
      <p className="mt-1 text-2xl font-bold tabular-nums text-dark">{value}</p>
      {hint && <p className="mt-0.5 text-xs text-gray">{hint}</p>}
    </div>
  )
}
