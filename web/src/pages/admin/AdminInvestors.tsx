import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ArrowLeft, Ban, CheckCircle2, Eye, Loader2, MapPin, MessagesSquare, Percent, Plus, Power, Search, Trash2, TrendingUp, X } from 'lucide-react'
import { Avatar } from '@/components/Avatar'
import { InvestorBilling } from '@/components/investor/InvestorBilling'
import { RoomChat } from '@/components/roomchat/RoomChat'
import { getApiErrorMessage } from '@/lib/api'
import { adminService } from '@/services/adminService'
import { investorService, type AdminInvestor, type AdminInvestorsData, type InvestorMunicipality } from '@/services/investorService'
import { territoryService } from '@/services/territoryService'

const pct = (v: number) => `${v.toLocaleString('pt-PT', { maximumFractionDigits: 2 })}%`
const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div role="dialog" aria-modal="true" aria-label={title} className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-bold text-dark">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Fechar" className="text-gray"><X size={18} /></button>
        </div>
        {children}
      </div>
    </div>
  )
}

/** Associar um município (e, se preciso, escolher a pessoa). */
function AssignModal({ investor, data, onClose, onDone }: { investor: AdminInvestor | null; data: AdminInvestorsData; onClose: () => void; onDone: () => void }) {
  const [person, setPerson] = useState<{ id: number; name: string } | null>(investor ? { id: investor.id, name: investor.name } : null)
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  const [provinceId, setProvinceId] = useState<number | ''>('')
  const [municipalityId, setMunicipalityId] = useState<number | ''>('')
  const [percentage, setPercentage] = useState('')
  const [startsOn, setStartsOn] = useState(today())

  useEffect(() => {
    const t = setTimeout(() => setQ(search.trim()), 350)
    return () => clearTimeout(t)
  }, [search])

  const users = useQuery({ queryKey: ['investor-candidates', q], queryFn: () => adminService.listUsers({ search: q }), enabled: !person && q.length >= 2 })
  const candidates = (users.data?.data ?? []).filter((u) => u.role === 'USER' || u.role === 'INVESTOR')
  const provinces = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalities = useQuery({ queryKey: ['municipalities', provinceId], queryFn: () => territoryService.listMunicipalities(Number(provinceId)), enabled: !!provinceId })

  const value = Number(percentage.replace(',', '.'))
  const valid = person && municipalityId && value > 0 && value <= 100

  const save = useMutation({
    mutationFn: () => investorService.assign({ user_id: person!.id, municipality_id: Number(municipalityId), percentage: value, starts_on: startsOn || undefined }),
    onSuccess: onDone,
  })

  return (
    <Modal title={investor ? `Associar município a ${investor.name}` : 'Associar investidor'} onClose={onClose}>
      <div className="space-y-3 text-sm">
        {!investor && (
          person ? (
            <p className="flex items-center justify-between rounded-xl bg-light px-3 py-2">
              <span>Pessoa: <strong className="text-dark">{person.name}</strong></span>
              <button type="button" onClick={() => setPerson(null)} className="text-xs font-medium text-primary">Trocar</button>
            </p>
          ) : (
            <div>
              <label className="mb-1 block font-medium text-dark" htmlFor="inv-search">Pessoa (conta de Utilizador)</label>
              <div className="relative">
                <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray" aria-hidden="true" />
                <input id="inv-search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nome ou telefone…" className="w-full rounded-lg border border-gray/20 py-2 pl-9 pr-3" />
              </div>
              {users.isLoading && q.length >= 2 && <Loader2 className="mt-2 animate-spin text-gray" size={16} />}
              <ul className="mt-1 max-h-40 divide-y divide-gray/10 overflow-y-auto">
                {candidates.map((u) => (
                  <li key={u.id}>
                    <button type="button" onClick={() => setPerson({ id: u.id, name: u.name })} className="w-full py-2 text-left hover:bg-light">
                      <span className="block font-medium text-dark">{u.name}</span>
                      <span className="block text-xs text-gray">{[u.role === 'INVESTOR' ? 'Já é investidor' : 'Utilizador', u.phone, u.municipality].filter(Boolean).join(' · ')}</span>
                    </button>
                  </li>
                ))}
                {q.length >= 2 && users.data && candidates.length === 0 && <li className="py-3 text-xs text-gray">Ninguém encontrado. Só contas de Utilizador podem ser investidores.</li>}
              </ul>
            </div>
          )
        )}

        <div className="grid grid-cols-2 gap-2">
          <label className="block">
            <span className="mb-1 block font-medium text-dark">Província</span>
            <select value={provinceId} onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : ''); setMunicipalityId('') }} className="w-full rounded-lg border border-gray/20 px-2 py-2">
              <option value="">Selecione</option>
              {provinces.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </label>
          <label className="block">
            <span className="mb-1 block font-medium text-dark">Município</span>
            <select value={municipalityId} disabled={!provinceId} onChange={(e) => setMunicipalityId(e.target.value ? Number(e.target.value) : '')} className="w-full rounded-lg border border-gray/20 px-2 py-2 disabled:opacity-50">
              <option value="">Selecione</option>
              {municipalities.data?.map((m) => {
                const taken = data.taken.find((t) => t.municipality_id === m.id)
                return <option key={m.id} value={m.id} disabled={!!taken}>{m.name}{taken ? `,já tem investidor (${taken.investor_name ?? '—'})` : ''}</option>
              })}
            </select>
          </label>
        </div>

        <div className="grid grid-cols-2 gap-2">
          <label className="block">
            <span className="mb-1 block font-medium text-dark">Percentagem</span>
            <span className="relative block">
              <input inputMode="decimal" value={percentage} onChange={(e) => setPercentage(e.target.value)} placeholder="Ex.: 10" aria-label="Percentagem" className="w-full rounded-lg border border-gray/20 py-2 pl-3 pr-8" />
              <Percent size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray" aria-hidden="true" />
            </span>
          </label>
          <label className="block">
            <span className="mb-1 block font-medium text-dark">Conta a partir de</span>
            <input type="date" value={startsOn} max={today()} onChange={(e) => setStartsOn(e.target.value)} className="w-full rounded-lg border border-gray/20 px-2 py-2" />
          </label>
        </div>

        <p className="rounded-lg bg-light p-2.5 text-xs text-gray">
          A percentagem é sobre a <strong>comissão do KeruGás</strong> das entregas desse município, a partir da data escolhida. Um município só pode ter um investidor; a pessoa só vê os municípios a que está associada.
        </p>

        {save.isError && <p role="alert" className="rounded-lg bg-danger/10 p-2.5 text-xs text-danger">{getApiErrorMessage(save.error, 'Não foi possível associar.')}</p>}
        <button type="button" disabled={!valid || save.isPending} onClick={() => save.mutate()} className="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-2.5 font-semibold text-white disabled:opacity-50">
          {save.isPending && <Loader2 size={15} className="animate-spin" />} Associar
        </button>
      </div>
    </Modal>
  )
}

