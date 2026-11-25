import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BellRing, Zap, CalendarClock, Send, History, Pencil, FlaskConical, Play, MessageSquareText, Users,
  TrendingUp, AlertTriangle, Plus, Trash2, X, Loader2, Moon, CheckCircle2,
} from 'lucide-react'
import {
  notificationCenterService, type BroadcastAudience, type BroadcastPayload, type BroadcastPreview, type Campaign,
  type CampaignTemplate,
} from '@/services/notificationCenterService'
import { territoryService } from '@/services/territoryService'
import { getApiErrorMessage } from '@/lib/api'
import { smsInfo } from '@/lib/sms'
import { useAuth } from '@/hooks/useAuth'
import { hasSuperPowers, isProvinceSuperAdmin } from '@/lib/roles'

type Tab = 'campaigns' | 'send' | 'history'

const AUDIENCES: Array<{ value: BroadcastAudience; label: string }> = [
  { value: 'users', label: 'Clientes' },
  { value: 'drivers', label: 'Condutores' },
  { value: 'operators', label: 'Operadores de posto' },
  { value: 'admins', label: 'Admins e Super Admins' },
  { value: 'all', label: 'Todos' },
]

const DESTINATIONS = [
  { value: '/', label: 'Início' },
  { value: '/pedir-gas', label: 'Pedir gás' },
  { value: '/pedir-gas?repetir=1', label: 'Repetir o último pedido' },
  { value: '/mapa', label: 'Mapa' },
  { value: '/encontrar-gas', label: 'Encontrar gás' },
  { value: '/alerts', label: 'Alertas' },
  { value: '/pedidos', label: 'Os meus pedidos' },
  { value: '/driver', label: 'Painel do condutor' },
  { value: '/operator', label: 'Painel do operador' },
  { value: '/admin', label: 'Painel administrativo' },
]

const STATUS_LABEL: Record<string, { label: string; tone: string }> = {
  SCHEDULED: { label: 'Agendado', tone: 'bg-warning/15 text-warning' },
  SENDING: { label: 'A enviar', tone: 'bg-primary/10 text-primary' },
  SENT: { label: 'Enviado', tone: 'bg-success/10 text-success' },
  CANCELLED: { label: 'Cancelado', tone: 'bg-gray/15 text-gray' },
  FAILED: { label: 'Falhou', tone: 'bg-danger/10 text-danger' },
}

const kz = (value: number) => `${value.toLocaleString('pt-AO', { maximumFractionDigits: 0 })} Kz`

/**
 * Notificações,só Super Admin (Setembro 2026). O backend recusa (403)
 * qualquer outro papel (`permission:notifications.manage`); esta
 * verificação aqui é só para mostrar uma mensagem clara.
 */
