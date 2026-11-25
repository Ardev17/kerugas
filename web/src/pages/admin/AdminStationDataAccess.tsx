import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, Loader2, Lock, LockOpen, Search, ShieldCheck, ShieldX, Users } from 'lucide-react'
import { adminService } from '@/services/adminService'
import type { StationDataEffect, StationDataMunicipality, StationDataPerson } from '@/services/adminService'
import { getApiErrorMessage } from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { hasSuperPowers, isProvinceSuperAdmin } from '@/lib/roles'

/**
 * Acesso à consulta dos postos (Novembro 2026).
 *
 * A torneira do período gratuito. O município é a regra geral,fechado ou
 * aberto,e a pessoa é a excepção, que ganha sempre ao município. Só o Super
 * Admin geral e o Provincial; o servidor recusa qualquer outro papel com 403,
 * esta guarda só evita mostrar a página a quem chegue por link directo.
 */

const EFFECT_LABEL: Record<StationDataEffect, string> = {
  INHERIT: 'Segue o município',
  ALLOW: 'Pode sempre',
  DENY: 'Nunca pode',
}

function dataCurta(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric' })
}


/** O pessoal de posto também paga a consulta: identifica-se na lista para não ser confundido. */
const PAPEL_DO_POSTO: Record<'STATION_MANAGER' | 'STATION_OPERATOR' | 'STATION_DRIVER', string> = {
  STATION_MANAGER: 'Gerente de posto',
  STATION_OPERATOR: 'Operador de posto',
  STATION_DRIVER: 'Condutor de posto',
}

export default function AdminStationDataAccess() {
  const { user } = useAuth()
  const canSee = hasSuperPowers(user)
  const [aba, setAba] = useState<'municipios' | 'pessoas'>('municipios')

  if (user && !canSee) return <Navigate to="/admin" replace />

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-dark">Acesso à consulta dos postos</h1>
        <p className="text-sm text-gray">
          {isProvinceSuperAdmin(user)
            ? <>Província de <strong className="text-dark">{user?.province ?? '—'}</strong>.</>
            : 'Toda a plataforma.'}
          {' '}Fechar a consulta num município deixa de mostrar os postos dessa zona a quem não tem
          subscrição. As excepções por pessoa ganham sempre à regra do município.
        </p>
      </div>

      <div role="tablist" aria-label="Secções" className="flex rounded-lg border border-gray/20 p-0.5">
        {([['municipios', 'Municípios'], ['pessoas', 'Pessoas']] as const).map(([chave, rotulo]) => (
          <button
            key={chave}
            type="button"
            role="tab"
            aria-selected={aba === chave}
            onClick={() => setAba(chave)}
            className={`rounded-md px-4 py-1.5 text-sm font-medium ${aba === chave ? 'bg-primary text-white' : 'text-gray hover:text-dark'}`}
          >
            {rotulo}
          </button>
        ))}
      </div>

      {aba === 'municipios' ? <Municipios /> : <Pessoas />}
    </div>
  )
}

// ------------------------------------------------------------------ municípios