function PercentageModal({ investor, m, onClose, onDone }: { investor: AdminInvestor; m: InvestorMunicipality; onClose: () => void; onDone: () => void }) {
  const [value, setValue] = useState(String(m.percentage))
  const n = Number(value.replace(',', '.'))
  const save = useMutation({ mutationFn: () => investorService.changePercentage(investor.id, m.id, n), onSuccess: onDone })

  return (
    <Modal title={`Percentagem em ${m.name}`} onClose={onClose}>
      <label className="block text-sm">
        <span className="mb-1 block font-medium text-dark">Nova percentagem (atual: {pct(m.percentage)})</span>
        <input inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} aria-label="Nova percentagem" className="w-full rounded-lg border border-gray/20 px-3 py-2" />
      </label>
      <p className="mt-2 text-xs text-gray">Vale só a partir de agora,o que já foi calculado com {pct(m.percentage)} não muda. {investor.name} é avisado.</p>
      {save.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(save.error, 'Não foi possível mudar.')}</p>}
      <button type="button" disabled={!(n > 0 && n <= 100) || save.isPending} onClick={() => save.mutate()} className="mt-3 w-full rounded-xl bg-primary py-2.5 text-sm font-semibold text-white disabled:opacity-50">Guardar</button>
    </Modal>
  )
}