export default function AdminNotifications() {
  const { user } = useAuth()
  // Super Admin Provincial (Outubro 2026): só envios aos utilizadores da
  // sua província; as campanhas automáticas são do Super Admin geral.
  const provincial = isProvinceSuperAdmin(user)
  const [tab, setTab] = useState<Tab>(provincial ? 'send' : 'campaigns')

  if (!hasSuperPowers(user)) {
    return <p className="py-16 text-center text-sm text-gray">Esta secção está disponível apenas para o Super Admin.</p>
  }

  const tabs: Array<{ id: Tab; label: string; icon: typeof Zap }> = [
    ...(provincial ? [] : [{ id: 'campaigns' as Tab, label: 'Campanhas automáticas', icon: Zap }]),
    { id: 'send', label: 'Enviar notificação', icon: Send },
    { id: 'history', label: 'Histórico de envios', icon: History },
  ]

  return (
    <div>
      <h1 className="flex items-center gap-2 text-xl font-bold text-dark">
        <BellRing size={22} className="text-primary" /> Notificações
      </h1>
      <p className="mt-1 text-sm text-gray">
        {provincial
          ? <>Envios aos utilizadores da província de <strong className="text-dark">{user?.province ?? '—'}</strong>,no sino, no telemóvel (push) e, quando escolhido, por SMS.</>
          : 'Campanhas automáticas e envios manuais,no sino do site e do app, no telemóvel (push) e, quando escolhido, por SMS.'}
      </p>

      <div className="mt-5 flex gap-1 overflow-x-auto rounded-xl bg-light p-1" role="tablist">
        {tabs.map(({ id, label, icon: Icon }) => (
          <button
            key={id}
            role="tab"
            aria-selected={tab === id}
            onClick={() => setTab(id)}
            className={`flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
              tab === id ? 'bg-white text-primary shadow-sm' : 'text-gray hover:text-dark'
            }`}
          >
            <Icon size={16} /> {label}
          </button>
        ))}
      </div>

      <div className="mt-5">
        {tab === 'campaigns' && !provincial && <CampaignsTab />}
        {tab === 'send' && <SendTab onSent={() => setTab('history')} lockedProvinceId={provincial ? user?.province_id ?? null : null} />}
        {tab === 'history' && <HistoryTab />}
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Campanhas automáticas                                               */
/* ------------------------------------------------------------------ */

function CampaignsTab() {
  const campaignsQuery = useQuery({ queryKey: ['admin-campaigns'], queryFn: notificationCenterService.campaigns })
  const campaigns = useMemo(() => campaignsQuery.data ?? [], [campaignsQuery.data])

  const totals = useMemo(() => campaigns.reduce(
    (acc, c) => ({
      sent: acc.sent + c.stats.sent_30d,
      converted: acc.converted + c.stats.converted_30d,
      smsCost: acc.smsCost + c.stats.sms_cost_30d_kz,
    }),
    { sent: 0, converted: 0, smsCost: 0 },
  ), [campaigns])

  if (campaignsQuery.isLoading) {
    return <div className="flex justify-center py-16 text-gray"><Loader2 className="animate-spin" /></div>
  }

  return (
    <div>
      <div className="grid gap-3 sm:grid-cols-3">
        <Kpi icon={Send} label="Enviadas (30 dias)" value={totals.sent.toLocaleString('pt-AO')} />
        <Kpi
          icon={TrendingUp}
          label="Pedidos gerados (até 48h depois)"
          value={`${totals.converted.toLocaleString('pt-AO')}${totals.sent ? ` · ${((totals.converted / totals.sent) * 100).toFixed(1)}%` : ''}`}
        />
        <Kpi icon={MessageSquareText} label="Custo de SMS (30 dias)" value={kz(totals.smsCost)} />
      </div>

      <div className="mt-3 flex items-start gap-2 rounded-lg bg-light p-3 text-xs text-gray">
        <Moon size={14} className="mt-0.5 shrink-0" />
        Regras sempre ativas: nada sai entre as 21:00 e as 08:00, no máximo 2 promoções por semana por pessoa, e cada
        pessoa só recebe o que aceitou nas suas preferências.
      </div>

      <div className="mt-5 space-y-3">
        {campaigns.map((c) => <CampaignCard key={c.key} campaign={c} />)}
      </div>
    </div>
  )
}

function Kpi({ icon: Icon, label, value }: { icon: typeof Send; label: string; value: string }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <p className="flex items-center gap-1.5 text-xs text-gray"><Icon size={14} /> {label}</p>
      <p className="mt-1 text-xl font-bold text-dark">{value}</p>
    </div>
  )
}

function CampaignCard({ campaign: c }: { campaign: Campaign }) {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [feedback, setFeedback] = useState<{ ok: boolean; text: string } | null>(null)

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['admin-campaigns'] })
  const show = (ok: boolean, text: string) => {
    setFeedback({ ok, text })
    window.setTimeout(() => setFeedback(null), 5000)
  }

  const update = useMutation({
    mutationFn: (changes: Parameters<typeof notificationCenterService.updateCampaign>[1]) =>
      notificationCenterService.updateCampaign(c.key, changes),
    onSuccess: () => { void refresh(); setEditing(false) },
    onError: (e) => show(false, getApiErrorMessage(e, 'Não foi possível guardar.')),
  })
  const test = useMutation({
    mutationFn: () => notificationCenterService.testCampaign(c.key),
    onSuccess: (msg) => show(true, msg),
    onError: (e) => show(false, getApiErrorMessage(e, 'Não foi possível enviar o teste.')),
  })
  const run = useMutation({
    mutationFn: () => notificationCenterService.runCampaign(c.key),
    onSuccess: (msg) => { show(true, msg); void refresh() },
    onError: (e) => show(false, getApiErrorMessage(e, 'Não foi possível correr a campanha.')),
  })

  const bestVariant = c.stats.variants.length > 1
    ? [...c.stats.variants].sort((a, b) => (b.sent ? b.converted / b.sent : 0) - (a.sent ? a.converted / a.sent : 0))[0]
    : null

  return (
    <div className={`rounded-xl border bg-white p-4 ${c.enabled ? 'border-gray/15' : 'border-gray/10 opacity-75'}`}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <p className="font-semibold text-dark">{c.name}</p>
            <span className="flex items-center gap-1 rounded-full bg-light px-2 py-0.5 text-[11px] font-medium text-gray">
              {c.trigger === 'event' ? <Zap size={11} /> : <CalendarClock size={11} />} {c.schedule}
            </span>
            {c.marketing && <span className="rounded-full bg-warning/15 px-2 py-0.5 text-[11px] font-medium text-warning">Promoção</span>}
          </div>
          <p className="mt-1 flex items-center gap-1 text-xs text-gray"><Users size={12} /> {c.audience}</p>
          <p className="mt-1.5 text-sm text-gray">{c.description}</p>
        </div>
        <Toggle label="Ativa" checked={c.enabled} disabled={update.isPending} onChange={(v) => update.mutate({ enabled: v })} />
      </div>

      <div className="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
        <Stat label="Enviadas (30d)" value={c.stats.sent_30d.toLocaleString('pt-AO')} />
        <Stat label="Conversão" value={c.stats.conversion_rate != null ? `${c.stats.conversion_rate}%` : '—'} />
        <Stat label="SMS (30d)" value={c.stats.sms_sent_30d.toLocaleString('pt-AO')} />
        <Stat label="Custo SMS" value={kz(c.stats.sms_cost_30d_kz)} />
      </div>
      {bestVariant && bestVariant.sent > 0 && (
        <p className="mt-2 text-xs text-gray">
          Teste A/B: a variante <strong className="text-dark">{bestVariant.variant + 1}</strong> é a que gera mais pedidos até agora.
        </p>
      )}

      <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-gray/10 pt-3">
        {c.sms_allowed && (
          <Toggle
            label="Também por SMS"
            checked={c.sms_enabled}
            disabled={update.isPending}
            onChange={(v) => update.mutate({ sms_enabled: v })}
          />
        )}
        <div className="ml-auto flex flex-wrap gap-2">
          <SmallButton icon={Pencil} onClick={() => setEditing((v) => !v)}>{editing ? 'Fechar' : 'Editar textos'}</SmallButton>
          <SmallButton icon={FlaskConical} busy={test.isPending} onClick={() => test.mutate()}>Testar em mim</SmallButton>
          {c.can_run_now && (
            <SmallButton
              icon={Play}
              busy={run.isPending}
              onClick={() => {
                if (window.confirm(`Correr "${c.name}" agora? As regras (preferências, horas de silêncio, limite de promoções e "nunca repetir") continuam a aplicar-se.`)) {
                  run.mutate()
                }
              }}
            >
              Correr agora
            </SmallButton>
          )}
        </div>
      </div>

      {feedback && (
        <p className={`mt-2 flex items-center gap-1.5 text-sm ${feedback.ok ? 'text-success' : 'text-danger'}`}>
          {feedback.ok ? <CheckCircle2 size={15} /> : <AlertTriangle size={15} />} {feedback.text}
        </p>
      )}

      {editing && (
        <TemplateEditor
          campaign={c}
          saving={update.isPending}
          onSave={(templates) => update.mutate({ templates })}
          onReset={() => update.mutate({ templates: null as unknown as CampaignTemplate[] })}
        />
      )}
    </div>
  )
}

