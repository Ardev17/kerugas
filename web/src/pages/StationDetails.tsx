import { useState, type ReactNode, useEffect } from 'react'
import axios from 'axios'
import SubscriptionNotice from '@/components/stations/SubscriptionNotice'
import { GasFlame } from '@/components/fx/GasFlame'
import { useParams, Link, useLocation, useNavigate } from 'react-router-dom'
import { stationIdFromParam, stationPath } from '@/app/paths'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Navigation, Bell, Heart, ShieldCheck, Flag, Star, CheckCircle2, X, Clock, PackageCheck, Phone, MapPinned } from 'lucide-react'
import { stationService } from '@/services/stationService'
import { communityService } from '@/services/communityService'
import { favoriteService } from '@/services/favoriteService'
import { alertService } from '@/services/alertService'
import { analyticsService } from '@/services/analyticsService'
import { useAuth } from '@/hooks/useAuth'
import { CentralContactLinks } from '@/components/CentralContactLinks'
import { LastUpdated } from '@/components/LastUpdated'
import { RouteLink } from '@/components/RouteLink'
import { queueLevelLabel } from '@/constants/theme'
import { Avatar } from '@/components/Avatar'
import { useSeo } from '@/seo/useSeo'
import { stationSeo } from '@/seo/stationSeo'

/**
 * Consulta fechada (Novembro 2026). O servidor responde 401 quando precisa de
 * saber quem está do outro lado, e 403 quando já sabe e a resposta é não,em
 * ambos os casos com `reason` e uma mensagem escrita pelo administrador.
 */
interface BloqueioDaConsulta {
  reason: 'login_required' | 'municipality_blocked' | 'user_blocked'
  message: string | null
  municipality: string | null
}

function bloqueioDaConsulta(erro: unknown): BloqueioDaConsulta | null {
  if (!axios.isAxiosError(erro)) return null

  const estado = erro.response?.status
  if (estado !== 401 && estado !== 403) return null

  const corpo = erro.response?.data as {
    reason?: string
    message?: string
    municipality?: { name?: string } | null
  } | undefined

  const motivo = corpo?.reason
  if (motivo !== 'login_required' && motivo !== 'municipality_blocked' && motivo !== 'user_blocked') return null

  return {
    reason: motivo,
    message: corpo?.message ?? null,
    municipality: corpo?.municipality?.name ?? null,
  }
}

const REPORT_REASONS = [
  { value: 'GAS_UNAVAILABLE', label: 'Gás indicado como disponível mas não está' },
  { value: 'QUEUE_INCORRECT', label: 'Fila incorreta' },
  { value: 'STATION_CLOSED', label: 'Posto está fechado' },
  { value: 'LOCATION_INCORRECT', label: 'Localização incorreta' },
  { value: 'MISLEADING_INFORMATION', label: 'Informação enganosa' },
  { value: 'OTHER', label: 'Outro' },
]