/** Desativar (contrato desfeito) ou reativar um investidor. */
function ActivationModal({ investor, onClose, onDone }: { investor: AdminInvestor; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  const [result, setResult] = useState<string | null>(null)
  const deactivating = investor.active
  const run = useMutation({
    mutationFn: async () => {
      if (deactivating) {
        const r = await investorService.deactivate(investor.id, reason.trim() || undefined)
        return r.freed_municipalities.length ? `Investidor desativado. Municípios libertados: ${r.freed_municipalities.join(', ')}.` : 'Investidor desativado.'
      }
      const r = await investorService.reactivate(investor.id)
      return [
        'Investidor reativado.',
        r.restored.length ? `Volta a ganhar, a partir de agora, em: ${r.restored.join(', ')}.` : '',
        r.skipped.length ? `Já com outro investidor (não voltam): ${r.skipped.join(', ')}.` : '',
      ].filter(Boolean).join(' ')
    },
    onSuccess: (msg) => { setResult(msg); onDone() },
  })

  if (result) {
    return (
      <Modal title="Feito" onClose={onClose}>
        <p className="flex items-start gap-2 text-sm text-dark"><CheckCircle2 size={18} className="mt-0.5 shrink-0 text-success" /> {result}</p>
        <button type="button" onClick={onClose} className="mt-4 w-full rounded-xl bg-primary py-2.5 text-sm font-semibold text-white">Fechar</button>
      </Modal>
    )
  }

  return (
    <Modal title={deactivating ? `Desativar ${investor.name}?` : `Reativar ${investor.name}?`} onClose={onClose}>
      {deactivating ? (
        <div className="space-y-2 text-sm text-gray">
          <p>Use quando o contrato é desfeito. A partir de agora:</p>
          <ul className="list-disc space-y-1 pl-5">
            <li>deixa de receber a percentagem e de ver e exportar a faturação;</li>
            <li>os municípios ({investor.municipalities.map((m) => m.name).join(', ') || 'nenhum'}) ficam livres para outro investidor;</li>
            <li>o que já ganhou fica no histórico e a conversa com o Super Admin continua.</li>
          </ul>
          <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={250} placeholder="Motivo (opcional, o investidor é avisado)" aria-label="Motivo" className="mt-2 w-full rounded-lg border border-gray/20 px-3 py-2 text-dark" />
        </div>
      ) : (
        <p className="text-sm text-gray">
          Volta a ver a faturação e a ganhar a partir de agora, com as mesmas percentagens, nos municípios que ainda estão livres
          {investor.paused_municipalities.length ? ` (${investor.paused_municipalities.map((m) => `${m.name} ${pct(m.percentage)}`).join(', ')})` : ''}. O período em que esteve desativado não conta.
        </p>
      )}
      {run.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(run.error, 'Não foi possível concluir.')}</p>}
      <div className="mt-4 flex gap-2">
        <button type="button" onClick={onClose} className="flex-1 rounded-xl border border-gray/20 py-2.5 text-sm font-medium text-dark">Cancelar</button>
        <button type="button" disabled={run.isPending} onClick={() => run.mutate()} className={`flex-1 rounded-xl py-2.5 text-sm font-semibold text-white disabled:opacity-60 ${deactivating ? 'bg-danger' : 'bg-success'}`}>
          {deactivating ? 'Desativar' : 'Reativar'}
        </button>
      </div>
    </Modal>
  )
}

function RemoveModal({ investor, m, onClose, onDone }: { investor: AdminInvestor; m: InvestorMunicipality; onClose: () => void; onDone: () => void }) {
  const remove = useMutation({ mutationFn: () => investorService.remove(investor.id, m.id), onSuccess: onDone })
  const last = investor.municipalities.length === 1

  return (
    <Modal title="Retirar investidor?" onClose={onClose}>
      <p className="text-sm text-gray">
        {investor.name} deixa de ser o investidor de <strong className="text-dark">{m.name}</strong> e deixa de ver a faturação deste município.
        {last ? ' Como não tem outros municípios, a conta volta a Utilizador.' : ''}
      </p>
      {remove.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(remove.error, 'Não foi possível retirar.')}</p>}
      <div className="mt-4 flex gap-2">
        <button type="button" onClick={onClose} className="flex-1 rounded-xl border border-gray/20 py-2.5 text-sm font-medium text-dark">Cancelar</button>
        <button type="button" disabled={remove.isPending} onClick={() => remove.mutate()} className="flex-1 rounded-xl bg-danger py-2.5 text-sm font-semibold text-white disabled:opacity-60">Retirar</button>
      </div>
    </Modal>
  )
}

/**
 * Investidores (Outubro 2026),só o Super Admin geral: associa contas a
 * municípios com a percentagem, vê exatamente o que cada investidor vê e
 * conversa com cada um em privado.
 */
