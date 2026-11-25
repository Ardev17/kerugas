import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent, type ReactNode } from 'react'
import { Reveal } from '@/components/fx/Reveal'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { UserCircle2, Lock, MapPin, Smartphone, Bell, Check, Eye, EyeOff, LocateFixed, Camera, Trash2, AlertTriangle, Images, Headset } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { useCentralChat } from '@/hooks/useCentralChat'
import { useAuthStore } from '@/stores/authStore'
import { profileService, fieldErrors, type ProfilePayload } from '@/services/profileService'
import { vizinhosService } from '@/services/vizinhosService'
import { territoryService } from '@/services/territoryService'
import { getApiErrorMessage } from '@/lib/api'
import { ROLE_LABEL } from '@/lib/roles'
import { LocationPicker } from '@/components/LocationPicker'
import { Avatar } from '@/components/Avatar'
import { AvatarCropper } from '@/components/AvatarCropper'
import { AVATAR_ACCEPT, validateAvatarFile } from '@/lib/avatar'
import { avatarService } from '@/services/avatarService'
import { accountService } from '@/services/accountService'
import { canUseNativeCamera, pickNativePhoto } from '@/native/camera'
import { readQueue } from '@/lib/pickupQueue'
import type { AuthUser } from '@/types'

/** Papéis cuja província/município é a área de trabalho (o servidor recusa mudar). */
const LOCATION_LOCKED: AuthUser['role'][] = ['ADMIN', 'PROVINCE_SUPER_ADMIN', 'DRIVER']

const digits = (s: string) => s.replace(/\D/g, '').replace(/^244/, '')

/**
 * "Meu Perfil" (Outubro 2026),para todos os papéis: dados pessoais,
 * localização, palavra-passe e sessões abertas. O servidor é quem garante
 * as regras (só a própria conta, palavra-passe para mudar telemóvel/email,
 * área de trabalho bloqueada para Admin/Provincial/Condutor).
 */
export default function Profile() {
  const { user } = useAuth()

  if (!user) return <div className="mx-auto max-w-3xl px-4 py-16 text-center text-gray">A carregar o seu perfil...</div>

  return (
    <div className="mx-auto max-w-3xl space-y-6 px-4 py-8">
      <header className="fx-slide-next relative isolate flex items-center gap-4">
        <div className="fx-hero-glow" aria-hidden="true" />
        <span className="fx-avatar-ring">
          <Avatar name={user.name} url={user.avatar_url} size="lg" />
        </span>
        <div>
          <h1 className="text-3xl font-extrabold tracking-tight text-dark">Meu <span className="fx-gradient-text">perfil</span></h1>
          <p className="text-sm text-gray">
            {ROLE_LABEL[user.role]}
            {user.province ? ` · ${user.province}` : ''}
            {user.created_at ? ` · membro desde ${new Date(user.created_at).toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' })}` : ''}
          </p>
        </div>
      </header>

      <PhotoCard user={user} />
      <PersonalDataCard user={user} />
      <LocationCard user={user} />
      <PasswordCard />
      <SessionsCard />

      <Link to="/notificacoes" className="fx-row flex items-center gap-2 rounded-2xl border border-gray/15 bg-white p-4 text-sm font-medium text-dark">
        <Bell size={16} className="text-primary" /> Preferências de notificações
      </Link>

      <CentralChatLink />

      <DeleteAccountCard />
    </div>
  )
}