export default function StationDetails() {
  // URL amigável (Outubro 2026): /postos/12-posto-central-lubango,o número
  // identifica o posto; o nome é só para pessoas e motores de pesquisa.
  const { id } = useParams<{ id: string }>()
  const stationId = stationIdFromParam(id)
  const location = useLocation()
  const navigate = useNavigate()
  const { isAuthenticated } = useAuth()
  const queryClient = useQueryClient()

  const [activeModal, setActiveModal] = useState<'report' | 'confirm' | 'review' | null>(null)
  const [feedback, setFeedback] = useState<string | null>(null)

  const stationQuery = useQuery({
    queryKey: ['station', stationId],
    queryFn: () => stationService.show(stationId),
    enabled: !!stationId,
    // Consulta fechada (Novembro 2026): 401 e 403 são respostas legítimas com
    // uma explicação dentro. Repetir o pedido só atrasa a mensagem.
    retry: (falhas, erro) => !bloqueioDaConsulta(erro) && falhas < 2,
  })

  const bloqueio = bloqueioDaConsulta(stationQuery.error)

  // SEO do posto (Outubro 2026): título com o nome e a zona, e dados
  // estruturados (schema.org) para os motores de pesquisa e as IAs.
  useSeo(stationQuery.data ? stationSeo(stationQuery.data) : null)

  // Endereço sem nome (/postos/12) ou com um nome antigo → corrige para o atual,
  // sem criar uma entrada nova no histórico.
  useEffect(() => {
    const station = stationQuery.data
    if (!station) return
    const canonical = stationPath(station)
    if (location.pathname !== canonical) navigate(`${canonical}${location.search}${location.hash}`, { replace: true })
  }, [stationQuery.data, location.pathname, location.search, location.hash, navigate])

  useEffect(() => {
    if (stationQuery.data) {
      analyticsService.track('station_view', { station_id: stationQuery.data.id })
    }
  }, [stationQuery.data])

  const reviewsQuery = useQuery({
    queryKey: ['reviews', stationId],
    queryFn: () => communityService.listReviews(stationId),
    enabled: !!stationId,
  })

  const commentsQuery = useQuery({
    queryKey: ['comments', stationId],
    queryFn: () => communityService.listComments(stationId),
    enabled: !!stationId,
  })

  const favoriteMutation = useMutation({
    mutationFn: () => favoriteService.add(stationId),
    onSuccess: () => {
      setFeedback('Adicionado aos favoritos.')
      analyticsService.track('favorite_added', { station_id: stationId })
    },
  })

  const alertMutation = useMutation({
    mutationFn: () => alertService.create({ station_id: stationId, channels: ['in_app', 'sms'] }),
    onSuccess: () => {
      setFeedback('Alerta criado,avisaremos quando houver gás.')
      analyticsService.track('alert_created', { station_id: stationId })
    },
  })

  const reportMutation = useMutation({
    mutationFn: (payload: { reason: string; description?: string }) => communityService.createReport(stationId, payload),
    onSuccess: () => { setActiveModal(null); setFeedback('Denúncia registada. Obrigado.') },
  })

  const confirmMutation = useMutation({
    mutationFn: (payload: { gas_available: boolean; queue_level?: string }) => communityService.createConfirmation(stationId, payload),
    onSuccess: () => {
      setActiveModal(null)
      setFeedback('Confirmação registada. Obrigado por ajudar a comunidade.')
      queryClient.invalidateQueries({ queryKey: ['station', stationId] })
    },
  })

  const reviewMutation = useMutation({
    mutationFn: (payload: { rating: number; comment?: string }) => communityService.createReview(stationId, payload),
    onSuccess: () => {
      setActiveModal(null)
      queryClient.invalidateQueries({ queryKey: ['reviews', stationId] })
    },
  })

  if (stationQuery.isLoading) {
    return <div className="mx-auto max-w-3xl px-4 py-10">A carregar posto...</div>
  }

  if (bloqueio) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-10">
        <SubscriptionNotice
          zones={[{ name: bloqueio.municipality ?? 'Esta zona', reason: bloqueio.message }]}
          needsLogin={bloqueio.reason === 'login_required'}
          accountBlocked={bloqueio.reason === 'user_blocked'}
        />
      </div>
    )
  }

  if (stationQuery.isError || !stationQuery.data) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-10 text-center">
        <p className="font-medium text-dark">Não conseguimos carregar este posto neste momento.</p>
        <button onClick={() => stationQuery.refetch()} className="mt-4 rounded-lg border border-gray/20 px-4 py-2 text-sm font-medium hover:bg-light">
          Tentar novamente
        </button>
      </div>
    )
  }

  const station = stationQuery.data

  const requireAuth = (action: () => void) => {
    if (!isAuthenticated) {
      setFeedback('Precisa de iniciar sessão para esta ação.')
      return
    }
    action()
  }

  return (
    <div className="mx-auto max-w-3xl px-4 py-10">
      <div className="fx-glass fx-slide-next relative overflow-hidden rounded-3xl p-6">
        <div className={`pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full blur-3xl ${station.gas_available ? 'bg-primary/25' : 'bg-gray/10'}`} aria-hidden="true" />
        <div className="relative flex items-start gap-4">
          <GasFlame size={46} lit={station.gas_available} className="shrink-0" />
          <div className="min-w-0">
            <h1 className="text-2xl font-extrabold text-dark sm:text-3xl">{station.name}</h1>
            <p className="text-gray">{station.municipality}, {station.province}</p>
          </div>
        </div>
        {station.address && <p className="mt-1 text-sm text-gray">{station.address}</p>}
        {station.reference_point && (
          <p className="mt-0.5 flex items-center gap-1 text-sm text-gray">
            <MapPinned size={13} /> {station.reference_point}
          </p>
        )}

        <div className="fx-cascade mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
          <Stat i={1} label="Disponibilidade" value={station.gas_available ? 'Disponível' : 'Indisponível'} tone={station.gas_available ? 'text-success' : 'text-danger'} live={station.gas_available} />
          <Stat i={2} label="Fila" value={station.queue_level ? (queueLevelLabel[station.queue_level] ?? station.queue_level) : '-'} />
          <Stat i={3} label="Confiança" value={`${station.confidence_score}%`} icon={<ShieldCheck size={14} />} bar={station.confidence_score} />
        </div>

        {/*
          BUG DE POLÍTICA CORRIGIDO: este bloco mostrava station.phone
          (o telefone individual do posto) com um link tel: direto.
          Decisão explícita do cliente: o contacto de um posto NUNCA
          deve ser exposto ao público,só a central KeruGás. O grid
          mostra-se sempre agora (não só quando há horário/garrafas),
          porque a central está sempre presente.
        */}
        <div className="mt-4 grid gap-3 rounded-lg bg-light p-4 sm:grid-cols-3">
          {station.opening_hours && (
            <div>
              <p className="flex items-center gap-1.5 text-xs text-gray"><Clock size={13} /> Horário</p>
              <p className="mt-0.5 text-sm font-medium text-dark">{station.opening_hours}</p>
            </div>
          )}
          {station.cylinder_types && (
            <div>
              <p className="flex items-center gap-1.5 text-xs text-gray"><PackageCheck size={13} /> Tipos de garrafa</p>
              <p className="mt-0.5 text-sm font-medium text-dark">{station.cylinder_types}</p>
            </div>
          )}
          <div className="sm:col-span-1">
            <p className="mb-1 flex items-center gap-1.5 text-xs text-gray"><Phone size={13} /> Central KeruGás</p>
            <CentralContactLinks variant="compact" />
          </div>
        </div>

        <p className="mt-4 text-sm text-gray"><LastUpdated at={station.status_updated_at} /></p>

        {feedback && (
          <div className="mt-4 flex items-center justify-between rounded-lg bg-success/10 px-3 py-2 text-sm text-success">
            {feedback}
            <button onClick={() => setFeedback(null)} aria-label="Fechar"><X size={14} /></button>
          </div>
        )}

        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <RouteLink
            latitude={station.latitude}
            longitude={station.longitude}
            className="fx-btn-fire flex items-center justify-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-semibold text-white"
          >
            <Navigation size={14} /> Traçar rota
          </RouteLink>
          <button
            onClick={() => requireAuth(() => alertMutation.mutate())}
            disabled={alertMutation.isPending}
            className="fx-btn-ghost group flex items-center justify-center gap-1.5 rounded-xl border border-gray/20 px-3 py-2.5 text-sm font-medium disabled:opacity-60"
          >
            <Bell size={14} className="fx-bell" /> Avise-me
          </button>
          <button
            onClick={() => requireAuth(() => favoriteMutation.mutate())}
            disabled={favoriteMutation.isPending}
            className="fx-btn-ghost flex items-center justify-center gap-1.5 rounded-xl border border-gray/20 px-3 py-2.5 text-sm font-medium disabled:opacity-60"
          >
            <Heart size={14} className={favoriteMutation.isSuccess ? 'fx-pop fill-danger text-danger' : ''} /> Favoritar
          </button>
          <button
            onClick={() => requireAuth(() => setActiveModal('report'))}
            className="fx-btn-ghost flex items-center justify-center gap-1.5 rounded-xl border border-gray/20 px-3 py-2.5 text-sm font-medium"
          >
            <Flag size={14} /> Relatar problema
          </button>
        </div>

        <button
          onClick={() => requireAuth(() => setActiveModal('confirm'))}
          className="fx-btn-ghost mt-3 flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-primary/40 px-3 py-2.5 text-sm font-medium text-primary"
        >
          <CheckCircle2 size={14} /> Confirmar informação (ajudar a comunidade)
        </button>
      </div>

      <section className="fx-glass fx-slide-next mt-6 rounded-3xl p-6">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold text-dark">Avaliações</h2>
          <button onClick={() => requireAuth(() => setActiveModal('review'))} className="text-sm font-medium text-primary">
            Avaliar posto
          </button>
        </div>

        <div className="mt-4 space-y-3">
          {reviewsQuery.data?.length === 0 && <p className="text-sm text-gray">Ainda sem avaliações.</p>}
          {reviewsQuery.data?.map((r) => (
            <div key={r.id} className="border-b border-gray/10 pb-3 last:border-0">
              <div className="flex items-center gap-1">
                {Array.from({ length: 5 }).map((_, i) => (
                  <Star key={i} size={14} className={i < r.rating ? 'fill-warning text-warning' : 'text-gray/30'} />
                ))}
                <Avatar name={r.user_name} url={r.user_avatar_url} size="xs" className="ml-2" />
                <span className="ml-1 text-sm font-medium text-dark">{r.user_name}</span>
              </div>
              {r.comment && <p className="mt-1 text-sm text-gray">{r.comment}</p>}
            </div>
          ))}
        </div>
      </section>

      <section className="fx-glass fx-slide-next mt-6 rounded-3xl p-6">
        <h2 className="font-semibold text-dark">Comentários</h2>
        <CommentForm stationId={stationId} isAuthenticated={isAuthenticated} onRequireAuth={() => setFeedback('Precisa de iniciar sessão para comentar.')} />
        <div className="mt-4 space-y-3">
          {commentsQuery.data?.length === 0 && <p className="text-sm text-gray">Ainda sem comentários.</p>}
          {commentsQuery.data?.map((c) => (
            <div key={c.id} className="border-b border-gray/10 pb-3 last:border-0">
              <p className="flex items-center gap-2 text-sm font-medium text-dark">
                <Avatar name={c.user_name} url={c.user_avatar_url} size="xs" /> {c.user_name}
              </p>
              <p className="mt-1 text-sm text-gray">{c.content}</p>
            </div>
          ))}
        </div>
      </section>

      <div className="mt-6 rounded-xl border border-dashed border-primary/30 bg-primary/5 p-6 text-center">
        <p className="font-medium text-dark">Não encontrou o que precisava aqui?</p>
        <Link to="/pedir-gas" className="mt-3 inline-block rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">
          Pedir gás para entrega
        </Link>
        <p className="mt-4 text-xs text-gray">Ou ligue diretamente para a central KeruGás:</p>
        <div className="mt-2 flex justify-center">
          <CentralContactLinks variant="compact" />
        </div>
      </div>

      {activeModal === 'report' && (
        <ReportModal onClose={() => setActiveModal(null)} onSubmit={(v) => reportMutation.mutate(v)} isPending={reportMutation.isPending} />
      )}
      {activeModal === 'confirm' && (
        <ConfirmModal onClose={() => setActiveModal(null)} onSubmit={(v) => confirmMutation.mutate(v)} isPending={confirmMutation.isPending} />
      )}
      {activeModal === 'review' && (
        <ReviewModal onClose={() => setActiveModal(null)} onSubmit={(v) => reviewMutation.mutate(v)} isPending={reviewMutation.isPending} />
      )}
    </div>
  )
}