function TemplateEditor({ campaign, saving, onSave, onReset }: {
  campaign: Campaign
  saving: boolean
  onSave: (templates: CampaignTemplate[]) => void
  onReset: () => void
}) {
  const [templates, setTemplates] = useState<CampaignTemplate[]>(() => campaign.templates.map((t) => ({ ...t })))
  const withOccasion = campaign.key === 'special_dates'

  const set = (i: number, field: keyof CampaignTemplate, value: string) =>
    setTemplates((ts) => ts.map((t, j) => (j === i ? { ...t, [field]: value } : t)))

  return (
    <div className="mt-4 space-y-4 rounded-lg bg-light p-4">
      <p className="text-xs text-gray">
        Variáveis disponíveis: {campaign.variables.map((v) => <code key={v} className="mr-1 rounded bg-white px-1 py-0.5 text-dark">{`{${v}}`}</code>)}
        {templates.length > 1 && ' · Cada pessoa recebe sempre a mesma variante (teste A/B).'}
      </p>

      {templates.map((t, i) => {
        const sms = t.sms ? smsInfo(t.sms) : null
        return (
          <div key={i} className="space-y-2 rounded-lg border border-gray/15 bg-white p-3">
            <div className="flex items-center justify-between">
              <p className="text-xs font-semibold uppercase tracking-wide text-gray">Variante {i + 1}</p>
              {templates.length > 1 && (
                <button onClick={() => setTemplates((ts) => ts.filter((_, j) => j !== i))} aria-label={`Remover variante ${i + 1}`} className="text-danger">
                  <Trash2 size={15} />
                </button>
              )}
            </div>
            {withOccasion && (
              <Field label="Data (DD-MM)" value={t.occasion ?? ''} onChange={(v) => set(i, 'occasion', v)} placeholder="11-11" />
            )}
            <Field label="Título" value={t.title} onChange={(v) => set(i, 'title', v)} max={120} />
            <Field label="Mensagem" value={t.body} onChange={(v) => set(i, 'body', v)} max={500} multiline />
            {campaign.sms_allowed && (
              <div>
                <Field label="SMS (opcional, sem links)" value={t.sms ?? ''} onChange={(v) => set(i, 'sms', v)} max={160} multiline />
                {sms && sms.length > 0 && <SmsMeter info={sms} />}
              </div>
            )}
          </div>
        )
      })}

      <div className="flex flex-wrap gap-2">
        {templates.length < 5 && (
          <SmallButton icon={Plus} onClick={() => setTemplates((ts) => [...ts, { title: '', body: '', sms: '' }])}>Nova variante</SmallButton>
        )}
        <button onClick={onReset} disabled={saving} className="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray hover:text-dark">
          Repor textos originais
        </button>
        <button
          onClick={() => onSave(templates.map((t) => ({ ...t, sms: t.sms?.trim() ? t.sms : null, occasion: t.occasion || null })))}
          disabled={saving}
          className="ml-auto rounded-lg bg-primary px-4 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
        >
          {saving ? 'A guardar...' : 'Guardar textos'}
        </button>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Envio manual                                                        */
/* ------------------------------------------------------------------ */

function SendTab({ onSent, lockedProvinceId = null }: { onSent: () => void; lockedProvinceId?: number | null }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<BroadcastPayload>({
    audience: 'users', kind: 'announcement', title: '', body: '', path: '/', send_sms: false, sms_text: '',
    province_id: lockedProvinceId, municipality_id: null, scheduled_at: null,
  })
  const [preview, setPreview] = useState<BroadcastPreview | null>(null)
  const [error, setError] = useState<string | null>(null)

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', form.province_id],
    queryFn: () => territoryService.listMunicipalities(Number(form.province_id)),
    enabled: !!form.province_id,
  })

  const patch = (changes: Partial<BroadcastPayload>) => {
    setForm((f) => ({ ...f, ...changes }))
    setPreview(null)
  }

  const previewMutation = useMutation({
    mutationFn: () => notificationCenterService.preview(form),
    onSuccess: (p) => { setPreview(p); setError(null) },
    onError: (e) => setError(getApiErrorMessage(e, 'Não foi possível calcular o alcance.')),
  })
  const sendMutation = useMutation({
    mutationFn: () => notificationCenterService.send({
      ...form,
      scheduled_at: form.scheduled_at ? new Date(form.scheduled_at).toISOString() : null,
    }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['admin-broadcasts'] })
      onSent()
    },
    onError: (e) => setError(getApiErrorMessage(e, 'Não foi possível enviar.')),
  })

  const sms = form.send_sms && form.sms_text ? smsInfo(form.sms_text) : null
  const canSend = form.title.trim() && form.body.trim() && (!form.send_sms || (sms && !sms.hasLink && sms.segments === 1))

  const confirmAndSend = () => {
    if (!preview) return
    const when = form.scheduled_at ? `agendado para ${new Date(form.scheduled_at).toLocaleString('pt-PT')}` : 'agora'
    const smsLine = form.send_sms ? `\n${preview.sms_recipients} SMS · custo estimado ${kz(preview.sms_cost_estimate_kz)}` : ''
    if (window.confirm(`Enviar ${when} a ${preview.recipients} pessoa(s)?${smsLine}`)) {
      sendMutation.mutate()
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
      <div className="space-y-4 rounded-xl border border-gray/15 bg-white p-5">
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Para quem" value={form.audience} onChange={(v) => patch({ audience: v as BroadcastAudience })}
            options={AUDIENCES.map((a) => ({ value: a.value, label: a.label }))} />
          <Select label="Tipo" value={form.kind} onChange={(v) => patch({ kind: v as 'announcement' | 'promo' })}
            options={[
              { value: 'announcement', label: 'Aviso (chega a todos)' },
              { value: 'promo', label: 'Promoção (respeita as preferências)' },
            ]} />
          <Select label={lockedProvinceId ? 'Província' : 'Província (opcional)'} value={String(form.province_id ?? '')} disabled={!!lockedProvinceId}
            onChange={(v) => patch({ province_id: v ? Number(v) : null, municipality_id: null })}
            options={[{ value: '', label: 'Todas' }, ...(provincesQuery.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]} />
          <Select label="Município (opcional)" value={String(form.municipality_id ?? '')} disabled={!form.province_id}
            onChange={(v) => patch({ municipality_id: v ? Number(v) : null })}
            options={[{ value: '', label: 'Todos' }, ...(municipalitiesQuery.data ?? []).map((m) => ({ value: String(m.id), label: m.name }))]} />
        </div>

        <Field label="Título" value={form.title} onChange={(v) => patch({ title: v })} max={120} placeholder="Ex: Olá, {nome}! Temos novidades 🎉" />
        <Field label="Mensagem" value={form.body} onChange={(v) => patch({ body: v })} max={500} multiline
          placeholder="Use {nome} para o primeiro nome de cada pessoa." />
        <Select label="Ao tocar, abre" value={form.path ?? '/'} onChange={(v) => patch({ path: v })}
          options={DESTINATIONS.map((d) => ({ value: d.value, label: d.label }))} />

        <div className="rounded-lg border border-gray/15 p-3">
          <Toggle label="Enviar também por SMS" checked={form.send_sms} onChange={(v) => patch({ send_sms: v })} />
          {form.send_sms && (
            <div className="mt-3">
              <Field label="Texto do SMS (sem links, até 160 carateres)" value={form.sms_text ?? ''} max={160} multiline
                onChange={(v) => patch({ sms_text: v })} placeholder="KeruGas: ..." />
              {sms && sms.length > 0 && <SmsMeter info={sms} />}
            </div>
          )}
        </div>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Agendar (opcional)</span>
          <input
            type="datetime-local"
            value={form.scheduled_at ?? ''}
            onChange={(e) => patch({ scheduled_at: e.target.value || null })}
            className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
          />
          <span className="mt-1 block text-xs text-gray">Vazio = enviar já.</span>
        </label>

        {preview && (
          <div className="rounded-lg bg-primary/5 p-4 text-sm">
            <p className="font-semibold text-dark">{preview.recipients.toLocaleString('pt-AO')} pessoa(s) vão receber</p>
            {form.send_sms && (
              <p className="mt-1 text-gray">
                {preview.sms_recipients.toLocaleString('pt-AO')} por SMS · custo estimado <strong className="text-dark">{kz(preview.sms_cost_estimate_kz)}</strong>
              </p>
            )}
            {preview.quiet_hours_now && !form.scheduled_at && (
              <p className="mt-2 flex items-start gap-1.5 text-warning">
                <Moon size={14} className="mt-0.5 shrink-0" /> São horas de silêncio (21:00–08:00). Considere agendar para amanhã de manhã.
              </p>
            )}
          </div>
        )}

        {error && <p className="flex items-center gap-1.5 text-sm text-danger"><AlertTriangle size={15} /> {error}</p>}

        <div className="flex flex-wrap gap-2">
          <button
            onClick={() => previewMutation.mutate()}
            disabled={previewMutation.isPending}
            className="flex items-center gap-2 rounded-lg border border-gray/20 px-4 py-2.5 text-sm font-semibold text-dark disabled:opacity-60"
          >
            {previewMutation.isPending ? <Loader2 size={16} className="animate-spin" /> : <Users size={16} />} Calcular alcance e custo
          </button>
          <button
            onClick={confirmAndSend}
            disabled={!preview || !canSend || sendMutation.isPending}
            title={!preview ? 'Calcule primeiro o alcance' : undefined}
            className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
          >
            {sendMutation.isPending ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
            {form.scheduled_at ? 'Agendar envio' : 'Enviar'}
          </button>
        </div>
      </div>

      <PhonePreview title={form.title} body={form.body} />
    </div>
  )
}

/** Como a notificação vai aparecer num telemóvel, com {nome} de exemplo. */
function PhonePreview({ title, body }: { title: string; body: string }) {
  const sample = (text: string) => text.replace(/\{nome\}/g, 'Maria').replace(/\{central\}/g, '+244 923 000 000')

  return (
    <div className="lg:sticky lg:top-20 lg:self-start">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray">Pré-visualização</p>
      <div className="rounded-[2rem] border-4 border-dark bg-gradient-to-b from-[#11264B] to-[#0f172a] p-3 pt-6 shadow-xl">
        <p className="mb-4 text-center text-3xl font-light text-white">12:30</p>
        <div className="rounded-2xl bg-white/95 p-3 shadow">
          <div className="flex items-center gap-2 text-[11px] text-gray">
            <img src="/branding/favicon-32.png" alt="" className="h-4 w-4" /> KeruGás · agora
          </div>
          <p className="mt-1 text-sm font-semibold text-dark">{sample(title) || 'Título da notificação'}</p>
          <p className="mt-0.5 line-clamp-4 text-sm text-dark/80">{sample(body) || 'A mensagem aparece aqui.'}</p>
        </div>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Histórico                                                           */
/* ------------------------------------------------------------------ */

function HistoryTab() {
  const queryClient = useQueryClient()
  const broadcastsQuery = useQuery({
    queryKey: ['admin-broadcasts'],
    queryFn: () => notificationCenterService.broadcasts(),
    // Envios em curso atualizam sozinhos.
    refetchInterval: (q) => (q.state.data?.data.some((b) => b.status === 'SENDING') ? 5000 : false),
  })
  const cancel = useMutation({
    mutationFn: (id: number) => notificationCenterService.cancel(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-broadcasts'] }),
  })

  const items = broadcastsQuery.data?.data ?? []

  if (!broadcastsQuery.isLoading && items.length === 0) {
    return <p className="py-16 text-center text-sm text-gray">Ainda não houve envios manuais.</p>
  }

  return (
    <ul className="space-y-3">
      {items.map((b) => (
        <li key={b.id} className="rounded-xl border border-gray/15 bg-white p-4">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="font-semibold text-dark">{b.title}</p>
              <p className="mt-0.5 line-clamp-2 text-sm text-gray">{b.body}</p>
            </div>
            <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${STATUS_LABEL[b.status]?.tone}`}>
              {STATUS_LABEL[b.status]?.label ?? b.status}
            </span>
          </div>
          <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray">
            <span>{b.audience_label}{b.province ? ` · ${b.province}` : ''}{b.municipality ? ` · ${b.municipality}` : ''}</span>
            <span>{b.kind === 'promo' ? 'Promoção' : 'Aviso'}</span>
            <span>{b.status === 'SCHEDULED' ? 'Agendado para ' : ''}{new Date(b.scheduled_at ?? b.created_at).toLocaleString('pt-PT')}</span>
            {b.status === 'SENT' && <span>{b.recipients_count.toLocaleString('pt-AO')} pessoas{b.send_sms ? ` · ${b.sms_count} SMS` : ''}</span>}
            {b.created_by && <span>por {b.created_by}</span>}
          </div>
          {b.status === 'SCHEDULED' && (
            <button
              onClick={() => window.confirm('Cancelar este envio agendado?') && cancel.mutate(b.id)}
              className="mt-3 flex items-center gap-1 text-xs font-semibold text-danger"
            >
              <X size={14} /> Cancelar envio
            </button>
          )}
        </li>
      ))}
    </ul>
  )
}

/* ------------------------------------------------------------------ */
/* Pequenos componentes                                                */
/* ------------------------------------------------------------------ */

function SmsMeter({ info }: { info: ReturnType<typeof smsInfo> }) {
  const bad = info.hasLink || info.segments > 1
  return (
    <p className={`mt-1 text-xs ${bad ? 'text-danger' : 'text-gray'}`}>
      {info.hasLink
        ? 'Tem um link ou endereço,a operadora recusa SMS com links.'
        : `${info.length}/160 carateres · ${info.segments} SMS${info.segments > 1 ? ',encurte para caber num só (custo a dobrar)' : ''}`}
      {!info.hasLink && info.text !== '' && ' · acentos convertidos automaticamente para caber num só SMS'}
    </p>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-light px-3 py-2">
      <p className="text-gray">{label}</p>
      <p className="font-semibold text-dark">{value}</p>
    </div>
  )
}

function Toggle({ label, checked, disabled, onChange }: { label: string; checked: boolean; disabled?: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className="flex items-center gap-2 text-sm font-medium text-dark disabled:opacity-60"
    >
      <span aria-hidden="true" className={`relative h-5 w-9 rounded-full transition-colors ${checked ? 'bg-primary' : 'bg-gray/30'}`}>
        <span className={`absolute left-0 top-0.5 h-4 w-4 rounded-full bg-[#ffffff] shadow transition-transform ${checked ? 'translate-x-4' : 'translate-x-0.5'}`} />
      </span>
      {label}
    </button>
  )
}

function SmallButton({ icon: Icon, busy, onClick, children }: { icon: typeof Send; busy?: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      disabled={busy}
      className="flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-1.5 text-xs font-semibold text-dark hover:bg-light disabled:opacity-60"
    >
      {busy ? <Loader2 size={13} className="animate-spin" /> : <Icon size={13} />} {children}
    </button>
  )
}

function Field({ label, value, onChange, max, multiline, placeholder }: {
  label: string
  value: string
  onChange: (v: string) => void
  max?: number
  multiline?: boolean
  placeholder?: string
}) {
  const common = {
    value,
    placeholder,
    maxLength: max,
    onChange: (e: { target: { value: string } }) => onChange(e.target.value),
    className: 'w-full rounded-lg border border-gray/20 px-3 py-2 text-sm',
  }
  return (
    <label className="block text-sm">
      <span className="mb-1 flex justify-between font-medium text-dark">
        {label}
        {max && <span className="text-xs font-normal text-gray">{value.length}/{max}</span>}
      </span>
      {multiline ? <textarea rows={3} {...common} /> : <input {...common} />}
    </label>
  )
}

function Select({ label, value, onChange, options, disabled }: {
  label: string
  value: string
  onChange: (v: string) => void
  options: Array<{ value: string; label: string }>
  disabled?: boolean
}) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-dark">{label}</span>
      <select value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm disabled:opacity-60">
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </label>
  )
}