function useSaveProfile() {
  const { token, setSession } = useAuthStore()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: ProfilePayload) => profileService.update(payload),
    onSuccess: (updated) => {
      if (token) setSession(updated, token)
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

/**
 * Foto de perfil (Outubro 2026): opcional e visível para todos. Escolher →
 * enquadrar/recortar → enviar. O servidor valida e re-codifica a imagem.
 */
function PhotoCard({ user }: { user: AuthUser }) {
  const { token, setSession } = useAuthStore()
  const inputRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [pickError, setPickError] = useState<string | null>(null)
  const [confirmRemove, setConfirmRemove] = useState(false)

  const onSaved = (updated: AuthUser) => {
    if (token) setSession(updated, token)
  }

  const upload = useMutation({
    mutationFn: (blob: Blob) => avatarService.upload(blob),
    onSuccess: (updated) => {
      onSaved(updated)
      setFile(null)
    },
  })
  const remove = useMutation({
    mutationFn: () => avatarService.remove(),
    onSuccess: (updated) => {
      onSaved(updated)
      setConfirmRemove(false)
    },
  })

  const accept = (picked: File) => {
    const problem = validateAvatarFile(picked)
    setPickError(problem)
    upload.reset()
    if (!problem) setFile(picked)
  }

  const onPick = (e: ChangeEvent<HTMLInputElement>) => {
    const picked = e.target.files?.[0]
    e.target.value = '' // permite voltar a escolher o mesmo ficheiro
    if (picked) accept(picked)
  }

  // App Android: câmara ou galeria do sistema (Outubro 2026).
  const native = canUseNativeCamera()
  const pickNative = async (source: 'camera' | 'gallery') => {
    try {
      const picked = await pickNativePhoto(source)
      if (picked) accept(picked)
    } catch {
      setPickError(source === 'camera' ? 'Não foi possível abrir a câmara.' : 'Não foi possível abrir a galeria.')
    }
  }

  const uploadError = upload.isError ? fieldErrors(upload.error).avatar ?? getApiErrorMessage(upload.error, 'Não foi possível guardar a foto.') : null
  const hasPhoto = Boolean(user.avatar_url)

  return (
    <Card icon={Camera} title="Foto de perfil">
      <div className="flex flex-wrap items-center gap-4">
        <span className="fx-avatar-ring">
          <Avatar name={user.name} url={user.avatar_url} size="xl" alt={hasPhoto ? 'A sua foto de perfil' : undefined} />
        </span>
        <div className="space-y-2">
          <div className="flex flex-wrap gap-2">
            {native ? (
              <>
                <button type="button" onClick={() => void pickNative('camera')} className="fx-btn-fire flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-semibold text-white">
                  <Camera size={14} /> Tirar foto
                </button>
                <button type="button" onClick={() => void pickNative('gallery')} className="flex items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-2 text-sm font-semibold text-primary hover:bg-primary/5">
                  <Images size={14} /> Galeria
                </button>
              </>
            ) : (
              <button
                type="button"
                onClick={() => inputRef.current?.click()}
                className="fx-btn-fire flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-semibold text-white"
              >
                <Camera size={14} /> {hasPhoto ? 'Mudar foto' : 'Escolher foto'}
              </button>
            )}
            {hasPhoto && !confirmRemove && (
              <button
                type="button"
                onClick={() => setConfirmRemove(true)}
                className="flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-2 text-sm font-medium text-dark hover:bg-light"
              >
                <Trash2 size={14} /> Remover
              </button>
            )}
            {confirmRemove && (
              <span className="flex items-center gap-2 text-sm">
                Remover a foto?
                <button type="button" onClick={() => remove.mutate()} disabled={remove.isPending} className="rounded-lg bg-danger px-3 py-1.5 font-semibold text-white disabled:opacity-50">
                  {remove.isPending ? 'A remover...' : 'Sim, remover'}
                </button>
                <button type="button" onClick={() => setConfirmRemove(false)} className="rounded-lg border border-gray/20 px-3 py-1.5 text-dark">
                  Não
                </button>
              </span>
            )}
          </div>
          <p className="text-xs text-gray">Opcional. JPG, PNG ou WebP. A foto fica visível para todos,escolha uma imagem adequada.</p>
          {pickError && <p role="alert" className="text-sm text-danger">{pickError}</p>}
          {remove.isError && <p role="alert" className="text-sm text-danger">{getApiErrorMessage(remove.error, 'Não foi possível remover a foto.')}</p>}
        </div>
      </div>
      <input ref={inputRef} type="file" accept={AVATAR_ACCEPT} onChange={onPick} className="hidden" data-testid="avatar-input" />
      {file && (
        <AvatarCropper
          file={file}
          busy={upload.isPending}
          error={uploadError}
          onCancel={() => {
            setFile(null)
            upload.reset()
          }}
          onConfirm={(blob) => upload.mutate(blob)}
        />
      )}
    </Card>
  )
}

/**
 * Eliminar a conta (Outubro 2026,exigência da Google Play). O servidor
 * diz o que impede a eliminação (ex.: garrafas por acertar) e aplica o
 * prazo de arrependimento: entrar de novo nesse prazo cancela.
 */
function DeleteAccountCard() {
  const navigate = useNavigate()
  const clearSession = useAuthStore((s) => s.clearSession)
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [reason, setReason] = useState('')

  const status = useQuery({ queryKey: ['account-deletion'], queryFn: () => accountService.deletionStatus(), enabled: open })
  const mutation = useMutation({
    mutationFn: (p: { current_password: string; confirmation: string; reason?: string }) => accountService.requestDeletion(p),
    onSuccess: (res) => {
      clearSession()
      navigate(`/eliminar-conta?agendada=${encodeURIComponent(res.scheduled_for)}`, { replace: true })
    },
  })
  const errors = fieldErrors(mutation.error)
  // Carregamentos registados sem internet ainda não chegaram ao servidor —
  // o servidor não os vê, por isso a verificação é feita aqui.
  const pendingPickups = open ? readQueue().length : 0
  const blockers = [
    ...(status.data?.blockers ?? []),
    ...(pendingPickups > 0 ? [`Tem ${pendingPickups} carregamento(s) por enviar (sem internet). Ligue-se à internet e abra o seu painel para os enviar.`] : []),
  ]
  const graceDays = status.data?.grace_days ?? 7

  return (
    <section aria-label="Eliminar conta" className="rounded-xl border border-danger/30 bg-white p-5">
      <h2 className="flex items-center gap-2 font-semibold text-danger"><AlertTriangle size={17} /> Eliminar conta</h2>
      {!open ? (
        <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-gray">Apaga os seus dados pessoais da Kerugas. Tem {graceDays} dias para mudar de ideias.</p>
          <button type="button" onClick={() => setOpen(true)} className="rounded-lg border border-danger/40 px-3 py-2 text-sm font-medium text-danger hover:bg-danger/5">
            Quero eliminar a minha conta
          </button>
        </div>
      ) : (
        <div className="mt-3 space-y-3 text-sm">
          <ul className="list-disc space-y-1 pl-5 text-gray">
            <li>Todas as sessões terminam já, em todos os dispositivos.</li>
            <li>Se entrar de novo nos próximos <strong className="text-dark">{graceDays} dias</strong>, a eliminação é cancelada.</li>
            <li>Depois disso, o nome, telefone, email, foto, localização, favoritos, alertas, comentários e mensagens são apagados.</li>
            <li>Os pedidos de gás e pagamentos ficam guardados sem os seus dados pessoais (registos financeiros obrigatórios).</li>
            <li>Pode voltar a criar uma conta com o mesmo número.</li>
          </ul>

          {status.isLoading && <p className="text-gray">A verificar a sua conta...</p>}
          {blockers.length > 0 && (
            <div role="alert" className="rounded-lg bg-warning/10 p-3 text-dark">
              <p className="font-semibold">Ainda não pode eliminar a conta:</p>
              <ul className="mt-1 list-disc pl-5">{blockers.map((b) => <li key={b}>{b}</li>)}</ul>
            </div>
          )}

          {status.isSuccess && blockers.length === 0 && (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                mutation.mutate({ current_password: password, confirmation, reason: reason.trim() || undefined })
              }}
              className="space-y-3"
            >
              <Field label="Porque está a sair? (opcional)" htmlFor="del-reason">
                <textarea id="del-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} rows={2} className="w-full rounded-lg border border-gray/20 px-3 py-2" />
              </Field>
              <Field label="Palavra-passe atual" htmlFor="del-password" error={errors.current_password}>
                <input id="del-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="w-full rounded-lg border border-gray/20 px-3 py-2" />
              </Field>
              <Field label="Escreva ELIMINAR para confirmar" htmlFor="del-confirm" error={errors.confirmation}>
                <input id="del-confirm" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} autoCapitalize="characters" className="w-full rounded-lg border border-gray/20 px-3 py-2" />
              </Field>
              {mutation.isError && !errors.current_password && !errors.confirmation && (
                <p role="alert" className="text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível eliminar a conta.')}</p>
              )}
              <div className="flex flex-wrap gap-2">
                <button
                  type="submit"
                  disabled={!password || confirmation !== 'ELIMINAR' || mutation.isPending}
                  className="rounded-lg bg-danger px-4 py-2 font-semibold text-white disabled:opacity-50"
                >
                  {mutation.isPending ? 'A eliminar...' : 'Eliminar a minha conta'}
                </button>
                <button type="button" onClick={() => setOpen(false)} className="rounded-lg border border-gray/20 px-4 py-2 text-dark">Cancelar</button>
              </div>
            </form>
          )}
        </div>
      )}
    </section>
  )
}