function Stat({ i = 0, label, value, icon, tone = 'text-dark', live = false, bar }: { i?: number; label: string; value: string; icon?: ReactNode; tone?: string; live?: boolean; bar?: number }) {
  return (
    <div style={{ ['--i' as string]: i }} className="rounded-2xl bg-light p-3 text-center">
      <p className="flex items-center justify-center gap-1 text-xs text-gray">{icon}{label}</p>
      <p className={`mt-1 flex items-center justify-center gap-1.5 font-bold ${tone}`}>
        {live && <span className="fx-live-dot" aria-hidden="true" />}
        {value}
      </p>
      {bar !== undefined && (
        <div className="fx-bar mx-auto mt-2 w-4/5" aria-hidden="true">
          <i style={{ width: `${Math.max(0, Math.min(100, bar))}%` }} />
        </div>
      )}
    </div>
  )
}

function ModalShell({ title, children, onClose }: { title: string; children: ReactNode; onClose: () => void }) {
  return (
    <div className="fx-backdrop-in fixed inset-0 z-50 flex items-end justify-center bg-black/40 backdrop-blur-sm sm:items-center" role="dialog" aria-modal="true">
      <div className="fx-modal-in w-full max-w-sm rounded-t-3xl bg-white p-6 shadow-2xl sm:rounded-3xl">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">{title}</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        <div className="mt-4">{children}</div>
      </div>
    </div>
  )
}

