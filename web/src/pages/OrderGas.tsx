import { forwardRef, useEffect, useRef, useState, type InputHTMLAttributes, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MapPin, ArrowLeft, ArrowRight, PackageCheck, RotateCcw, Check } from 'lucide-react'
import { useGeolocation } from '@/hooks/useGeolocation'
import { territoryGuessMessage, useTerritoryFromCoords } from '@/hooks/useTerritoryFromCoords'
import { LocationPicker } from '@/components/LocationPicker'
import { HoneypotField } from '@/components/HoneypotField'
import { useHoneypot } from '@/hooks/useHoneypot'
import { territoryService } from '@/services/territoryService'
import { gasOrderService } from '@/services/gasOrderService'
import type { GasOrderPayload } from '@/types'
import { successFeedback } from '@/native/haptics'
import { useAuth } from '@/hooks/useAuth'
import { saveActiveOrder } from '@/lib/activeOrder'
import { PageHero } from '@/components/fx/PageHero'

/**
 * Fluxo de pedido em 4 passos (secção 220): Contacto -> Localização ->
 * Pedido -> Confirmação. Objetivo: pedido rápido em menos de 60s
 * (secção 177), sem obrigar criação de conta (secção 172).
 */
const schema = z.object({
  customer_name: z.string().min(2, 'Informe o seu nome.'),
  customer_phone: z.string().min(9, 'Informe um número de telefone válido.'),
  province_id: z.coerce.number({ error: 'Selecione a província.' }),
  municipality_id: z.coerce.number({ error: 'Selecione o município.' }),
  neighborhood: z.string().optional(),
  address: z.string().optional(),
  reference_point: z.string().optional(),
  quantity: z.coerce.number().min(1).max(20),
  cylinder_type: z.string().optional(),
  notes: z.string().max(500).optional(),
})

/**
 * O zod 4 distingue o tipo de ENTRADA do de SAÍDA: em `z.coerce.number()`
 * a entrada é `unknown` (o que vem do input HTML) e a saída é `number`.
 * Por isso o useForm leva os três genéricos,entrada, contexto e saída —
 * e o handleSubmit entrega já os valores convertidos. (Novembro 2026)
 */
type FormInput = z.input<typeof schema>
type FormValues = z.output<typeof schema>

const STEPS = ['Contacto', 'Localização', 'Pedido', 'Confirmação'] as const