function PersonalDataCard({ user }: { user: AuthUser }) {
  const [name, setName] = useState(user.name)
  const [phone, setPhone] = useState(user.phone)
  const [email, setEmail] = useState(user.email ?? '')
  const [currentPassword, setCurrentPassword] = useState('')
  const [saved, setSaved] = useState(false)
  const mutation = useSaveProfile()
  const errors = fieldErrors(mutation.error)

  const phoneChanged = digits(phone) !== digits(user.phone)
  const emailChanged = email.trim() !== (user.email ?? '')
  const needsPassword = phoneChanged || emailChanged
  const dirty = name.trim() !== user.name || phoneChanged || emailChanged
  const valid = name.trim().length >= 2 && digits(phone).length === 9 && (!needsPassword || currentPassword !== '')

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!valid || !dirty) return
    setSaved(false)
    const payload: ProfilePayload = { name: name.trim() }
    if (phoneChanged) payload.phone = phone
    if (emailChanged) payload.email = email.trim() === '' ? null : email.trim()
    if (needsPassword) payload.current_password = currentPassword
    mutation.mutate(payload, {
      onSuccess: () => { setSaved(true); setCurrentPassword('') },
    })
  }

  return (
    <Card icon={UserCircle2} title="Dados pessoais">
      <form onSubmit={submit} className="space-y-3" noValidate>
        <Field label="Nome completo" htmlFor="p-name" error={errors.name}>
          <input id="p-name" value={name} maxLength={150} autoComplete="name" onChange={(e) => setName(e.target.value)} className={inputCls} />
        </Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Telemóvel" htmlFor="p-phone" error={errors.phone} hint="É com ele que entra na conta.">
            <input id="p-phone" value={phone} inputMode="tel" autoComplete="tel" onChange={(e) => setPhone(e.target.value)} className={inputCls} />
          </Field>
          <Field label="Email (opcional)" htmlFor="p-email" error={errors.email}>
            <input id="p-email" type="email" value={email} autoComplete="email" onChange={(e) => setEmail(e.target.value)} className={inputCls} />
          </Field>
        </div>
        {needsPassword && (
          <Field label="Palavra-passe atual" htmlFor="p-current" error={errors.current_password} hint="Por segurança, é pedida para mudar o telemóvel ou o email.">
            <input id="p-current" type="password" value={currentPassword} autoComplete="current-password" onChange={(e) => setCurrentPassword(e.target.value)} className={inputCls} />
          </Field>
        )}
        <SaveRow disabled={!dirty || !valid || mutation.isPending} pending={mutation.isPending} saved={saved && !dirty} error={mutation.isError && Object.keys(errors).length === 0 ? getApiErrorMessage(mutation.error, 'Não foi possível guardar.') : null} />
      </form>
    </Card>
  )
}

