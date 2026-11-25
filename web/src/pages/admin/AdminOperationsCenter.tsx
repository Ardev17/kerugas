import { Fragment, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Send, Phone, MapPin, CheckCircle2, XCircle, Loader2, AlertTriangle,
  History, FileText, Plus, Pencil, Power, ChevronRight, Wallet, ShieldCheck, ChevronDown, ChevronUp, Lock,
} from 'lucide-react'
import { operationsService } from '@/services/operationsService'
import { territoryService } from '@/services/territoryService'
import { getApiErrorMessage } from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { CONSULT_TEMPLATE_TYPES } from '@/types'
import type { Operation, OperationStationOption, SmsDispatch, SmsPreview, SmsSubscriptionStatus, SmsTemplate, SmsTemplateType } from '@/types'
import { queueLevelLabel as QUEUE_LABEL } from '@/constants/theme'
import { LastUpdated } from '@/components/LastUpdated'
import { smsCount } from '@/lib/sms'
import { hasSuperPowers, isGlobalSuperAdmin, isProvinceScoped } from '@/lib/roles'
const TEMPLATE_TYPE_LABEL: Record<SmsTemplateType, string> = {
  GENERAL: 'Geral', AVAILABILITY: 'Disponibilidade', NO_GAS: 'Sem gás',
  DELIVERY_REQUEST: 'Pedido de entrega', STATION_INFO: 'Info de posto', NOT_REGISTERED: 'Não registado',
  SUBSCRIPTION_REQUIRED: 'Subscrição necessária', SUBSCRIPTION_EXPIRED: 'Subscrição terminada',
  SUBSCRIPTION_ACTIVATED: 'Subscrição ativada', OPERATOR_ANALYTICS: 'Aviso ao operador',
  FREE_CONSULTS_LOW: 'Consultas a acabar', FREE_CONSULTS_EXHAUSTED: 'Consultas esgotadas',
  FREE_CONSULTS_RENEWED: 'Consultas renovadas', MUNICIPALITY_PAID: 'Município pago',
  ACCESS_GRANTED: 'Acesso concedido', ACCESS_REVOKED: 'Acesso retirado',
  STATION_OFF_MAP: 'Posto a sair do mapa', ORDER_TAKEN_BY_OTHER: 'Pedido de outro posto',
}

type Tab = 'responder' | 'templates' | 'historico'

/**
 * Central de Operações KeruGás.
 *
 * REGRA FUNDAMENTAL: este módulo existe porque o KeruGás NÃO recebe
 * automaticamente os SMS que os clientes enviam para o número oficial
 * — essas mensagens chegam ao telefone/chip físico da Central. Um
 * admin lê o SMS ali e inicia manualmente o atendimento aqui. Nada
 * nesta página é acionado por uma mensagem recebida pelo sistema.
 *
 * Acesso restrito a ADMIN/SUPER_ADMIN (ver AdminLayout e o backend
 * `permission:operations.manage` / `permission:sms.send`).
 */
export default function AdminOperationsCenter() {
  const [tab, setTab] = useState<Tab>('responder')

  return (
    <div>
      <div className="flex flex-col gap-1">
        <h1 className="text-xl font-bold text-dark">Central de Operações</h1>
        <p className="text-sm text-gray">
          Responda a clientes que contactaram o número oficial da Central através do telefone físico.
        </p>
      </div>

      <div className="mt-4 flex gap-1 overflow-x-auto rounded-lg border border-gray/15 bg-white p-1">
        <TabButton active={tab === 'responder'} onClick={() => setTab('responder')} icon={Send} label="Responder Cliente" />
        <TabButton active={tab === 'templates'} onClick={() => setTab('templates')} icon={FileText} label="Templates SMS" />
        <TabButton active={tab === 'historico'} onClick={() => setTab('historico')} icon={History} label="Histórico" />
      </div>

      <div className="mt-6">
        {tab === 'responder' && <RespondClientPanel />}
        {tab === 'templates' && <TemplatesPanel />}
        {tab === 'historico' && <HistoryPanel />}
      </div>
    </div>
  )
}

function TabButton({
  active, onClick, icon: Icon, label,
}: { active: boolean; onClick: () => void; icon: typeof Send; label: string }) {
  return (
    <button
      onClick={onClick}
      className={`flex shrink-0 items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold transition-colors ${
        active ? 'bg-primary text-white' : 'text-gray hover:bg-light'
      }`}
    >
      <Icon size={16} /> {label}
    </button>
  )
}

/* ------------------------------------------------------------------ */
/* Responder Cliente                                                   */
/* ------------------------------------------------------------------ */

