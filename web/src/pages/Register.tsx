import { forwardRef, useEffect, useRef, useState, type InputHTMLAttributes } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { MapPin, CheckCircle2 } from 'lucide-react'
import { authService } from '@/services/authService'
import { getApiErrorMessage } from '@/lib/api'
import { territoryService } from '@/services/territoryService'
import { useAuthStore } from '@/stores/authStore'
import { useGeolocation } from '@/hooks/useGeolocation'
import { territoryGuessMessage, useTerritoryFromCoords } from '@/hooks/useTerritoryFromCoords'
import { LocationPicker } from '@/components/LocationPicker'
import { HoneypotField } from '@/components/HoneypotField'
import { useHoneypot } from '@/hooks/useHoneypot'
import { AuthShell } from '@/components/fx/AuthShell'

const schema = z
  .object({
    name: z.string().min(2, 'Informe o seu nome completo.'),
    phone: z.string().min(9, 'Informe um número de telefone válido.'),
    email: z.string().email('Email inválido.').optional().or(z.literal('')),
    password: z.string().min(8, 'A password deve ter pelo menos 8 caracteres.'),
    password_confirmation: z.string(),
    province_id: z.coerce.number({ error: 'Selecione a província.' }),
    municipality_id: z.coerce.number({ error: 'Selecione o município.' }),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'As passwords não coincidem.',
    path: ['password_confirmation'],
  })

/**
 * O zod 4 distingue o tipo de ENTRADA do de SAÍDA: em `z.coerce.number()`
 * a entrada é `unknown` (o que vem do input HTML) e a saída é `number`.
 * Por isso o useForm leva os três genéricos,entrada, contexto e saída —
 * e o handleSubmit entrega já os valores convertidos. (Novembro 2026)
 */
type FormInput = z.input<typeof schema>
type FormValues = z.output<typeof schema>

