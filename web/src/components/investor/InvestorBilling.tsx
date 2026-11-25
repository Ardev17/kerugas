import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { Ban, Coins, FileSpreadsheet, FileText, Info, Landmark, Loader2, MapPin, PiggyBank, ShieldCheck } from 'lucide-react'
import { getApiErrorMessage } from '@/lib/api'
import { formatKz } from '@/lib/metrics'
import { PRESETS, presetRange, type Preset } from '@/lib/investor'
import type { InvestorExportFormat, InvestorFilters, InvestorOverview } from '@/services/investorService'

const pct = (v: number) => `${v.toLocaleString('pt-PT', { maximumFractionDigits: 2 })}%`
const dateLabel = (iso: string) => new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' })

interface Props {
  /** Chave da cache (o investidor ou o investidor visto pelo Super Admin). */
  queryKey: unknown[]
  load: (filters: InvestorFilters) => Promise<InvestorOverview>
  /** Exportar o que está no ecrã (mesmo município e período). */
  onExport?: (format: InvestorExportFormat, filters: InvestorFilters) => Promise<void>
}

/** Erro de um pedido com `responseType: 'blob'`: a mensagem vem dentro do blob. */
async function exportError(err: unknown): Promise<string> {
  if (axios.isAxiosError(err) && err.response?.data instanceof Blob) {
    try {
      return (JSON.parse(await err.response.data.text()) as { message?: string }).message ?? 'Não foi possível gerar o ficheiro.'
    } catch {
      // segue para a mensagem genérica
    }
  }
  return getApiErrorMessage(err, 'Não foi possível gerar o ficheiro.')
}

/**
 * Faturação do investidor (Outubro 2026): total arrecadado, comissão do
 * KeruGás e a parte do investidor,SÓ dos municípios em seu nome e só desde
 * que é investidor. Com vários municípios, filtra por um ou vê todos.
 */