export default function OrderGas() {
  const [step, setStep] = useState(0)
  // Sentido da última mudança de passo: o novo passo entra pela direita ou pela esquerda.
  const [dir, setDir] = useState<'next' | 'prev'>('next')
  // Honeypot anti-bot (Outubro 2026),fica montado em todos os passos.
  const honeypot = useHoneypot()
  // BUG CORRIGIDO: a validação por passo dependia de trigger() do
  // react-hook-form a re-validar o schema inteiro (Zod) a cada
  // avanço, o que se mostrou pouco previsível num formulário de vários
  // passos com campos ainda por montar (ex: província no passo 0).
  // Substituído por verificações locais simples, diretas, sem
  // qualquer dependência do resolver,impossível de dar falso
  // "Required" com um valor já preenchido.
  const [attemptedStep, setAttemptedStep] = useState<number | null>(null)
  const geo = useGeolocation()
  const [adjustedCoords, setAdjustedCoords] = useState<{ lat: number; lng: number } | null>(null)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  useEffect(() => {
    if (geo.status === 'granted' && geo.latitude !== null && geo.longitude !== null) {
      setAdjustedCoords({ lat: geo.latitude, lng: geo.longitude })
    }
  }, [geo.status, geo.latitude, geo.longitude])

  const { register, handleSubmit, watch, setValue, getValues } = useForm<FormInput, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { quantity: 1, customer_name: '', customer_phone: '' },
  })

  // Pré-preenchimento (Setembro 2026): com sessão iniciada, nome e
  // telefone já vêm da conta. Com `?repetir=1` (o "Pedir agora" das
  // notificações de gás a acabar), o resto vem do último pedido,o
  // pedido fica a um toque de "Confirmar".
  const { user, isAuthenticated } = useAuth()
  const [searchParams] = useSearchParams()
  const repeatLast = searchParams.get('repetir') === '1'
  const lastOrderQuery = useQuery({
    queryKey: ['my-orders', 'last'],
    queryFn: () => gasOrderService.listMine(),
    enabled: isAuthenticated && repeatLast,
  })
  const lastOrder = lastOrderQuery.data?.data[0]
  const filledFromAccount = useRef(false)
  const filledFromLastOrder = useRef(false)
  const pendingMunicipality = useRef<number | null>(null)
  const [repeatedFrom, setRepeatedFrom] = useState<string | null>(null)

  useEffect(() => {
    if (filledFromAccount.current || !user) return
    filledFromAccount.current = true
    if (!getValues('customer_name')) setValue('customer_name', user.name)
    if (!getValues('customer_phone')) setValue('customer_phone', user.phone)
  }, [user, getValues, setValue])

  useEffect(() => {
    if (filledFromLastOrder.current || !lastOrder) return
    filledFromLastOrder.current = true
    if (lastOrder.province_id) setValue('province_id', lastOrder.province_id)
    pendingMunicipality.current = lastOrder.municipality_id ?? null
    setValue('neighborhood', lastOrder.neighborhood ?? '')
    setValue('address', lastOrder.address ?? '')
    setValue('reference_point', lastOrder.reference_point ?? '')
    setValue('quantity', lastOrder.quantity ?? 1)
    setValue('cylinder_type', lastOrder.cylinder_type ?? '')
    if (lastOrder.latitude != null && lastOrder.longitude != null) {
      setAdjustedCoords({ lat: lastOrder.latitude, lng: lastOrder.longitude })
    }
    setRepeatedFrom(lastOrder.reference)
  }, [lastOrder, setValue])

  const customerName = watch('customer_name')
  const customerPhone = watch('customer_phone')
  const provinceId = watch('province_id')
  const municipalityId = watch('municipality_id')
  // watch() devolve o valor cru do input (string), porque o zod só o
  // converte na validação; aqui normalizamos para número uma vez só.
  const quantity = Number(watch('quantity') ?? 0)

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(Number(provinceId)),
    enabled: !!provinceId,
  })

  // O município só pode ser escolhido depois de a lista da província
  // carregar (senão o <select> não tem a opção e fica em branco).
  useEffect(() => {
    if (pendingMunicipality.current && municipalitiesQuery.data?.some((m) => m.id === pendingMunicipality.current)) {
      setValue('municipality_id', pendingMunicipality.current)
      pendingMunicipality.current = null
    }
  }, [municipalitiesQuery.data, setValue])

  // "Partilhar minha localização" também preenche província e município;
  // o cliente pode alterá-los (uma escolha manual nunca é sobrescrita).
  const territory = useTerritoryFromCoords(adjustedCoords, !!provincesQuery.data, (guess) => {
    pendingMunicipality.current = guess.municipality_id
    if (Number(getValues('province_id')) !== guess.province_id) setValue('municipality_id', undefined as unknown as number)
    setValue('province_id', guess.province_id as number)
    // Mesma província já carregada: a lista não muda, aplica logo.
    if (guess.municipality_id && Number(provinceId) === guess.province_id && municipalitiesQuery.data?.some((m) => m.id === guess.municipality_id)) {
      setValue('municipality_id', guess.municipality_id)
      pendingMunicipality.current = null
    }
  })

  const mutation = useMutation({
    mutationFn: (payload: GasOrderPayload) => gasOrderService.create(payload),
    onSuccess: (order, payload) => {
      void successFeedback()
      // Faz aparecer o botão "Acompanhar pedido" (até o pedido terminar).
      saveActiveOrder({ reference: order.reference, phone: payload.customer_phone })
      void queryClient.invalidateQueries({ queryKey: ['active-order'] })
      navigate(`/order/success/${order.reference}`)
    },
  })

  const stepIsValid: Record<number, boolean> = {
    0: (customerName ?? '').trim().length >= 2 && (customerPhone ?? '').trim().length >= 9,
    1: !!provinceId && !!municipalityId,
    2: !!quantity && quantity >= 1 && quantity <= 20,
  }

  const next = () => {
    if (stepIsValid[step]) {
      setAttemptedStep(null)
      setDir('next')
      setStep((s) => Math.min(s + 1, STEPS.length - 1))
    } else {
      setAttemptedStep(step)
    }
  }

  const back = () => {
    setDir('prev')
    setStep((s) => Math.max(s - 1, 0))
  }

  const onSubmit = (values: FormValues) => {
    // Só o último passo envia. Enter num campo ou um clique que "vaze" para o
    // botão de envio avançam o passo em vez de criar o pedido sem confirmação.
    if (step < STEPS.length - 1) {
      next()
      return
    }
    mutation.mutate({
      ...values,
      latitude: adjustedCoords?.lat,
      longitude: adjustedCoords?.lng,
      location_accuracy: geo.accuracy ?? undefined,
      location_source: geo.status === 'granted' ? 'GPS' : 'MANUAL',
      website: honeypot.website,
    })
  }

  // Erros só aparecem depois de uma tentativa de avançar falhada NESSE
  // passo específico,nunca antes de o utilizador interagir.
  const showStepErrors = attemptedStep === step
  const slide = dir === 'next' ? 'fx-slide-next' : 'fx-slide-prev'

  return (
    <div className="mx-auto max-w-xl px-4 py-10">
      <PageHero title="Pedir" highlight="gás" subtitle="Informe onde está e como podemos contactá-lo, que a nossa equipa assume o resto." />

      <StepIndicator current={step} />

      {repeatedFrom && (
        <p className="mt-4 flex items-center gap-2 rounded-lg bg-primary/5 px-3 py-2 text-sm text-dark">
          <RotateCcw size={16} className="shrink-0 text-primary" />
          Preenchemos com os dados do seu último pedido ({repeatedFrom}). Confirme e está feito!
        </p>
      )}

      <form
        onSubmit={(e) => {
          // Enter num campo nunca envia: só avança o passo (o envio é o botão do último passo).
          e.preventDefault()
          if (step < STEPS.length - 1) next()
        }}
        className="fx-glass mt-6 space-y-4 rounded-3xl p-5 sm:p-6">
        {step === 0 && (
          <fieldset className={`space-y-3 ${slide}`}>
            <Input
              label="Nome completo"
              error={showStepErrors && (customerName ?? '').trim().length < 2 ? 'Informe o seu nome.' : undefined}
              {...register('customer_name')}
            />
            <Input
              label="Número de telefone"
              type="tel"
              error={showStepErrors && (customerPhone ?? '').trim().length < 9 ? 'Informe um número de telefone válido.' : undefined}
              {...register('customer_phone')}
            />
          </fieldset>
        )}

        {step === 1 && (
          <fieldset className={`space-y-3 ${slide}`}>
            <button
              type="button"
              onClick={() => { territory.reset(); geo.request() }}
              disabled={geo.status === 'requesting'}
              className="fx-btn-ghost flex w-full items-center justify-center gap-2 rounded-xl border-2 border-primary px-4 py-3 text-sm font-semibold text-primary disabled:opacity-60"
            >
              <span className={geo.status === 'requesting' ? 'fx-radar' : 'inline-flex'}><MapPin size={16} /></span> {geo.status === 'granted' ? 'Localização obtida' : geo.status === 'requesting' ? 'A obter localização...' : 'Partilhar minha localização'}
            </button>

            {(geo.status === 'denied' || geo.status === 'unsupported') && geo.error && (
              <p role="alert" className="text-xs text-danger">{geo.error}</p>
            )}

            {geo.status === 'granted' && adjustedCoords && (
              <LocationPicker
                latitude={adjustedCoords.lat}
                longitude={adjustedCoords.lng}
                accuracy={geo.accuracy}
                onChange={(lat, lng) => setAdjustedCoords({ lat, lng })}
              />
            )}

            {territoryGuessMessage(territory.status) && (
              <p className={`text-xs ${territory.status === 'unknown' ? 'text-danger' : 'text-gray'}`} role="status">
                {territoryGuessMessage(territory.status)}
              </p>
            )}

            <p className="text-xs text-gray">
              Precisamos da sua localização para ajudar a organizar a entrega do gás no local correto.
              Se preferir, informe o endereço manualmente abaixo.
            </p>

            <Select
              label="Província"
              error={showStepErrors && !provinceId ? 'Selecione a província.' : undefined}
              {...register('province_id', { onChange: territory.markTouched })}
              options={provincesQuery.data?.map((p) => ({ value: p.id, label: p.name })) ?? []}
            />
            <Select
              label="Município"
              error={showStepErrors && !municipalityId ? 'Selecione o município.' : undefined}
              {...register('municipality_id', { onChange: territory.markTouched })}
              disabled={!provinceId}
              options={municipalitiesQuery.data?.map((m) => ({ value: m.id, label: m.name })) ?? []}
            />
            <Input label="Bairro / Zona" {...register('neighborhood')} />
            <Input label="Endereço" {...register('address')} />
            <Input label="Ponto de referência" {...register('reference_point')} />
          </fieldset>
        )}

        {step === 2 && (
          <fieldset className={`space-y-3 ${slide}`}>
            <Input
              label="Quantidade"
              type="number"
              error={showStepErrors && !stepIsValid[2] ? 'Informe uma quantidade válida (1 a 20).' : undefined}
              {...register('quantity')}
            />
            <Input label="Tipo de botija (opcional)" {...register('cylinder_type')} />
            <Textarea label="Observações" {...register('notes')} placeholder="Ex: Ligar antes de chegar." />
          </fieldset>
        )}

        {step === 3 && (
          <div className={`rounded-2xl border border-primary/20 bg-light p-5 text-sm ${slide}`}>
            <p className="font-medium text-dark">Confirme os dados do seu pedido antes de enviar.</p>
            <p className="mt-2 text-gray">Nome: {customerName}</p>
            <p className="text-gray">Telefone: {customerPhone}</p>
            <p className="text-gray">Quantidade: {quantity}</p>
            <p className="mt-2 text-xs text-gray">
              A nossa equipa entrará em contacto consigo para confirmar os detalhes e organizar a entrega.
            </p>
          </div>
        )}

        {mutation.isError && (
          <p className="text-sm text-danger">Não foi possível enviar o pedido neste momento. Tente novamente.</p>
        )}

        <div className="flex gap-3 pt-2">
          {step > 0 && (
            <button type="button" onClick={back} className="fx-btn-ghost flex items-center gap-1.5 rounded-xl border border-gray/20 px-4 py-3 text-sm font-medium">
              <ArrowLeft size={16} /> Voltar
            </button>
          )}

          {step < STEPS.length - 1 ? (
            // `key` distinto: sem ele o React reaproveita o mesmo <button> e muda
            // o type de "button" para "submit" a meio do clique, o que enviava o
            // pedido ao carregar em "Continuar" (sem passar pela confirmação).
            <button
              key="next"
              type="button"
              onClick={next}
              className="fx-btn-fire group ml-auto flex items-center gap-1.5 rounded-xl px-6 py-3 text-sm font-semibold text-white"
            >
              Continuar <ArrowRight size={16} className="transition-transform group-hover:translate-x-1" />
            </button>
          ) : (
            <button
              key="submit"
              type="button"
              onClick={handleSubmit(onSubmit)}
              disabled={mutation.isPending}
              className="fx-btn-fire ml-auto flex items-center gap-1.5 rounded-xl px-6 py-3 text-sm font-semibold text-white disabled:opacity-60"
            >
              <PackageCheck size={16} /> {mutation.isPending ? 'A enviar...' : 'Enviar pedido'}
            </button>
          )}
        </div>
        <HoneypotField {...honeypot.fieldProps} />
      </form>
    </div>
  )
}