function ReportModal({ onClose, onSubmit, isPending }: { onClose: () => void; onSubmit: (v: { reason: string; description?: string }) => void; isPending: boolean }) {
  const [reason, setReason] = useState(REPORT_REASONS[0].value)
  const [description, setDescription] = useState('')

  return (
    <ModalShell title="Relatar problema" onClose={onClose}>
      <div className="space-y-3">
        <select aria-label="Motivo da denúncia" className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" value={reason} onChange={(e) => setReason(e.target.value)}>
          {REPORT_REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
        </select>
        <textarea
          className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
          rows={3}
          placeholder="Descreva o problema (opcional)"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
        />
        <button
          onClick={() => onSubmit({ reason, description: description || undefined })}
          disabled={isPending}
          className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
        >
          {isPending ? 'A enviar...' : 'Enviar denúncia'}
        </button>
      </div>
    </ModalShell>
  )
}

function ConfirmModal({ onClose, onSubmit, isPending }: { onClose: () => void; onSubmit: (v: { gas_available: boolean; queue_level?: string }) => void; isPending: boolean }) {
  const [gasAvailable, setGasAvailable] = useState(true)
  const [queueLevel, setQueueLevel] = useState('LOW')

  return (
    <ModalShell title="Confirmar informação" onClose={onClose}>
      <div className="space-y-3">
        <div className="flex gap-2">
          <button
            onClick={() => setGasAvailable(true)}
            className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium ${gasAvailable ? 'border-success bg-success/10 text-success' : 'border-gray/20'}`}
          >
            Tem gás
          </button>
          <button
            onClick={() => setGasAvailable(false)}
            className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium ${!gasAvailable ? 'border-danger bg-danger/10 text-danger' : 'border-gray/20'}`}
          >
            Sem gás
          </button>
        </div>
        <select aria-label="Nível da fila" className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" value={queueLevel} onChange={(e) => setQueueLevel(e.target.value)}>
          <option value="LOW">Fila baixa</option>
          <option value="MEDIUM">Fila média</option>
          <option value="HIGH">Fila alta</option>
        </select>
        <button
          onClick={() => onSubmit({ gas_available: gasAvailable, queue_level: queueLevel })}
          disabled={isPending}
          className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
        >
          {isPending ? 'A confirmar...' : 'Confirmar'}
        </button>
      </div>
    </ModalShell>
  )
}