export default function AdminInvestors() {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const selectedId = Number(params.get('investidor')) || null
  const view = params.get('vista') === 'faturacao' ? 'billing' : 'chat'
  const focusId = Number(params.get('mensagem')) || undefined

  const [assignFor, setAssignFor] = useState<AdminInvestor | null | 'new'>(null)
  const [pctFor, setPctFor] = useState<{ investor: AdminInvestor; m: InvestorMunicipality } | null>(null)
  const [removeFor, setRemoveFor] = useState<{ investor: AdminInvestor; m: InvestorMunicipality } | null>(null)
  const [activationFor, setActivationFor] = useState<AdminInvestor | null>(null)

  const list = useQuery({ queryKey: ['admin-investors'], queryFn: investorService.list, refetchInterval: 60_000 })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['admin-investors'] })
  const done = () => { setAssignFor(null); setPctFor(null); setRemoveFor(null); refresh(); void queryClient.invalidateQueries({ queryKey: ['admin-investor-overview'] }) }

  const selected = list.data?.investors.find((i) => i.id === selectedId)

  if (selectedId) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <button type="button" onClick={() => { setParams({}); refresh() }} className="inline-flex items-center gap-1 text-sm text-gray hover:text-dark"><ArrowLeft size={15} /> Todos os investidores</button>
        <header className="flex items-center gap-3 rounded-2xl border border-gray/15 bg-white p-3">
          <Avatar name={selected?.name ?? '?'} url={selected?.avatar_url ?? null} size="md" />
          <div className="min-w-0">
            <h1 className="truncate text-lg font-bold text-dark">{selected?.name ?? 'Investidor'}</h1>
            <p className="truncate text-xs text-gray">{selected ? selected.municipalities.map((m) => `${m.name} ${pct(m.percentage)}`).join(' · ') || 'Sem municípios' : ''}</p>
          </div>
        </header>
        <div role="tablist" className="flex gap-1 rounded-full bg-light p-1">
          <button type="button" role="tab" aria-selected={view === 'chat'} onClick={() => setParams({ investidor: String(selectedId) })} className={`flex flex-1 items-center justify-center gap-1.5 rounded-full py-2 text-sm font-semibold ${view === 'chat' ? 'bg-white text-dark shadow-sm' : 'text-gray'}`}>
            <MessagesSquare size={15} /> Conversa privada
          </button>
          <button type="button" role="tab" aria-selected={view === 'billing'} onClick={() => setParams({ investidor: String(selectedId), vista: 'faturacao' })} className={`flex flex-1 items-center justify-center gap-1.5 rounded-full py-2 text-sm font-semibold ${view === 'billing' ? 'bg-white text-dark shadow-sm' : 'text-gray'}`}>
            <Eye size={15} /> O que o investidor vê
          </button>
        </div>
        {view === 'billing' ? (
          <InvestorBilling key={selectedId} queryKey={['admin-investor-overview', selectedId]} load={(f) => investorService.overviewOf(selectedId, f)} onExport={(format, f) => investorService.exportOf(selectedId, format, f)} />
        ) : (
          <RoomChat
            key={selectedId}
            type="investor"
            roomId={selectedId}
            focusId={focusId}
            emptyTitle="Ainda não há mensagens"
            emptyText="Conversa privada entre os Super Admins e este investidor. Nenhum outro investidor a vê."
            placeholder="Escreva ao investidor…"
            onSent={refresh}
          />
        )}
      </div>
    )
  }

  const investors = list.data?.investors ?? []

  return (
    <div className="mx-auto max-w-3xl">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-dark"><TrendingUp size={22} className="text-primary" /> Investidores</h1>
          <p className="mt-1 max-w-xl text-sm text-gray">
            Cada investidor vê só a faturação dos municípios a que está associado: total arrecadado, comissão do KeruGás e a sua parte. Um município tem no máximo um investidor.
          </p>
        </div>
        <button type="button" onClick={() => setAssignFor('new')} disabled={!list.data} className="flex items-center gap-1.5 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          <Plus size={15} aria-hidden="true" /> Associar investidor
        </button>
      </div>

      {list.isLoading && <div className="flex justify-center py-12 text-gray"><Loader2 className="animate-spin" size={22} /></div>}
      {list.isError && <p role="alert" className="mt-4 rounded-xl bg-danger/10 p-3 text-sm text-danger">{getApiErrorMessage(list.error, 'Não foi possível carregar os investidores.')}</p>}
      {list.data && investors.length === 0 && (
        <p className="mt-4 rounded-2xl border border-dashed border-gray/25 p-8 text-center text-sm text-gray">Ainda não há investidores. Use “Associar investidor”.</p>
      )}

      <ul className="mt-4 space-y-3">
        {investors.map((inv) => (
          <li key={inv.id} className={`rounded-2xl border p-4 ${inv.active ? 'border-gray/15 bg-white' : 'border-danger/30 bg-danger/5'}`}>
            <div className="flex flex-wrap items-center gap-3">
              <Avatar name={inv.name} url={inv.avatar_url} size="sm" zoomable={false} />
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 truncate font-semibold text-dark">
                  {inv.name}
                  {!inv.active && <span className="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-semibold text-danger"><Ban size={11} aria-hidden="true" /> Desativado</span>}
                </p>
                <p className="truncate text-xs text-gray">{[inv.phone, inv.email].filter(Boolean).join(' · ')}</p>
              </div>
              <button type="button" onClick={() => setParams({ investidor: String(inv.id) })} className="relative flex items-center gap-1 rounded-full border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark">
                <MessagesSquare size={13} /> Conversa
                {inv.chat_unread > 0 && <span className="ml-1 rounded-full bg-primary px-1.5 text-[11px] font-bold text-white" aria-label={`${inv.chat_unread} por ler`}>{inv.chat_unread}</span>}
              </button>
              {inv.active && (
                <button type="button" onClick={() => setParams({ investidor: String(inv.id), vista: 'faturacao' })} className="flex items-center gap-1 rounded-full border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark">
                  <Eye size={13} /> Ver e exportar
                </button>
              )}
              <button type="button" onClick={() => setActivationFor(inv)} aria-label={inv.active ? `Desativar ${inv.name}` : `Reativar ${inv.name}`} className={`flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-semibold ${inv.active ? 'border border-danger/30 text-danger' : 'bg-success text-white'}`}>
                <Power size={13} aria-hidden="true" /> {inv.active ? 'Desativar' : 'Reativar'}
              </button>
            </div>

            <ul className="mt-3 space-y-1.5">
              {inv.municipalities.map((m) => (
                <li key={m.id} className="flex flex-wrap items-center gap-2 rounded-xl bg-light px-3 py-2 text-sm">
                  <MapPin size={14} className="text-primary" aria-hidden="true" />
                  <span className="font-medium text-dark">{m.name}</span>
                  <span className="text-xs text-gray">{m.province} · desde {new Date(m.since).toLocaleDateString('pt-PT')}</span>
                  <span className="ml-auto rounded-full bg-primary/10 px-2 py-0.5 text-xs font-bold text-primary">{pct(m.percentage)}</span>
                  <button type="button" onClick={() => setPctFor({ investor: inv, m })} aria-label={`Mudar a percentagem de ${inv.name} em ${m.name}`} className="rounded-full p-1 text-gray hover:bg-white"><Percent size={14} /></button>
                  <button type="button" onClick={() => setRemoveFor({ investor: inv, m })} aria-label={`Retirar ${inv.name} de ${m.name}`} className="rounded-full p-1 text-danger hover:bg-white"><Trash2 size={14} /></button>
                </li>
              ))}
              {inv.active && inv.municipalities.length === 0 && <li className="text-xs italic text-gray">Sem municípios em vigor.</li>}
              {!inv.active && (
                <li className="text-xs text-danger">
                  Desativado em {inv.deactivated_at ? new Date(inv.deactivated_at).toLocaleDateString('pt-PT') : '—'},sem acesso à faturação.
                  {inv.paused_municipalities.length > 0 && ` Municípios libertados: ${inv.paused_municipalities.map((m) => `${m.name} (${pct(m.percentage)})`).join(', ')}.`}
                </li>
              )}
            </ul>
            {inv.active && <button type="button" onClick={() => setAssignFor(inv)} className="mt-2 flex items-center gap-1 text-xs font-semibold text-primary"><Plus size={13} /> Associar outro município</button>}
          </li>
        ))}
      </ul>

      {assignFor && list.data && (
        <AssignModal investor={assignFor === 'new' ? null : assignFor} data={list.data} onClose={() => setAssignFor(null)} onDone={done} />
      )}
      {pctFor && <PercentageModal investor={pctFor.investor} m={pctFor.m} onClose={() => setPctFor(null)} onDone={done} />}
      {activationFor && <ActivationModal investor={activationFor} onClose={() => setActivationFor(null)} onDone={done} />}
      {removeFor && <RemoveModal investor={removeFor.investor} m={removeFor.m} onClose={() => setRemoveFor(null)} onDone={done} />}
    </div>
  )
}