export function InvestorBilling({ queryKey, load, onExport }: Props) {
  const [municipalityId, setMunicipalityId] = useState<number | undefined>(undefined)
  const [preset, setPreset] = useState<Preset>('month')
  const [custom, setCustom] = useState(() => presetRange('month') as { from: string; to: string })
  const range = preset === 'custom' ? custom : (presetRange(preset) as { from: string; to: string })

  const query = useQuery({
    queryKey: [...queryKey, municipalityId ?? 'all', range.from, range.to],
    queryFn: () => load({ municipality_id: municipalityId, from: range.from, to: range.to }),
    placeholderData: (prev) => prev,
  })
  const data = query.data

  const [exporting, setExporting] = useState<InvestorExportFormat | null>(null)
  const [exportMsg, setExportMsg] = useState<string | null>(null)
  const runExport = async (format: InvestorExportFormat) => {
    if (!onExport) return
    setExporting(format)
    setExportMsg(null)
    try {
      await onExport(format, { municipality_id: municipalityId, from: range.from, to: range.to })
    } catch (e) {
      setExportMsg(await exportError(e))
    } finally {
      setExporting(null)
    }
  }

  const maxDaily = useMemo(() => Math.max(1, ...(data?.daily ?? []).map((d) => d.collected)), [data])

  if (query.isLoading) {
    return <div className="flex justify-center py-16 text-gray"><Loader2 className="animate-spin" size={24} aria-label="A carregar" /></div>
  }
  if (query.isError && !data && axios.isAxiosError(query.error) && query.error.response?.status === 403 && /desativada/i.test(getApiErrorMessage(query.error, ''))) {
    return (
      <div className="rounded-2xl border border-dashed border-gray/25 bg-white p-8 text-center">
        <Ban className="mx-auto text-danger/70" size={36} aria-hidden="true" />
        <p className="mt-2 font-semibold text-dark">A conta de investidor está desativada</p>
        <p className="mt-1 text-sm text-gray">A faturação deixou de estar disponível. O que já ganhou fica registado,fale com o Super Admin pela conversa.</p>
      </div>
    )
  }
  if (query.isError && !data) {
    return <p role="alert" className="rounded-xl bg-danger/10 p-4 text-sm text-danger">{getApiErrorMessage(query.error, 'Não foi possível carregar a faturação.')}</p>
  }
  if (!data) return null

  if (data.municipalities.length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-gray/25 bg-white p-8 text-center">
        <Landmark className="mx-auto text-primary/60" size={36} aria-hidden="true" />
        <p className="mt-2 font-semibold text-dark">Ainda não está associado a nenhum município</p>
        <p className="mt-1 text-sm text-gray">Quando o Super Admin o associar, a faturação aparece aqui.</p>
      </div>
    )
  }

  const selected = data.municipalities.find((m) => m.id === (municipalityId ?? (data.municipalities.length === 1 ? data.municipalities[0].id : undefined)))
  const chip = (active: boolean) => `rounded-full border px-3 py-1.5 text-xs font-medium ${active ? 'border-primary bg-primary text-white' : 'border-gray/20 bg-white text-dark'}`

  return (
    <div className="space-y-4">
      {/* Municípios do investidor */}
      <section aria-label="Os seus municípios" className="rounded-2xl border border-gray/15 bg-white p-3">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray">Os seus municípios</p>
        <div className="flex flex-wrap gap-2">
          {data.municipalities.length > 1 && (
            <button type="button" aria-pressed={municipalityId === undefined} onClick={() => setMunicipalityId(undefined)} className={chip(municipalityId === undefined)}>
              Todos ({data.municipalities.length})
            </button>
          )}
          {data.municipalities.map((m) => (
            <button
              key={m.id}
              type="button"
              aria-pressed={municipalityId === m.id || data.municipalities.length === 1}
              onClick={() => setMunicipalityId(m.id)}
              className={chip(municipalityId === m.id || data.municipalities.length === 1)}
            >
              <MapPin size={12} className="mr-1 inline" aria-hidden="true" />{m.name} · {pct(m.percentage)}
            </button>
          ))}
        </div>
        {selected && (
          <p className="mt-2 text-xs text-gray">
            {selected.name}{selected.province ? `, ${selected.province}` : ''},investidor desde {dateLabel(selected.since)}, com {pct(selected.percentage)} da comissão do KeruGás.
          </p>
        )}
      </section>

      {/* Período */}
      <section aria-label="Período" className="flex flex-wrap items-end gap-2">
        {PRESETS.map((p) => (
          <button key={p.key} type="button" aria-pressed={preset === p.key} onClick={() => setPreset(p.key)} className={chip(preset === p.key)}>{p.label}</button>
        ))}
        {preset === 'custom' && (
          <div className="flex w-full flex-wrap gap-2 sm:w-auto">
            <label className="text-xs text-gray">De
              <input type="date" value={custom.from} max={custom.to} onChange={(e) => setCustom((c) => ({ ...c, from: e.target.value }))} className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm text-dark" />
            </label>
            <label className="text-xs text-gray">Até
              <input type="date" value={custom.to} min={custom.from} onChange={(e) => setCustom((c) => ({ ...c, to: e.target.value }))} className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm text-dark" />
            </label>
          </div>
        )}
      </section>
      {onExport && (
        <section aria-label="Exportar" className="flex flex-wrap items-center gap-2">
          <span className="text-xs text-gray">Exportar este período:</span>
          <button type="button" disabled={exporting !== null} onClick={() => void runExport('csv')} className="flex items-center gap-1.5 rounded-full border border-gray/20 bg-white px-3 py-1.5 text-xs font-medium text-dark disabled:opacity-60">
            {exporting === 'csv' ? <Loader2 size={13} className="animate-spin" /> : <FileSpreadsheet size={13} className="text-success" aria-hidden="true" />} Excel
          </button>
          <button type="button" disabled={exporting !== null} onClick={() => void runExport('pdf')} className="flex items-center gap-1.5 rounded-full border border-gray/20 bg-white px-3 py-1.5 text-xs font-medium text-dark disabled:opacity-60">
            {exporting === 'pdf' ? <Loader2 size={13} className="animate-spin" /> : <FileText size={13} className="text-danger" aria-hidden="true" />} PDF
          </button>
          {exportMsg && <span role="alert" className="text-xs text-danger">{exportMsg}</span>}
        </section>
      )}
      {query.isError && <p role="alert" className="rounded-lg bg-danger/10 p-3 text-xs text-danger">{getApiErrorMessage(query.error, 'Não foi possível carregar este período.')}</p>}

      {/* Números principais */}
      <section aria-label="Resumo" className={`grid gap-3 sm:grid-cols-3 ${query.isFetching ? 'opacity-70 transition-opacity' : ''}`}>
        <div className="rounded-2xl border border-gray/15 bg-white p-4">
          <p className="flex items-center gap-1.5 text-xs font-medium text-gray"><Coins size={14} aria-hidden="true" /> Total arrecadado</p>
          <p className="mt-1 text-2xl font-extrabold tabular-nums text-dark">{formatKz(data.summary.collected_total)}</p>
          <p className="text-xs text-gray">{data.summary.orders} {data.summary.orders === 1 ? 'entrega' : 'entregas'} · {data.summary.bottles} garrafas</p>
        </div>
        <div className="rounded-2xl border border-gray/15 bg-white p-4">
          <p className="flex items-center gap-1.5 text-xs font-medium text-gray"><Landmark size={14} aria-hidden="true" /> Comissão do KeruGás</p>
          <p className="mt-1 text-2xl font-extrabold tabular-nums text-dark">{formatKz(data.summary.commission_total)}</p>
          <p className="text-xs text-gray">O resto é o custo das garrafas.</p>
        </div>
        <div className="rounded-2xl bg-gradient-to-br from-primary to-secondary p-4 text-white">
          <p className="flex items-center gap-1.5 text-xs font-medium text-white/90"><PiggyBank size={14} aria-hidden="true" /> A sua parte</p>
          <p className="mt-1 text-2xl font-extrabold tabular-nums">{formatKz(data.summary.share_total)}</p>
          <p className="text-xs text-white/90">{selected ? `${pct(selected.percentage)} da comissão` : 'A sua percentagem de cada município'}</p>
        </div>
      </section>

      {data.summary.pending_orders > 0 && (
        <p className="flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">
          <Info size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
          {data.summary.pending_orders} {data.summary.pending_orders === 1 ? 'entrega ainda não tem' : 'entregas ainda não têm'} a comissão separada pela central,contam no total, mas a comissão (e a sua parte) só aparece depois.
        </p>
      )}

      {/* Por município (só quando vê todos) */}
      {municipalityId === undefined && data.municipalities.length > 1 && (
        <section aria-label="Por município" className="overflow-x-auto rounded-2xl border border-gray/15 bg-white">
          <table className="w-full text-sm">
            <thead className="bg-light text-left text-xs uppercase text-gray">
              <tr><th className="px-3 py-2">Município</th><th className="px-3 py-2">%</th><th className="px-3 py-2 text-right">Arrecadado</th><th className="px-3 py-2 text-right">Comissão</th><th className="px-3 py-2 text-right">A sua parte</th></tr>
            </thead>
            <tbody>
              {data.municipalities.map((m) => {
                const row = data.by_municipality.find((r) => r.municipality_id === m.id)
                return (
                  <tr key={m.id} className="border-t border-gray/10">
                    <td className="px-3 py-2 font-medium text-dark">{m.name}</td>
                    <td className="px-3 py-2 text-gray">{pct(m.percentage)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatKz(row?.collected_total ?? 0)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatKz(row?.commission_total ?? 0)}</td>
                    <td className="px-3 py-2 text-right font-semibold tabular-nums text-primary">{formatKz(row?.share_total ?? 0)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </section>
      )}

      {/* Dia a dia */}
      <section aria-label="Dia a dia" className="rounded-2xl border border-gray/15 bg-white p-3">
        <p className="mb-2 text-sm font-semibold text-dark">Dia a dia</p>
        {data.daily.length === 0 ? (
          <p className="py-6 text-center text-sm text-gray">Sem entregas neste período.</p>
        ) : (
          <ul className="space-y-1.5">
            {data.daily.map((d) => (
              <li key={d.date} className="grid grid-cols-[4.5rem_1fr_auto] items-center gap-2 text-xs">
                <span className="text-gray">{new Date(`${d.date}T12:00:00`).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short' })}</span>
                <span className="h-3 overflow-hidden rounded-full bg-light" aria-hidden="true">
                  <span className="block h-full rounded-full bg-primary/70" style={{ width: `${Math.max(3, (d.collected / maxDaily) * 100)}%` }} />
                </span>
                <span className="tabular-nums text-dark">{formatKz(d.collected)} · <strong className="text-primary">{formatKz(d.share)}</strong></span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Transparência: como se calcula e histórico de percentagens */}
      <section aria-label="Como se calcula" className="rounded-2xl bg-light p-3 text-xs text-gray">
        <p className="flex items-start gap-2"><ShieldCheck size={14} className="mt-0.5 shrink-0 text-success" aria-hidden="true" /> {data.rule} Pedidos cancelados não contam. Período: {new Date(`${data.range.from}T12:00:00`).toLocaleDateString('pt-PT')} a {new Date(`${data.range.to}T12:00:00`).toLocaleDateString('pt-PT')}.</p>
        {data.periods.length > 1 && (
          <ul className="mt-2 space-y-0.5 pl-6">
            {data.periods.map((p, i) => (
              <li key={i}>
                {data.municipalities.find((m) => m.id === p.municipality_id)?.name ?? 'Município'}: {pct(p.percentage)} de {dateLabel(p.starts_at)} {p.ends_at ? `a ${dateLabel(p.ends_at)}` : 'até hoje'}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