export default function Register() {
  const navigate = useNavigate()
  const setSession = useAuthStore((s) => s.setSession)
  const geo = useGeolocation()
  const honeypot = useHoneypot()
  // Começa igual ao que o browser deteta, mas o utilizador pode
  // arrastar o pino para corrigir,ver LocationPicker para o porquê
  // (geolocalização por IP em Angola frequentemente resolve para
  // Luanda, mesmo para quem está noutra cidade).
  const [adjustedCoords, setAdjustedCoords] = useState<{ lat: number; lng: number } | null>(null)

  useEffect(() => {
    if (geo.status === 'granted' && geo.latitude !== null && geo.longitude !== null) {
      setAdjustedCoords({ lat: geo.latitude, lng: geo.longitude })
    }
  }, [geo.status, geo.latitude, geo.longitude])

  const { register, handleSubmit, watch, setValue, formState: { errors } } = useForm<FormInput, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', phone: '', email: '', password: '', password_confirmation: '' },
    mode: 'onChange',
    reValidateMode: 'onChange',
  })

  const provinceId = watch('province_id')

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(Number(provinceId)),
    enabled: !!provinceId,
  })

  // "Obter localização" também preenche província e município (o
  // utilizador pode alterá-los,uma escolha manual nunca é sobrescrita).
  const pendingMunicipality = useRef<number | null>(null)
  const territory = useTerritoryFromCoords(adjustedCoords, !!provincesQuery.data, (guess) => {
    pendingMunicipality.current = guess.municipality_id
    // Município de outra província deixa de valer.
    if (Number(provinceId) !== guess.province_id) setValue('municipality_id', undefined as unknown as number)
    setValue('province_id', guess.province_id as number, { shouldValidate: true })
    if (guess.municipality_id && Number(provinceId) === guess.province_id && municipalitiesQuery.data) {
      // Mesma província já carregada: aplica logo (o efeito abaixo só corre quando a lista muda).
      applyPendingMunicipality()
    }
  })

  function applyPendingMunicipality() {
    if (pendingMunicipality.current && municipalitiesQuery.data?.some((m) => m.id === pendingMunicipality.current)) {
      setValue('municipality_id', pendingMunicipality.current, { shouldValidate: true })
      pendingMunicipality.current = null
    }
  }

  useEffect(applyPendingMunicipality, [municipalitiesQuery.data]) // eslint-disable-line react-hooks/exhaustive-deps

  const mutation = useMutation({
    mutationFn: authService.register,
    onSuccess: ({ user, token }) => {
      setSession(user, token)
      navigate('/dashboard', { replace: true })
    },
  })

  return (
    <AuthShell title="Criar conta" subtitle="Registe-se para favoritar postos e receber alertas.">
      <form
        onSubmit={handleSubmit((v) =>
          mutation.mutate({
            ...v,
            email: v.email || undefined,
            latitude: adjustedCoords?.lat,
            longitude: adjustedCoords?.lng,
            website: honeypot.website,
          })
        )}
        className="fx-stagger mt-6 space-y-4"
      >
        <Field label="Nome completo" error={errors.name?.message} {...register('name')} />
        <Field label="Telefone" type="tel" error={errors.phone?.message} {...register('phone')} />
        <Field label="Email (opcional)" type="email" error={errors.email?.message} {...register('email')} />

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Província</span>
          <select className="fx-input w-full rounded-xl border border-gray/20 px-3.5 py-3 text-sm" {...register('province_id', { onChange: territory.markTouched })}>
            <option value="">Selecione</option>
            {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          {errors.province_id && <span className="mt-1 block text-xs text-danger">{errors.province_id.message}</span>}
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Município</span>
          <select className="fx-input w-full rounded-xl border border-gray/20 px-3.5 py-3 text-sm" disabled={!provinceId} {...register('municipality_id', { onChange: territory.markTouched })}>
            <option value="">Selecione</option>
            {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </select>
          {errors.municipality_id && <span className="mt-1 block text-xs text-danger">{errors.municipality_id.message}</span>}
        </label>

        <div>
          <button
            type="button"
            onClick={() => { territory.reset(); geo.request() }}
            disabled={geo.status === 'granted' || geo.status === 'requesting'}
            className={`flex w-full items-center justify-center gap-2 rounded-lg border-2 px-4 py-2.5 text-sm font-medium transition-colors ${
              geo.status === 'granted'
                ? 'border-success bg-success/5 text-success'
                : 'border-dashed border-primary/40 text-primary hover:bg-primary/5'
            }`}
          >
            {geo.status === 'granted' ? (
              <>
                <CheckCircle2 size={16} /> Localização exata guardada
              </>
            ) : (
              <>
                <MapPin size={16} />
                {geo.status === 'requesting' ? 'A obter localização...' : 'Aperte este botão, se estiver apenas em sua casa'}
              </>
            )}
          </button>
          <p className="mt-1.5 text-xs text-gray">
            Opcional. Ajuda a nossa equipa a traçar a rota de entrega até si com precisão,
            especialmente útil se não conseguir atender chamadas ou ler mensagens.
          </p>
          {(geo.status === 'denied' || geo.status === 'unsupported') && (
            <p role="alert" className="mt-1 text-xs text-danger">
              {geo.error ?? 'Não foi possível obter a localização.'} Pode continuar sem ela.
            </p>
          )}
          {territoryGuessMessage(territory.status) && (
            <p className={`mt-1 text-xs ${territory.status === 'unknown' ? 'text-danger' : 'text-gray'}`} role="status">
              {territoryGuessMessage(territory.status)}
            </p>
          )}
          {geo.status === 'granted' && adjustedCoords && (
            <div className="mt-3">
              <LocationPicker
                latitude={adjustedCoords.lat}
                longitude={adjustedCoords.lng}
                accuracy={geo.accuracy}
                onChange={(lat, lng) => setAdjustedCoords({ lat, lng })}
              />
            </div>
          )}
        </div>

        <Field label="Password" type="password" error={errors.password?.message} {...register('password')} />
        <Field label="Confirmar password" type="password" error={errors.password_confirmation?.message} {...register('password_confirmation')} />

        {mutation.isError && (
          <p className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível criar a conta. Verifique os dados e tente novamente.')}</p>
        )}

        <button
          type="submit"
          disabled={mutation.isPending}
          className="fx-btn-fire w-full rounded-xl px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
        >
          {mutation.isPending ? 'A criar conta...' : 'Criar conta'}
        </button>
        <HoneypotField {...honeypot.fieldProps} />
      </form>
    </AuthShell>
  )
}

/**
 * BUG CORRIGIDO (causa raiz, confirmada pelo aviso da consola
 * "Function components cannot be given refs... Did you mean to use
 * React.forwardRef()?"): este componente é usado como
 * `<Field {...register('nome')} />`, e `register()` devolve, entre
 * outras coisas, uma `ref`. Sem `forwardRef`, o React descarta essa
 * `ref` silenciosamente (é assim que refs funcionam em componentes de
 * função),nunca chegava ao `<input>` real. O react-hook-form
 * depende dessa ref para o seu modelo de campos não controlados;
 * sem ela, o valor digitado nem sempre era lido corretamente na
 * validação, mesmo com o campo visivelmente preenchido. Era esta a
 * causa do "Required" persistente em campos com texto.
 */
const Field = forwardRef<HTMLInputElement, { label: string; error?: string } & InputHTMLAttributes<HTMLInputElement>>(
  function Field({ label, error, ...rest }, ref) {
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