function LocationCard({ user }: { user: AuthUser }) {
  const locked = LOCATION_LOCKED.includes(user.role)
  const [provinceId, setProvinceId] = useState<number | ''>(user.province_id ?? '')
  const [municipalityId, setMunicipalityId] = useState<number | ''>(user.municipality_id ?? '')
  const [neighborhoodId, setNeighborhoodId] = useState<number | ''>(user.neighborhood_id ?? '')
  const [coords, setCoords] = useState<{ lat: number; lng: number } | null>(
    user.latitude != null && user.longitude != null ? { lat: Number(user.latitude), lng: Number(user.longitude) } : null,
  )
  const [geoError, setGeoError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const mutation = useSaveProfile()
  const errors = fieldErrors(mutation.error)

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(Number(provinceId)),
    enabled: !!provinceId,
  })

  // Bairro (Vizinhos KeruGás, Outubro 2026): opcional; os do município escolhido.
  const neighborhoodsQuery = useQuery({
    queryKey: ['neighborhoods', municipalityId],
    queryFn: () => vizinhosService.neighborhoods(Number(municipalityId)),
    enabled: !!municipalityId,
  })

  const original = useMemo(() => ({
    province: user.province_id ?? '', municipality: user.municipality_id ?? '', neighborhood: user.neighborhood_id ?? '',
    lat: user.latitude != null ? Number(user.latitude) : null, lng: user.longitude != null ? Number(user.longitude) : null,
  }), [user.province_id, user.municipality_id, user.neighborhood_id, user.latitude, user.longitude])

  const coordsChanged = (coords?.lat ?? null) !== original.lat || (coords?.lng ?? null) !== original.lng
  const placeChanged = !locked && (provinceId !== original.province || municipalityId !== original.municipality)
  const neighborhoodChanged = neighborhoodId !== original.neighborhood
  const dirty = coordsChanged || placeChanged || neighborhoodChanged
  const valid = locked || (!!provinceId && !!municipalityId)

  useEffect(() => { if (dirty) setSaved(false) }, [dirty])

  const useMyLocation = () => {
    setGeoError(null)
    if (!('geolocation' in navigator)) {
      setGeoError('Este dispositivo não permite obter a localização.')
      return
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => setCoords({ lat: Number(pos.coords.latitude.toFixed(7)), lng: Number(pos.coords.longitude.toFixed(7)) }),
      () => setGeoError('Não foi possível obter a localização. Verifique a permissão de localização.'),
      { enableHighAccuracy: true, timeout: 15000 },
    )
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!dirty || !valid) return
    const payload: ProfilePayload = {}
    if (placeChanged) {
      payload.province_id = provinceId || null
      payload.municipality_id = municipalityId || null
    }
    if (neighborhoodChanged || placeChanged) payload.neighborhood_id = neighborhoodId || null
    if (coordsChanged) {
      payload.latitude = coords?.lat ?? null
      payload.longitude = coords?.lng ?? null
    }
    mutation.mutate(payload, { onSuccess: () => setSaved(true) })
  }

  return (
    <Card icon={MapPin} title="Localização">
      <form onSubmit={submit} className="space-y-3" noValidate>
        {locked && (
          <p className="rounded-lg bg-light p-3 text-xs text-gray">
            A sua província{user.role === 'DRIVER' ? ' e município definem os pedidos que recebe' : ' define a sua área de trabalho'},só o Super Admin as pode alterar.
          </p>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Província" htmlFor="p-province" error={errors.province_id}>
            <select
              id="p-province"
              value={provinceId}
              disabled={locked}
              onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : ''); setMunicipalityId('') }}
              className={inputCls}
            >
              <option value="">Selecione...</option>
              {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </Field>
          <Field label="Município" htmlFor="p-municipality" error={errors.municipality_id}>
            <select
              id="p-municipality"
              value={municipalityId}
              disabled={locked || !provinceId}
              onChange={(e) => { setMunicipalityId(e.target.value ? Number(e.target.value) : ''); setNeighborhoodId('') }}
              className={inputCls}
            >
              <option value="">Selecione...</option>
              {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
          </Field>
        </div>
        <Field label="Bairro (opcional)" htmlFor="p-neighborhood" error={errors.neighborhood_id} hint="Aparece aos seus vizinhos nos Vizinhos KeruGás e ajuda a filtrar conversas.">
          <select
            id="p-neighborhood"
            value={neighborhoodId}
            disabled={!municipalityId}
            onChange={(e) => setNeighborhoodId(e.target.value ? Number(e.target.value) : '')}
            className={inputCls}
          >
            <option value="">Não indicar</option>
            {neighborhoodsQuery.data?.map((n) => <option key={n.id} value={n.id}>{n.name}</option>)}
          </select>
        </Field>

        <div>
          <p className="text-xs font-medium text-gray">Localização exata da sua casa (opcional)</p>
          <p className="text-xs text-gray">Ajuda o condutor a chegar quando pede gás. Só é usada nas suas entregas.</p>
          <div className="mt-2 flex flex-wrap gap-2">
            <button type="button" onClick={useMyLocation} className="flex items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-1.5 text-sm font-medium text-primary">
              <LocateFixed size={14} /> {coords ? 'Atualizar com a minha localização' : 'Usar a minha localização atual'}
            </button>
            {coords && (
              <button type="button" onClick={() => setCoords(null)} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">
                Remover localização exata
              </button>
            )}
          </div>
          {geoError && <p className="mt-1 text-xs text-danger">{geoError}</p>}
          {coords && (
            <div className="mt-3 overflow-hidden rounded-lg">
              <LocationPicker latitude={coords.lat} longitude={coords.lng} onChange={(lat, lng) => setCoords({ lat, lng })} />
              <p className="mt-1 text-xs text-gray">Pode arrastar o marcador para o ponto certo.</p>
            </div>
          )}
          {(errors.latitude || errors.longitude) && <p className="mt-1 text-xs text-danger">{errors.latitude ?? errors.longitude}</p>}
        </div>

        <SaveRow disabled={!dirty || !valid || mutation.isPending} pending={mutation.isPending} saved={saved && !dirty} error={mutation.isError && Object.keys(errors).length === 0 ? getApiErrorMessage(mutation.error, 'Não foi possível guardar.') : null} />
      </form>
    </Card>
  )
}