function ReviewModal({ onClose, onSubmit, isPending }: { onClose: () => void; onSubmit: (v: { rating: number; comment?: string }) => void; isPending: boolean }) {
  const [rating, setRating] = useState(5)
  const [comment, setComment] = useState('')

  return (
    <ModalShell title="Avaliar posto" onClose={onClose}>
      <div className="space-y-3">
        <div className="flex justify-center gap-1">
          {Array.from({ length: 5 }).map((_, i) => (
            <button key={i} onClick={() => setRating(i + 1)} aria-label={`${i + 1} estrelas`}>
              <Star size={28} className={i < rating ? 'fill-warning text-warning' : 'text-gray/30'} />
            </button>
          ))}
        </div>
        <textarea
          className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
          rows={3}
          placeholder="Comentário (opcional)"
          value={comment}
          onChange={(e) => setComment(e.target.value)}
        />
        <button
          onClick={() => onSubmit({ rating, comment: comment || undefined })}
          disabled={isPending}
          className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
        >
          {isPending ? 'A enviar...' : 'Enviar avaliação'}
        </button>
      </div>
    </ModalShell>
  )
}

function CommentForm({ stationId, isAuthenticated, onRequireAuth }: { stationId: number; isAuthenticated: boolean; onRequireAuth: () => void }) {
  const [content, setContent] = useState('')
  const queryClient = useQueryClient()

  const mutation = useMutation({
    mutationFn: () => communityService.createComment(stationId, content),
    onSuccess: () => {
      setContent('')
      queryClient.invalidateQueries({ queryKey: ['comments', stationId] })
    },
  })

  const submit = () => {
    if (!isAuthenticated) {
      onRequireAuth()
      return
    }
    if (content.trim().length < 3) return
    mutation.mutate()
  }

  return (
    <div className="mt-3 flex gap-2">
      <input
        aria-label="Escrever um comentário"
        className="flex-1 rounded-lg border border-gray/20 px-3 py-2 text-sm"
        placeholder="Escreva um comentário..."
        value={content}
        onChange={(e) => setContent(e.target.value)}
      />
      <button onClick={submit} disabled={mutation.isPending} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
        Enviar
      </button>
    </div>
  )
}