function RespondClientPanel() {
  const { user } = useAuth()
  const [phone, setPhone] = useState('')
  const [originalMessage, setOriginalMessage] = useState('')
  // Outubro 2026: um Admin atende só na sua província — vem preenchida e
  // bloqueada, e só se listam os municípios dela. O Super Admin escolhe.
  // Super Admin Provincial (Outubro 2026): igual ao Admin — a sua província.
  const lockedProvinceId = isProvinceScoped(user) ? user!.province_id : null
  const isScopedAdmin = isProvinceScoped(user)
  const [provinceId, setProvinceId] = useState<number | ''>(lockedProvinceId ?? '')
  const [municipalityId, setMunicipalityId] = useState<number | ''>('')
  // O utilizador pode chegar depois da primeira renderização (sessão a revalidar).
  useEffect(() => {
    if (lockedProvinceId) setProvinceId(lockedProvinceId)
  }, [lockedProvinceId])
  const [operation, setOperation] = useState<Operation | null>(null)
  const [selectedTemplateId, setSelectedTemplateId] = useState<number | ''>('')
  // Template "Informação de posto" (Novembro 2026): um posto por mensagem.
  const [stationId, setStationId] = useState<number | null>(null)
  const [preview, setPreview] = useState<SmsPreview | null>(null)
  const [sendResult, setSendResult] = useState<'success' | 'error' | null>(null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  const provincesQuery = useQuery({ queryKey: ['territory-provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['territory-municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(Number(provinceId)),
    enabled: provinceId !== '',
  })

  const templatesQuery = useQuery({ queryKey: ['sms-templates'], queryFn: operationsService.listTemplates })
  const activeTemplates = (templatesQuery.data?.data ?? []).filter((t) => t.active)

  const stationsQuery = useQuery({
    queryKey: ['operation-stations', operation?.id],
    queryFn: () => operationsService.stationsFor(operation!.id),
    enabled: !!operation,
  })

  const startMutation = useMutation({
    mutationFn: () => operationsService.startOperation({
      phone,
      province_id: provinceId === '' ? null : Number(provinceId),
      municipality_id: municipalityId === '' ? null : Number(municipalityId),
      original_message: originalMessage || null,
    }),
    onSuccess: (op) => {
      setOperation(op)
      setStationId(null)
      setSelectedTemplateId('')
      setPreview(null)
      setSendResult(null)
    },
  })

  const previewMutation = useMutation({
    mutationFn: () => operationsService.preview(operation!.id, Number(selectedTemplateId), needsStation ? stationId : null),
    onSuccess: (data) => { setPreview(data); setErrorMessage(null) },
    onError: (err) => setErrorMessage(getApiErrorMessage(err, 'Não foi possível gerar a pré-visualização.')),
  })

  const sendMutation = useMutation({
    mutationFn: () => operationsService.send(operation!.id, Number(selectedTemplateId), needsStation ? stationId : null),
    onSuccess: () => { setSendResult('success'); setErrorMessage(null) },
    onError: (err) => { setSendResult('error'); setErrorMessage(getApiErrorMessage(err, 'Não foi possível enviar o SMS.')) },
  })

  // Subscrição (Outubro 2026): consultas grátis gastas por este número no mês.
  const subscriptionQuery = useQuery({
    queryKey: ['operation-subscription', operation?.id],
    queryFn: () => operationsService.subscriptionStatus(operation!.id),
    enabled: !!operation,
    retry: false,
  })
  const subscription = subscriptionQuery.data
  const selectedTemplate = activeTemplates.find((t) => t.id === selectedTemplateId)
  const needsStation = selectedTemplate?.type === 'STATION_INFO'
  const pickStation = (id: number) => { setStationId(id); setPreview(null); setErrorMessage(null) }
  const consultBlocked = !!subscription?.blocked && !!selectedTemplate && CONSULT_TEMPLATE_TYPES.includes(selectedTemplate.type)

  const gasStations = useMemo(
    () => (stationsQuery.data ?? []).filter((s) => s.gas_available),
    [stationsQuery.data]
  )

  function resetAll() {
    setOperation(null)
    setPhone('')
    setOriginalMessage('')
    setProvinceId(lockedProvinceId ?? '')
    setMunicipalityId('')
    setSelectedTemplateId('')
    setStationId(null)
    setPreview(null)
    setSendResult(null)
    setErrorMessage(null)
  }

  // ETAPA 1 — dados do atendimento
  if (!operation) {
    return (
      <div className="max-w-xl rounded-xl border border-gray/15 bg-white p-5">
        <h2 className="text-sm font-bold text-dark">1. Dados do atendimento</h2>
        <p className="mt-1 text-xs text-gray">
          Introduza o número que apareceu no telefone físico da Central e, se souber, o município do cliente.
        </p>

        <div className="mt-4 space-y-3">
          <div>
            <label className="text-xs font-semibold text-dark">Telefone do cliente *</label>
            <div className="mt-1 flex items-center gap-2 rounded-lg border border-gray/20 px-3 py-2">
              <Phone size={16} className="text-gray" />
              <input
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="923123456 ou +244923123456"
                className="w-full text-sm outline-none"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs font-semibold text-dark">Província</label>
              <select
                value={provinceId}
                onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : ''); setMunicipalityId('') }}
                disabled={isScopedAdmin}
                className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm disabled:bg-light disabled:text-dark"
              >
                <option value="">Selecione...</option>
                {(provincesQuery.data ?? [])
                  .filter((p) => !isScopedAdmin || p.id === lockedProvinceId)
                  .map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-semibold text-dark">Município</label>
              <select
                value={municipalityId}
                onChange={(e) => setMunicipalityId(e.target.value ? Number(e.target.value) : '')}
                disabled={provinceId === ''}
                className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm disabled:bg-light"
              >
                <option value="">Selecione...</option>
                {(municipalitiesQuery.data ?? []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </div>
          </div>

          <div>
            <label className="text-xs font-semibold text-dark">Mensagem recebida pelo cliente (opcional)</label>
            <textarea
              value={originalMessage}
              onChange={(e) => setOriginalMessage(e.target.value)}
              placeholder='Ex: "Boa tarde, tem gás no Município X, ou no posto Z?"'
              rows={2}
              className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
            />
            <p className="mt-1 text-[11px] text-gray">
              Esta mensagem foi introduzida manualmente por {user?.name ?? 'si'} e não foi recebida automaticamente pelo KeruGás.
            </p>
          </div>

          {isScopedAdmin && (
            <p className="text-[11px] text-gray">
              {lockedProvinceId
                ? 'Atende apenas clientes da província da sua conta de Admin.'
                : 'A sua conta de Admin ainda não tem província atribuída — contacte um Super Admin.'}
            </p>
          )}

          <button
            onClick={() => startMutation.mutate()}
            disabled={!phone || startMutation.isPending || (isScopedAdmin && !lockedProvinceId)}
            className="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
          >
            {startMutation.isPending ? <Loader2 size={16} className="animate-spin" /> : <ChevronRight size={16} />}
            Continuar
          </button>
          {startMutation.isError && (
            <p className="text-xs text-red-600">{getApiErrorMessage(startMutation.error, 'Não foi possível iniciar o atendimento.')}</p>
          )}
        </div>
      </div>
    )
  }

  // ETAPA 2 — resultado do envio
  if (sendResult) {
    return (
      <div className="max-w-xl rounded-xl border border-gray/15 bg-white p-6 text-center">
        {sendResult === 'success' ? (
          <>
            <CheckCircle2 className="mx-auto text-green-600" size={40} />
            <h2 className="mt-3 text-sm font-bold text-dark">SMS enviado com sucesso</h2>
            <p className="mt-1 text-xs text-gray">O gateway de SMS aceitou o envio para {operation.phone}.</p>
          </>
        ) : (
          <>
            <XCircle className="mx-auto text-red-600" size={40} />
            <h2 className="mt-3 text-sm font-bold text-dark">Não foi possível enviar o SMS</h2>
            <p className="mt-1 text-xs text-gray">{errorMessage}</p>
          </>
        )}
        <button onClick={resetAll} className="mt-4 rounded-lg border border-gray/20 px-4 py-2 text-sm font-semibold text-dark">
          Novo atendimento
        </button>
      </div>
    )
  }

  // ETAPA 3 — postos, template e pré-visualização
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="rounded-xl border border-gray/15 bg-white p-5">
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-bold text-dark">2. Postos no município</h2>
          <button onClick={resetAll} className="text-xs font-medium text-gray hover:text-primary">Cancelar atendimento</button>
        </div>
        <p className="mt-1 flex items-center gap-1 text-xs text-gray">
          <MapPin size={12} /> {operation.phone} — {operation.municipality_id ? '' : 'sem município selecionado'}
        </p>

        {!operation.municipality_id && (
          <p className="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">
            Nenhum município foi selecionado. Use o template "Cliente não registado" ou cancele e recomece com o município correto.
          </p>
        )}

        {stationsQuery.isLoading && operation.municipality_id && <p className="mt-3 text-xs text-gray">A consultar postos...</p>}

        {stationsQuery.data && stationsQuery.data.length === 0 && (
          <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">Nenhum posto ativo encontrado para este município.</p>
        )}

        {stationsQuery.data && stationsQuery.data.length > 0 && gasStations.length === 0 && (
          <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
            Existem postos cadastrados neste município, mas nenhum está atualmente marcado como disponível.
          </p>
        )}

        <ul className="mt-3 space-y-2">
          {(stationsQuery.data ?? []).map((station: OperationStationOption) => (
            <li
              key={station.id}
              className={`rounded-lg border p-3 text-xs ${needsStation ? 'cursor-pointer' : ''} ${needsStation && stationId === station.id ? 'border-primary bg-primary/5' : 'border-gray/10'}`}
              onClick={needsStation ? () => pickStation(station.id) : undefined}
            >
              <div className="flex items-center justify-between">
                <span className="font-semibold text-dark">
                  {station.name}
                  {station.code && <span className="ml-1 font-normal text-gray">nº {station.code}</span>}
                </span>
                <span className={station.gas_available ? 'font-semibold text-green-700' : 'text-gray'}>
                  {station.gas_available ? 'Com gás' : 'Sem gás'}
                </span>
              </div>
              <div className="mt-1 flex items-center justify-between text-gray">
                <span>{station.queue_level_label ?? QUEUE_LABEL[station.queue_level ?? ''] ?? 'Fila desconhecida'}</span>
                <span><LastUpdated at={station.status_updated_at} /></span>
              </div>
            </li>
          ))}
        </ul>
      </div>

      <div className="rounded-xl border border-gray/15 bg-white p-5">
        <h2 className="text-sm font-bold text-dark">3. Template e envio</h2>

        {subscription && (
          <SubscriptionBanner
            status={subscription}
            onUseSuggested={subscription.suggested_template_id ? () => {
              setSelectedTemplateId(subscription.suggested_template_id!)
              setPreview(null)
              setErrorMessage(null)
            } : undefined}
          />
        )}

        <select
          value={selectedTemplateId}
          onChange={(e) => { setSelectedTemplateId(e.target.value ? Number(e.target.value) : ''); setPreview(null); setErrorMessage(null) }}
          className="mt-3 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
          aria-label="Template"
        >
          <option value="">Selecione um template...</option>
          {activeTemplates.map((t) => (
            <option key={t.id} value={t.id}>{t.name} ({TEMPLATE_TYPE_LABEL[t.type]})</option>
          ))}
        </select>

        {needsStation && (
          <div className="mt-3">
            <label htmlFor="sms-station" className="text-xs font-semibold text-dark">
              Posto {operation.municipality_id ? '' : '(escolha primeiro o município)'}
            </label>
            <select
              id="sms-station"
              value={stationId ?? ''}
              onChange={(e) => e.target.value ? pickStation(Number(e.target.value)) : setStationId(null)}
              disabled={!operation.municipality_id}
              className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm disabled:bg-light"
            >
              <option value="">Selecione o posto...</option>
              {(stationsQuery.data ?? []).map((s) => (
                <option key={s.id} value={s.id}>
                  {s.code ? `${s.code} · ` : ''}{s.name} — {s.gas_available ? 'com gás' : 'sem gás'}
                </option>
              ))}
            </select>
            <p className="mt-1 text-[11px] text-gray">Um posto por mensagem. Para informar outro posto, envie outra SMS.</p>
          </div>
        )}

        <button
          onClick={() => previewMutation.mutate()}
          disabled={!selectedTemplateId || previewMutation.isPending || consultBlocked || (needsStation && !stationId)}
          className="mt-3 flex w-full items-center justify-center gap-2 rounded-lg border border-gray/20 px-4 py-2 text-sm font-semibold text-dark disabled:opacity-50"
        >
          {previewMutation.isPending ? <Loader2 size={16} className="animate-spin" /> : null}
          Gerar pré-visualização
        </button>

        {errorMessage && !preview && (
          <p className="mt-2 flex items-center gap-1 text-xs text-red-600"><AlertTriangle size={12} /> {errorMessage}</p>
        )}

        {preview && (
          <div className="mt-4 space-y-3 rounded-lg border border-gray/15 bg-light p-4 text-xs">
            <Row label="Destinatário" value={preview.recipient} />
            <Row label="Província" value={preview.province ?? '-'} />
            <Row label="Município" value={preview.municipality ?? '-'} />
            <Row label="Template" value={preview.template.name} />
            <div>
              <p className="font-semibold text-dark">Mensagem</p>
              <pre className="mt-1 whitespace-pre-wrap rounded-lg bg-white p-3 font-sans text-xs text-dark">{preview.message}</pre>
            </div>
            <SmsCounter count={smsCount(preview.message)} />

            <p className="text-[11px] font-semibold text-dark">
              Você está prestes a enviar esta mensagem para {preview.recipient}.
            </p>

            <button
              onClick={() => sendMutation.mutate()}
              disabled={sendMutation.isPending || consultBlocked}
              className="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
            >
              {sendMutation.isPending ? <><Loader2 size={16} className="animate-spin" /> A enviar...</> : <><Send size={16} /> Confirmar e enviar SMS</>}
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

/**
 * Contador de caracteres e de SMS (Novembro 2026). Conta o texto já sem
 * acentos — é assim que sai (160 caracteres por SMS).
 */
function SmsCounter({ count, label = 'Mensagem' }: { count: ReturnType<typeof smsCount>; label?: string }) {
  const many = count.segments > 1
  return (
    <div className={`rounded-lg p-2 text-[11px] ${many ? 'bg-amber-50 text-amber-800' : 'bg-white text-gray'}`} role="status" aria-label={`Contador de ${label.toLowerCase()}`}>
      <div className="flex justify-between font-semibold">
        <span>{count.length} caracteres</span>
        <span>{count.segments} SMS</span>
      </div>
      <p className="mt-0.5">
        {count.encoding === 'UCS-2'
          ? `Tem símbolos especiais (${count.unicodeChars.slice(0, 5).join(' ')}): só ${count.singleLimit} caracteres por SMS${many ? `, ${count.perSegment} por parte` : ''}.`
          : `Acentos retirados no envio: ${count.singleLimit} caracteres por SMS${many ? `, ${count.perSegment} por parte` : ''}.`}
        {' '}
        {many
          ? `Vai como ${count.segments} SMS (cobrados ${count.segments} vezes).`
          : `Faltam ${count.remaining} para passar a 2 SMS.`}
      </p>
    </div>
  )
}

/**
 * Consultas grátis do mês deste telemóvel. Só alerta quando a
 * obrigatoriedade está ligada e o cliente chegou ao limite sem subscrição;
 * o servidor recusa (402) o envio da consulta nesse caso.
 */
function SubscriptionBanner({ status, onUseSuggested }: { status: SmsSubscriptionStatus; onUseSuggested?: () => void }) {
  if (status.subscribed) {
    return (
      <p className="mt-3 flex items-center gap-2 rounded-lg bg-success/10 p-3 text-xs text-success">
        <ShieldCheck size={14} /> Cliente subscrito até {new Date(status.valid_until!).toLocaleDateString('pt-PT')} — consultas sem limite.
      </p>
    )
  }

  if (status.blocked) {
    return (
      <div role="alert" className="mt-3 rounded-lg border border-danger/30 bg-danger/5 p-3 text-xs text-dark">
        <p className="flex items-center gap-2 font-semibold text-danger">
          <Lock size={14} /> Consultas grátis esgotadas ({status.used} de {status.limit} este mês)
        </p>
        <p className="mt-1 text-gray">
          Este cliente precisa de subscrição para receber mais consultas. Envie-lhe o template de subscrição.
        </p>
        {onUseSuggested && (
          <button type="button" onClick={onUseSuggested} className="mt-2 rounded-lg bg-primary px-3 py-1.5 font-semibold text-white">
            Usar template de subscrição
          </button>
        )}
      </div>
    )
  }

  if (status.enforced) {
    return (
      <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
        Consultas grátis este mês: <strong className="text-dark">{status.used} de {status.limit}</strong>
        {status.remaining === 1 ? ' — esta é a última grátis.' : ''}
      </p>
    )
  }

  return null
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <span className="text-gray">{label}</span>
      <span className="text-right font-medium text-dark">{value}</span>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Templates SMS                                                       */
/* ------------------------------------------------------------------ */

function TemplatesPanel() {
  const queryClient = useQueryClient()
  const templatesQuery = useQuery({ queryKey: ['sms-templates'], queryFn: operationsService.listTemplates })
  const [editing, setEditing] = useState<SmsTemplate | 'new' | null>(null)
  // Criar, editar e desativar templates é só do Super Admin; o Admin vê
  // os templates (e usa-os para responder), mas não os altera.
  const { user } = useAuth()
  const canManage = user?.role === 'SUPER_ADMIN'

  const deactivateMutation = useMutation({
    mutationFn: (id: number) => operationsService.deactivateTemplate(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sms-templates'] }),
  })

  return (
    <div>
      {canManage ? (
        <div className="flex justify-end">
          <button
            onClick={() => setEditing('new')}
            className="flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white"
          >
            <Plus size={16} /> Novo template
          </button>
        </div>
      ) : (
        <p className="text-xs text-gray">Só o Super Admin pode criar, editar ou desativar templates.</p>
      )}

      <div className="mt-4 grid gap-3 md:grid-cols-2">
        {(templatesQuery.data?.data ?? []).map((template) => (
          <div key={template.id} className="rounded-xl border border-gray/15 bg-white p-4">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-sm font-bold text-dark">{template.name}</p>
                <p className="text-xs text-gray">{TEMPLATE_TYPE_LABEL[template.type]} · {template.code}</p>
              </div>
              <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${template.active ? 'bg-green-100 text-green-700' : 'bg-gray/10 text-gray'}`}>
                {template.active ? 'Ativo' : 'Inativo'}
              </span>
            </div>
            <pre className="mt-2 max-h-24 overflow-hidden whitespace-pre-wrap rounded-lg bg-light p-2 font-sans text-xs text-gray">{template.content}</pre>
            {canManage && <div className="mt-3 flex gap-2">
              <button onClick={() => setEditing(template)} className="flex items-center gap-1 text-xs font-semibold text-primary">
                <Pencil size={13} /> Editar
              </button>
              {template.active && (
                <button
                  onClick={() => deactivateMutation.mutate(template.id)}
                  className="flex items-center gap-1 text-xs font-semibold text-gray hover:text-red-600"
                >
                  <Power size={13} /> Desativar
                </button>
              )}
            </div>}
          </div>
        ))}
      </div>

      {editing && (
        <TemplateFormModal
          template={editing === 'new' ? null : editing}
          allowedVariables={templatesQuery.data?.allowedVariables ?? []}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  )
}

function TemplateFormModal({
  template, allowedVariables, onClose,
}: { template: SmsTemplate | null; allowedVariables: string[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [name, setName] = useState(template?.name ?? '')
  const [code, setCode] = useState(template?.code ?? '')
  const [description, setDescription] = useState(template?.description ?? '')
  const [content, setContent] = useState(template?.content ?? '')
  const [type, setType] = useState<SmsTemplateType>(template?.type ?? 'GENERAL')
  const [active, setActive] = useState(template?.active ?? true)
  const [error, setError] = useState<string | null>(null)

  const saveMutation = useMutation({
    mutationFn: () => template
      ? operationsService.updateTemplate(template.id, { name, code, description, content, type, active })
      : operationsService.createTemplate({ name, code, description, content, type, active }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sms-templates'] })
      onClose()
    },
    onError: (err) => setError(getApiErrorMessage(err, 'Não foi possível guardar o template.')),
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-lg rounded-xl bg-white p-5">
        <h2 className="text-sm font-bold text-dark">{template ? 'Editar template' : 'Novo template'}</h2>

        <div className="mt-3 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs font-semibold text-dark">Nome</label>
              <input value={name} onChange={(e) => setName(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
            </div>
            <div>
              <label className="text-xs font-semibold text-dark">Código</label>
              <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
            </div>
          </div>

          <div>
            <label className="text-xs font-semibold text-dark">Tipo</label>
            <select value={type} onChange={(e) => setType(e.target.value as SmsTemplateType)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm">
              {Object.entries(TEMPLATE_TYPE_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>

          <div>
            <label className="text-xs font-semibold text-dark">Descrição</label>
            <input value={description ?? ''} onChange={(e) => setDescription(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          </div>

          <div>
            <label className="text-xs font-semibold text-dark">Conteúdo</label>
            <textarea value={content} onChange={(e) => setContent(e.target.value)} rows={6} aria-label="Conteúdo" className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 font-mono text-xs" />
            <div className="mt-1">
              <SmsCounter count={smsCount(content.replace(/\{\{\s*[a-z_]+\s*\}\}/g, ''))} label="Texto fixo" />
              <p className="mt-0.5 text-[11px] text-gray">Conta só o texto fixo — as variáveis ({'{{posto}}'}, {'{{municipio}}'}...) somam-se no envio. A contagem final aparece na pré-visualização.</p>
            </div>
            <p className="mt-1 text-[11px] text-gray">
              Variáveis permitidas: {allowedVariables.map((v) => `{{${v}}}`).join(', ')}
            </p>
          </div>

          <label className="flex items-center gap-2 text-xs font-semibold text-dark">
            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Ativo
          </label>

          {error && <p className="text-xs text-red-600">{error}</p>}

          <div className="flex justify-end gap-2 pt-2">
            <button onClick={onClose} className="rounded-lg border border-gray/20 px-4 py-2 text-sm font-semibold text-dark">Cancelar</button>
            <button
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending || !name || !code || !content}
              className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
              Guardar
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Histórico                                                            */
/* ------------------------------------------------------------------ */

const DISPATCH_STATUS_LABEL: Record<string, string> = {
  PENDING: 'Pendente', QUEUED: 'Enfileirado', SENT: 'Enviado',
  DELIVERED: 'Entregue', FAILED: 'Falhou', UNKNOWN: 'Desconhecido',
}

function HistoryPanel() {
  const { user } = useAuth()
  const isSuperAdmin = hasSuperPowers(user)
  const isGlobal = isGlobalSuperAdmin(user)
  const [status, setStatus] = useState('')
  const [recipient, setRecipient] = useState('')
  const [expandedId, setExpandedId] = useState<number | null>(null)

  const statsQuery = useQuery({ queryKey: ['sms-stats'], queryFn: () => operationsService.stats() })
  const historyQuery = useQuery({
    queryKey: ['sms-history', status, recipient],
    queryFn: () => operationsService.history({ status: status || undefined, recipient: recipient || undefined }),
  })

  const stats = statsQuery.data

  return (
    <div>
      {isGlobal && <SmsCreditsPanel />}
      {isSuperAdmin && <SmsUsageByAdminPanel />}

      {!isSuperAdmin && (
        <p className="mb-4 rounded-lg bg-light p-3 text-xs text-gray">
          A mostrar apenas os SMS que você enviou. Só o Super Admin vê o histórico de todos os administradores (auditoria e controlo de custo).
        </p>
      )}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="SMS enviados" value={stats?.total_sent ?? '-'} />
        <StatCard label="SMS entregues" value={stats?.total_delivered ?? '-'} />
        <StatCard label="SMS falhados" value={stats?.total_failed ?? '-'} />
        <StatCard label="Taxa de sucesso" value={stats ? `${stats.success_rate}%` : '-'} />
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        <input
          value={recipient}
          onChange={(e) => setRecipient(e.target.value)}
          placeholder="Pesquisar por telefone..."
          className="rounded-lg border border-gray/20 px-3 py-2 text-sm"
        />
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-lg border border-gray/20 px-3 py-2 text-sm">
          <option value="">Todos os estados</option>
          {Object.entries(DISPATCH_STATUS_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>

      <div className="mt-4 overflow-x-auto rounded-xl border border-gray/15 bg-white">
        <table className="w-full text-sm">
          <thead className="border-b border-gray/10 bg-light text-left text-xs uppercase text-gray">
            <tr>
              <th className="px-4 py-2" />
              <th className="px-4 py-2">Telefone</th>
              <th className="px-4 py-2">Template</th>
              <th className="px-4 py-2">Operador</th>
              <th className="px-4 py-2">Estado</th>
              <th className="px-4 py-2">Data</th>
            </tr>
          </thead>
          <tbody>
            {(historyQuery.data?.data ?? []).map((dispatch: SmsDispatch) => {
              const isExpanded = expandedId === dispatch.id
              return (
                <Fragment key={dispatch.id}>
                  <tr className="border-b border-gray/5 text-xs">
                    <td className="px-2 py-2">
                      <button
                        onClick={() => setExpandedId(isExpanded ? null : dispatch.id)}
                        aria-label={isExpanded ? 'Esconder mensagem' : 'Ver mensagem'}
                        className="text-gray hover:text-primary"
                      >
                        {isExpanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                      </button>
                    </td>
                    <td className="px-4 py-2 font-medium text-dark">{dispatch.recipient}</td>
                    <td className="px-4 py-2 text-gray">{dispatch.template?.name ?? '-'}</td>
                    <td className="px-4 py-2 text-gray">{dispatch.creator?.name ?? '-'}</td>
                    <td className="px-4 py-2">
                      <span className={dispatch.status === 'FAILED' ? 'font-semibold text-red-600' : 'font-semibold text-dark'}>
                        {DISPATCH_STATUS_LABEL[dispatch.status]}
                      </span>
                    </td>
                    <td className="px-4 py-2 text-gray">{new Date(dispatch.created_at).toLocaleString('pt-PT')}</td>
                  </tr>
                  {isExpanded && (
                    <tr className="border-b border-gray/5 bg-light text-xs">
                      <td />
                      <td colSpan={5} className="px-4 py-3">
                        <p className="font-semibold text-dark">Conteúdo da mensagem</p>
                        <pre className="mt-1 whitespace-pre-wrap rounded-lg bg-white p-2 font-sans text-xs text-dark">{dispatch.message}</pre>
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
            {historyQuery.data?.data.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-xs text-gray">Nenhum SMS enviado ainda.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/**
 * Uso de SMS por Admin (Setembro 2026, pedido explícito do cliente) —
 * monitorização/auditoria de custos. Estritamente Super Admin (mesmo
 * padrão de SmsCreditsPanel). O limite diário de cada Admin edita-se
 * em /admin/users, não aqui — esta é só a vista de leitura/auditoria.
 */
function SmsUsageByAdminPanel() {
  const usageQuery = useQuery({ queryKey: ['sms-usage-by-admin'], queryFn: () => operationsService.usageByAdmin() })

  return (
    <div className="mb-4 rounded-xl border border-gray/15 bg-white p-4">
      <div className="flex items-center gap-2">
        <ShieldCheck size={18} className="text-primary" />
        <div>
          <p className="text-sm font-semibold text-dark">Uso de SMS por Admin</p>
          <p className="text-xs text-gray">
            Quantos SMS cada Admin enviou hoje e este mês. O limite diário edita-se em Utilizadores.
          </p>
        </div>
      </div>

      <div className="mt-3 overflow-x-auto">
        <table className="w-full text-xs">
          <thead className="border-b border-gray/10 text-left uppercase text-gray">
            <tr>
              <th className="px-2 py-1.5">Admin</th>
              <th className="px-2 py-1.5">Província</th>
              <th className="px-2 py-1.5">Hoje</th>
              <th className="px-2 py-1.5">Limite diário</th>
              <th className="px-2 py-1.5">Este mês</th>
              <th className="px-2 py-1.5">Último envio</th>
            </tr>
          </thead>
          <tbody>
            {(usageQuery.data ?? []).map((row) => {
              const nearLimit = row.daily_limit !== null && row.remaining_today !== null && row.remaining_today <= Math.max(1, row.daily_limit * 0.1)
              return (
                <tr key={row.id} className="border-b border-gray/5">
                  <td className="px-2 py-1.5 font-medium text-dark">{row.name}</td>
                  <td className="px-2 py-1.5 text-gray">{row.province ?? '-'}</td>
                  <td className={`px-2 py-1.5 font-semibold ${nearLimit ? 'text-danger' : 'text-dark'}`}>{row.sent_today}</td>
                  <td className="px-2 py-1.5 text-gray">{row.daily_limit ?? 'Sem limite'}</td>
                  <td className="px-2 py-1.5 text-gray">{row.sent_this_month}</td>
                  <td className="px-2 py-1.5 text-gray">{row.last_sent_at ? new Date(row.last_sent_at).toLocaleString('pt-PT') : '-'}</td>
                </tr>
              )
            })}
            {usageQuery.data?.length === 0 && (
              <tr><td colSpan={6} className="px-2 py-4 text-center text-gray">Nenhum Admin encontrado.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/**
 * Saldo de créditos do provedor de SMS (pedido explícito do cliente,
 * Setembro 2026) — estritamente Super Admin (o backend recusa um Admin
 * comum, 403, mesmo tendo `sms.view`). Consulta sob pedido (botão), em
 * vez de automática ao abrir a página — é uma chamada de rede real ao
 * fornecedor externo, não um dado local para carregar sempre.
 */
function SmsCreditsPanel() {
  const [checked, setChecked] = useState(false)

  const creditsQuery = useQuery({
    queryKey: ['sms-credits'],
    queryFn: () => operationsService.credits(),
    enabled: checked,
  })

  const credits = creditsQuery.data

  return (
    <div className="mb-4 rounded-xl border border-primary/20 bg-primary/5 p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Wallet size={18} className="text-primary" />
          <div>
            <p className="text-sm font-semibold text-dark">Saldo de créditos SMS</p>
            <p className="text-xs text-gray">Consulta o saldo junto do provedor ativo (só disponível com a Kamba).</p>
          </div>
        </div>
        <button
          onClick={() => { setChecked(true); if (checked) creditsQuery.refetch() }}
          disabled={creditsQuery.isFetching}
          className="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-60"
        >
          {creditsQuery.isFetching ? <Loader2 size={15} className="animate-spin" /> : <Wallet size={15} />}
          {creditsQuery.isFetching ? 'A consultar...' : checked ? 'Atualizar saldo' : 'Ver saldo'}
        </button>
      </div>

      {checked && credits && (
        <div className="mt-3 border-t border-primary/10 pt-3">
          {credits.supported ? (
            credits.balance !== null ? (
              <p className="text-2xl font-bold text-dark">{credits.balance} <span className="text-sm font-normal text-gray">créditos</span></p>
            ) : (
              <p className="text-sm text-danger">{credits.message ?? 'Não foi possível consultar o saldo neste momento.'}</p>
            )
          ) : (
            <p className="text-sm text-gray">{credits.message}</p>
          )}
        </div>
      )}

      {checked && creditsQuery.isError && (
        <p className="mt-3 text-sm text-danger">
          {getApiErrorMessage(creditsQuery.error, 'Não foi possível consultar o saldo de créditos.')}
        </p>
      )}
    </div>
  )
}

function StatCard({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <p className="text-xs text-gray">{label}</p>
      <p className="mt-1 text-lg font-bold text-dark">{value}</p>
    </div>
  )
}