function PasswordCard() {
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState(false)
  const [done, setDone] = useState<string | null>(null)
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: () => profileService.changePassword({ current_password: current, password, password_confirmation: confirm }),
    onSuccess: (r) => {
      setCurrent(''); setPassword(''); setConfirm('')
      setDone(r.other_sessions_ended > 0
        ? `Palavra-passe alterada. ${r.other_sessions_ended} outra(s) sessão(ões) foram terminadas.`
        : 'Palavra-passe alterada.')
      void queryClient.invalidateQueries({ queryKey: ['profile-sessions'] })
    },
  })
  const errors = fieldErrors(mutation.error)

  const rules = [
    { ok: password.length >= 8, label: 'Pelo menos 8 caracteres' },
    { ok: /\p{L}/u.test(password), label: 'Letras' },
    { ok: /\d/.test(password), label: 'Números' },
    { ok: password !== '' && password === confirm, label: 'Confirmação igual' },
    { ok: password !== '' && password !== current, label: 'Diferente da atual' },
  ]
  const valid = current !== '' && rules.every((r) => r.ok)
  const type = show ? 'text' : 'password'

  return (
    <Card icon={Lock} title="Palavra-passe">
      <form
        onSubmit={(e) => { e.preventDefault(); if (valid) { setDone(null); mutation.mutate() } }}
        className="space-y-3"
        noValidate
      >
        <Field label="Palavra-passe atual" htmlFor="pw-current" error={errors.current_password}>
          <input id="pw-current" type={type} value={current} autoComplete="current-password" onChange={(e) => setCurrent(e.target.value)} className={inputCls} />
        </Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Nova palavra-passe" htmlFor="pw-new" error={errors.password}>
            <input id="pw-new" type={type} value={password} autoComplete="new-password" onChange={(e) => setPassword(e.target.value)} className={inputCls} />
          </Field>
          <Field label="Repita a nova palavra-passe" htmlFor="pw-confirm">
            <input id="pw-confirm" type={type} value={confirm} autoComplete="new-password" onChange={(e) => setConfirm(e.target.value)} className={inputCls} />
          </Field>
        </div>
        <button type="button" onClick={() => setShow((v) => !v)} className="flex items-center gap-1.5 text-xs font-medium text-gray">
          {show ? <EyeOff size={13} /> : <Eye size={13} />} {show ? 'Esconder' : 'Mostrar'} palavras-passe
        </button>
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Regras da palavra-passe">
          {rules.map((r) => (
            <li key={r.label} className={r.ok ? 'text-success' : 'text-gray'}>{r.ok ? '✓' : '•'} {r.label}</li>
          ))}
        </ul>
        <p className="text-xs text-gray">Ao mudar, as sessões abertas noutros telemóveis e navegadores terminam; esta continua aberta.</p>
        <div className="flex flex-wrap items-center gap-3">
          <button type="submit" disabled={!valid || mutation.isPending} className="fx-btn-fire rounded-xl px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60">
            {mutation.isPending ? 'A alterar...' : 'Alterar palavra-passe'}
          </button>
          {done && <span role="status" className="text-sm text-success">{done}</span>}
          {mutation.isError && Object.keys(errors).length === 0 && (
            <span className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível alterar a palavra-passe.')}</span>
          )}
        </div>
      </form>
    </Card>
  )
}