function Municipios() {
  const queryClient = useQueryClient()
  const [aberto, setAberto] = useState<number | null>(null)

  const query = useQuery({
    queryKey: ['station-data-municipalities'],
    queryFn: adminService.stationDataMunicipalities,
    staleTime: 30_000,
  })

  const mutation = useMutation({
    mutationFn: ({ id, ...payload }: { id: number; blocked: boolean; from?: string; reason?: string }) =>
      adminService.setStationDataMunicipality(id, payload),
    onSuccess: () => {
      setAberto(null)
      queryClient.invalidateQueries({ queryKey: ['station-data-municipalities'] })
    },
  })

  if (query.isLoading) return <Carregando />
  if (query.isError) return <Erro onRetry={() => query.refetch()} />

  const linhas = query.data ?? []
  if (linhas.length === 0) return <Vazio texto="Não há municípios no seu âmbito." />

  return (
    <div className="space-y-3">
      {mutation.isError && <Aviso texto={getApiErrorMessage(mutation.error, 'Não foi possível guardar a alteração.')} />}

      <ul className="divide-y divide-gray/10 overflow-hidden rounded-xl border border-gray/15 bg-white">
        {linhas.map((m) => (
          <li key={m.id} className="p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate font-semibold text-dark">{m.name}</p>
                <p className="text-xs text-gray">
                  {m.province ?? '—'}
                  {m.blocked_from && <> · {m.scheduled ? 'fecha a ' : 'fechado desde '}{dataCurta(m.blocked_from)}</>}
                  {m.blocked_by && <> · por {m.blocked_by}</>}
                </p>
                {m.reason && <p className="mt-1 text-xs italic text-gray">“{m.reason}”</p>}
              </div>

              <div className="flex items-center gap-3">
                <EstadoMunicipio municipio={m} />
                {m.blocked || m.scheduled ? (
                  <button
                    type="button"
                    onClick={() => mutation.mutate({ id: m.id, blocked: false })}
                    disabled={mutation.isPending}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark hover:bg-gray/5 disabled:opacity-50"
                  >
                    <LockOpen className="h-4 w-4" aria-hidden="true" />
                    Reabrir
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => setAberto(aberto === m.id ? null : m.id)}
                    aria-expanded={aberto === m.id}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90"
                  >
                    <Lock className="h-4 w-4" aria-hidden="true" />
                    Fechar
                  </button>
                )}
              </div>
            </div>

            {aberto === m.id && (
              <FormularioFecho
                aPedir={mutation.isPending}
                onCancelar={() => setAberto(null)}
                onConfirmar={(from, reason) => mutation.mutate({ id: m.id, blocked: true, from, reason })}
              />
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}

function EstadoMunicipio({ municipio }: { municipio: StationDataMunicipality }) {
  if (municipio.blocked) {
    return <Etiqueta tom="fechado" icone={Lock} texto="Fechado" />
  }
  if (municipio.scheduled) {
    return <Etiqueta tom="agendado" icone={CalendarClock} texto="Agendado" />
  }
  return <Etiqueta tom="aberto" icone={LockOpen} texto="Aberto" />
}

function FormularioFecho({
  aPedir, onConfirmar, onCancelar,
}: {
  aPedir: boolean
  onConfirmar: (from?: string, reason?: string) => void
  onCancelar: () => void
}) {
  const [quando, setQuando] = useState('')
  const [motivo, setMotivo] = useState('')
  const hoje = new Date().toISOString().slice(0, 10)

  return (
    <form
      className="mt-4 space-y-3 rounded-lg bg-gray/5 p-4"
      onSubmit={(e) => {
        e.preventDefault()
        onConfirmar(quando || undefined, motivo.trim() || undefined)
      }}
    >
      <div className="flex flex-wrap gap-4">
        <label className="text-xs font-semibold text-dark">
          A partir de
          <input
            type="date"
            value={quando}
            min={hoje}
            onChange={(e) => setQuando(e.target.value)}
            className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm"
          />
          <span className="mt-1 block font-normal text-gray">Em branco fecha já. Com data, fica agendado.</span>
        </label>

        <label className="min-w-[16rem] flex-1 text-xs font-semibold text-dark">
          Motivo que o utilizador vai ler
          <input
            type="text"
            value={motivo}
            maxLength={200}
            placeholder="Ex.: Período gratuito terminado. Fale connosco para continuar."
            onChange={(e) => setMotivo(e.target.value)}
            className="mt-1 block w-full rounded-lg border border-gray/20 px-2 py-1.5 text-sm font-normal"
          />
        </label>
      </div>

      <div className="flex gap-2">
        <button
          type="submit"
          disabled={aPedir}
          className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
        >
          {aPedir && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
          Confirmar
        </button>
        <button
          type="button"
          onClick={onCancelar}
          className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark hover:bg-gray/5"
        >
          Cancelar
        </button>
      </div>
    </form>
  )
}

// --------------------------------------------------------------------- pessoas

function Pessoas() {
  const queryClient = useQueryClient()
  const [procura, setProcura] = useState('')
  const [termo, setTermo] = useState('')
  const [aberto, setAberto] = useState<number | null>(null)

  const query = useQuery({
    queryKey: ['station-data-users', termo],
    queryFn: () => adminService.stationDataUsers(termo || undefined),
    staleTime: 15_000,
  })

  const mutation = useMutation({
    mutationFn: ({ id, ...payload }: { id: number; effect: StationDataEffect; expires_at?: string; reason?: string }) =>
      adminService.setStationDataUser(id, payload),
    onSuccess: () => {
      setAberto(null)
      queryClient.invalidateQueries({ queryKey: ['station-data-users'] })
    },
  })

  return (
    <div className="space-y-3">
      <form
        className="flex gap-2"
        onSubmit={(e) => { e.preventDefault(); setTermo(procura.trim()) }}
      >
        <label className="flex-1">
          <span className="sr-only">Procurar por nome ou telefone</span>
          <div className="flex items-center gap-2 rounded-lg border border-gray/20 bg-white px-3 py-2">
            <Search className="h-4 w-4 shrink-0 text-gray" aria-hidden="true" />
            <input
              type="search"
              value={procura}
              onChange={(e) => setProcura(e.target.value)}
              placeholder="Nome ou telefone"
              className="w-full text-sm outline-none"
            />
          </div>
        </label>
        <button type="submit" className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90">
          Procurar
        </button>
      </form>

      {mutation.isError && <Aviso texto={getApiErrorMessage(mutation.error, 'Não foi possível guardar a alteração.')} />}

      {query.isLoading ? <Carregando />
        : query.isError ? <Erro onRetry={() => query.refetch()} />
        : (query.data ?? []).length === 0 ? <Vazio texto="Nenhuma pessoa encontrada." />
        : (
          <ul className="divide-y divide-gray/10 overflow-hidden rounded-xl border border-gray/15 bg-white">
            {(query.data ?? []).map((p) => (
              <li key={p.id} className="p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate font-semibold text-dark">{p.name}</p>
                    {p.role && p.role !== 'USER' && (
                      <span className="mt-0.5 inline-block rounded-full bg-gray/10 px-2 py-0.5 text-[11px] font-semibold text-gray">
                        {PAPEL_DO_POSTO[p.role]}
                      </span>
                    )}
                    <p className="text-xs text-gray">
                      {p.phone ?? '—'}{p.municipality && <> · {p.municipality}</>}
                      {p.effect !== 'INHERIT' && p.expires_at && (
                        <> · {p.active ? 'até ' : 'expirou a '}{dataCurta(p.expires_at)}</>
                      )}
                    </p>
                    {p.reason && <p className="mt-1 text-xs italic text-gray">“{p.reason}”</p>}
                  </div>
                  <EstadoPessoa pessoa={p} />
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <div role="group" aria-label={`Acesso de ${p.name}`} className="flex rounded-lg border border-gray/20 p-0.5">
                    {(Object.keys(EFFECT_LABEL) as StationDataEffect[]).map((efeito) => (
                      <button
                        key={efeito}
                        type="button"
                        aria-pressed={p.effect === efeito}
                        disabled={mutation.isPending}
                        onClick={() => {
                          // "Pode sempre" pode levar prazo; os outros dois são imediatos.
                          if (efeito === 'ALLOW') setAberto(aberto === p.id ? null : p.id)
                          else mutation.mutate({ id: p.id, effect: efeito })
                        }}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50 ${p.effect === efeito ? 'bg-primary text-white' : 'text-gray hover:text-dark'}`}
                      >
                        {EFFECT_LABEL[efeito]}
                      </button>
                    ))}
                  </div>
                </div>

                {aberto === p.id && (
                  <FormularioExcepcao
                    aPedir={mutation.isPending}
                    onCancelar={() => setAberto(null)}
                    onConfirmar={(expires_at, reason) => mutation.mutate({ id: p.id, effect: 'ALLOW', expires_at, reason })}
                  />
                )}
              </li>
            ))}
          </ul>
        )}
    </div>
  )
}

function EstadoPessoa({ pessoa }: { pessoa: StationDataPerson }) {
  if (pessoa.effect === 'DENY') return <Etiqueta tom="fechado" icone={ShieldX} texto="Bloqueada" />
  if (pessoa.effect === 'ALLOW') {
    return pessoa.active
      ? <Etiqueta tom="aberto" icone={ShieldCheck} texto="Autorizada" />
      : <Etiqueta tom="agendado" icone={CalendarClock} texto="Prazo expirado" />
  }
  return <Etiqueta tom="neutro" icone={Users} texto="Regra do município" />
}

function FormularioExcepcao({
  aPedir, onConfirmar, onCancelar,
}: {
  aPedir: boolean
  onConfirmar: (expires_at?: string, reason?: string) => void
  onCancelar: () => void
}) {
  const [ate, setAte] = useState('')
  const [motivo, setMotivo] = useState('')
  const amanha = new Date(Date.now() + 86_400_000).toISOString().slice(0, 10)

  return (
    <form
      className="mt-3 space-y-3 rounded-lg bg-gray/5 p-4"
      onSubmit={(e) => {
        e.preventDefault()
        onConfirmar(ate || undefined, motivo.trim() || undefined)
      }}
    >
      <div className="flex flex-wrap gap-4">
        <label className="text-xs font-semibold text-dark">
          Válido até
          <input
            type="date"
            value={ate}
            min={amanha}
            onChange={(e) => setAte(e.target.value)}
            className="mt-1 block rounded-lg border border-gray/20 px-2 py-1.5 text-sm"
          />
          <span className="mt-1 block font-normal text-gray">Em branco não tem prazo.</span>
        </label>

        <label className="min-w-[16rem] flex-1 text-xs font-semibold text-dark">
          Nota interna
          <input
            type="text"
            value={motivo}
            maxLength={200}
            placeholder="Ex.: pagou 30 dias por transferência"
            onChange={(e) => setMotivo(e.target.value)}
            className="mt-1 block w-full rounded-lg border border-gray/20 px-2 py-1.5 text-sm font-normal"
          />
        </label>
      </div>

      <div className="flex gap-2">
        <button
          type="submit"
          disabled={aPedir}
          className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
        >
          {aPedir && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
          Autorizar
        </button>
        <button
          type="button"
          onClick={onCancelar}
          className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark hover:bg-gray/5"
        >
          Cancelar
        </button>
      </div>
    </form>
  )
}

// ----------------------------------------------------------------- partilhado

const TONS = {
  aberto: 'bg-green-50 text-green-700',
  fechado: 'bg-red-50 text-red-700',
  agendado: 'bg-amber-50 text-amber-700',
  neutro: 'bg-gray/10 text-gray',
} as const

function Etiqueta({
  tom, icone: Icone, texto,
}: {
  tom: keyof typeof TONS
  icone: typeof Lock
  texto: string
}) {
  return (
    <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${TONS[tom]}`}>
      <Icone className="h-3.5 w-3.5" aria-hidden="true" />
      {texto}
    </span>
  )
}

function Carregando() {
  return (
    <div className="flex items-center gap-2 rounded-xl border border-gray/15 bg-white p-6 text-sm text-gray">
      <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
      A carregar…
    </div>
  )
}

function Erro({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-6 text-sm">
      <p className="text-dark">Não foi possível carregar.</p>
      <button type="button" onClick={onRetry} className="mt-2 text-primary underline">Tentar de novo</button>
    </div>
  )
}

function Vazio({ texto }: { texto: string }) {
  return <p className="rounded-xl border border-gray/15 bg-white p-6 text-sm text-gray">{texto}</p>
}

function Aviso({ texto }: { texto: string }) {
  return <p role="alert" className="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{texto}</p>
}