function StepIndicator({ current }: { current: number }) {
  const pct = (current / (STEPS.length - 1)) * 100
  return (
    <div className="relative mt-8 px-0.5" role="list" aria-label="Progresso do pedido">
      {/* Calha e enchimento a deslizar entre os passos. */}
      <span className="absolute left-[14px] right-[14px] top-[13px] h-[3px] rounded-full bg-gray/15" aria-hidden="true" />
      <span className="fx-steps-fill" style={{ width: `calc((100% - 28px) * ${pct / 100})` }} aria-hidden="true" />
      <div className="relative flex justify-between">
        {STEPS.map((s, i) => {
          const done = i < current
          const active = i === current
          return (
            <div key={s} role="listitem" className="flex w-16 flex-col items-center gap-1.5 whitespace-nowrap" aria-current={active ? 'step' : undefined}>
              <span
                className={`fx-step-dot relative flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold ${
                  done ? 'bg-primary text-white' : active ? 'is-active bg-primary text-white' : 'bg-gray/15 text-gray'
                }`}
              >
                {done ? <Check size={14} strokeWidth={3} aria-hidden="true" /> : i + 1}
              </span>
              <span className={`text-[11px] ${active ? 'font-semibold text-primary' : 'text-gray'}`}>{s}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

/**
 * BUG CORRIGIDO (causa raiz real, confirmada pelo aviso da consola
 * "Function components cannot be given refs... Did you mean to use
 * React.forwardRef()?"): estes três componentes são usados como
 * `<Input {...register('nome')} />`, e `register()` devolve, entre
 * outras coisas, uma `ref`. Sem `forwardRef`, o React descartava-a
 * silenciosamente,nunca chegava ao elemento nativo. O
 * react-hook-form depende dessa ref para o seu modelo de campos não
 * controlados; sem ela, o valor digitado nem sempre era lido
 * corretamente na validação, mesmo com o campo visivelmente
 * preenchido. Era esta a causa do "Required" persistente. Confirmado
 * pela comparação com o Login (que usa `<input>` nativo diretamente,
 * sem wrapper, e nunca teve este problema).
 */
const Input = forwardRef<HTMLInputElement, { label: string; error?: string } & InputHTMLAttributes<HTMLInputElement>>(
  function Input({ label, error, ...rest }, ref) {
    return (
      <label className="block text-sm">
        <span className="mb-1 block font-medium text-dark">{label}</span>
        <input
          ref={ref}
          className={`fx-input w-full rounded-xl border px-3.5 py-3 text-sm ${error ? 'border-danger' : 'border-gray/20'}`}
          {...rest}
        />
        {error && <span className="mt-1 block text-xs text-danger">{error}</span>}
      </label>
    )
  }
)

const Textarea = forwardRef<HTMLTextAreaElement, { label: string } & TextareaHTMLAttributes<HTMLTextAreaElement>>(
  function Textarea({ label, ...rest }, ref) {
    return (
      <label className="block text-sm">
        <span className="mb-1 block font-medium text-dark">{label}</span>
        <textarea ref={ref} className="fx-input w-full rounded-xl border border-gray/20 px-3.5 py-3 text-sm" rows={3} {...rest} />
      </label>
    )
  }
)

const Select = forwardRef<
  HTMLSelectElement,
  { label: string; error?: string; options: { value: number; label: string }[] } & SelectHTMLAttributes<HTMLSelectElement>
>(function Select({ label, error, options, ...rest }, ref) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-dark">{label}</span>
      <select ref={ref} className={`fx-input w-full rounded-xl border px-3.5 py-3 text-sm ${error ? 'border-danger' : 'border-gray/20'}`} {...rest}>
        <option value="">Selecione</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
      {error && <span className="mt-1 block text-xs text-danger">{error}</span>}
    </label>
  )
})