function SessionsCard() {
  const queryClient = useQueryClient()
  const { data } = useQuery({ queryKey: ['profile-sessions'], queryFn: profileService.sessions })
  const mutation = useMutation({
    mutationFn: profileService.endOtherSessions,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['profile-sessions'] }),
  })
  const others = (data ?? []).filter((s) => !s.current).length

  return (
    <Card icon={Smartphone} title="Sessões abertas">
      {!data ? <p className="text-sm text-gray">A carregar...</p> : (
        <ul className="space-y-2">
          {data.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <span className="text-dark">
                {s.name === 'kerugas-web' ? 'Sessão' : s.name}
                {s.current && <span className="ml-2 rounded-full bg-success/10 px-2 py-0.5 text-xs text-success">este dispositivo</span>}
              </span>
              <span className="text-xs text-gray">
                {s.last_used_at ? `usada ${new Date(s.last_used_at).toLocaleString('pt-PT')}` : s.created_at ? `aberta ${new Date(s.created_at).toLocaleString('pt-PT')}` : ''}
              </span>
            </li>
          ))}
        </ul>
      )}
      <div className="mt-3 flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={() => mutation.mutate()}
          disabled={others === 0 || mutation.isPending}
          className="rounded-lg border border-danger/40 px-3 py-1.5 text-sm font-medium text-danger disabled:opacity-50"
        >
          Terminar as outras sessões{others > 0 ? ` (${others})` : ''}
        </button>
        {mutation.isSuccess && <span role="status" className="text-sm text-success">Outras sessões terminadas.</span>}
        {mutation.isError && <span className="text-sm text-danger">Não foi possível terminar as sessões.</span>}
      </div>
    </Card>
  )
}

const inputCls = 'fx-input mt-1 w-full rounded-xl border border-gray/20 bg-white px-3 py-2.5 text-sm text-dark disabled:cursor-not-allowed disabled:opacity-60'

function Card({ icon: Icon, title, children }: { icon: typeof Lock; title: string; children: ReactNode }) {
  return (
    <Reveal>
      <section aria-label={title} className="fx-glass rounded-2xl p-5">
        <h2 className="mb-4 flex items-center gap-2.5 font-semibold text-dark">
          <span className="fx-bubble h-8 w-8" aria-hidden="true"><Icon size={15} /></span> {title}
        </h2>
        {children}
      </section>
    </Reveal>
  )
}

function Field({ label, htmlFor, error, hint, children }: { label: string; htmlFor: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="block text-xs font-medium text-gray">{label}</label>
      {children}
      {error ? <p className="mt-1 text-xs text-danger">{error}</p> : hint ? <p className="mt-1 text-xs text-gray">{hint}</p> : null}
    </div>
  )
}

function SaveRow({ disabled, pending, saved, error }: { disabled: boolean; pending: boolean; saved: boolean; error: string | null }) {
  return (
    <div className="flex flex-wrap items-center gap-3">
      <button type="submit" disabled={disabled} className="fx-btn-fire rounded-xl px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60">
        {pending ? 'A guardar...' : 'Guardar'}
      </button>
      {saved && <span role="status" className="fx-pop flex items-center gap-1 text-sm text-success"><Check size={14} /> Guardado</span>}
      {error && <span className="text-sm text-danger">{error}</span>}
    </div>
  )
}

/** Conversa com a Central (Outubro 2026): só aparece depois de a direção escrever. */
function CentralChatLink() {
  const central = useCentralChat()
  if (!central.available) return null

  return (
    <Link to="/conversa-central" className="fx-row flex items-center gap-2 rounded-2xl border border-gray/15 bg-white p-4 text-sm font-medium text-dark">
      <Headset size={16} className="text-primary" /> Conversa com a Central
      {central.unread > 0 && <span className="ml-auto rounded-full bg-primary px-2 text-xs font-bold text-white">{central.unread}</span>}
    </Link>
  )
}
