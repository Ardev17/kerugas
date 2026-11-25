import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { Eye, EyeOff, MailCheck } from 'lucide-react'
import { authService } from '@/services/authService'
import { getApiErrorMessage } from '@/lib/api'
import { fieldErrors } from '@/services/profileService'
import { HoneypotField } from '@/components/HoneypotField'
import { useHoneypot } from '@/hooks/useHoneypot'

const RESEND_SECONDS = 60

/**
 * Recuperar a palavra-passe (Outubro 2026),pensado para a app: pede o
 * email, envia um código de 6 dígitos e a pessoa escreve-o AQUI, com a nova
 * palavra-passe, sem sair para o navegador. O link do mesmo email continua a
 * servir no computador (/reset-password).
 */
export default function ForgotPassword() {
  const navigate = useNavigate()
  const [step, setStep] = useState<'email' | 'code' | 'done'>('email')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [show, setShow] = useState(false)
  const [cooldown, setCooldown] = useState(0)
  const honeypot = useHoneypot()

  useEffect(() => {
    if (cooldown <= 0) return
    const t = window.setTimeout(() => setCooldown((c) => c - 1), 1000)
    return () => window.clearTimeout(t)
  }, [cooldown])

  const send = useMutation({
    mutationFn: () => authService.forgotPassword(email.trim(), honeypot.website),
    onSuccess: () => {
      setStep('code')
      setCooldown(RESEND_SECONDS)
    },
  })

  const reset = useMutation({
    mutationFn: () => authService.resetPassword({ email: email.trim(), code, password, password_confirmation: confirmation }),
    onSuccess: () => setStep('done'),
  })

  const errors = fieldErrors(reset.error)
  const strong = password.length >= 8 && /[A-Za-zÀ-ÿ]/.test(password) && /\d/.test(password)
  const canReset = /^\d{6}$/.test(code) && strong && password === confirmation && !reset.isPending

  const onSend = (e: FormEvent) => {
    e.preventDefault()
    send.mutate()
  }
  const onReset = (e: FormEvent) => {
    e.preventDefault()
    if (canReset) reset.mutate()
  }

  return (
    <div className="mx-auto max-w-sm px-4 py-16">
      <h1 className="text-2xl font-bold text-dark">Recuperar palavra-passe</h1>

      {step === 'email' && (
        <>
          <p className="mt-1 text-sm text-gray">Escreva o email da sua conta. Enviamos um código de 6 dígitos para repor a palavra-passe.</p>
          <form onSubmit={onSend} className="mt-6 space-y-4">
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">Email</span>
              <input
                type="email"
                required
                autoComplete="email"
                inputMode="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-sm"
              />
            </label>
            {send.isError && <p role="alert" className="text-sm text-danger">{getApiErrorMessage(send.error, 'Não foi possível enviar o código. Tente de novo.')}</p>}
            <button type="submit" disabled={send.isPending} className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60">
              {send.isPending ? 'A enviar...' : 'Enviar código'}
            </button>
            <HoneypotField {...honeypot.fieldProps} />
          </form>
          <p className="mt-6 rounded-lg bg-light p-3 text-xs text-gray">
            A sua conta não tem email? Fale connosco na página de <Link to="/contacto" className="font-medium text-primary">Contacto</Link>, a partir do número da conta, e ajudamos a recuperar o acesso.
          </p>
        </>
      )}

      {step === 'code' && (
        <>
          <p className="mt-1 flex gap-2 text-sm text-gray">
            <MailCheck size={18} className="mt-0.5 shrink-0 text-primary" />
            <span>Se existir uma conta com <strong className="text-dark">{email.trim()}</strong>, enviámos um código. Veja também a pasta de spam.</span>
          </p>
          <form onSubmit={onReset} className="mt-6 space-y-4">
            <div className="text-sm">
              <label htmlFor="fp-code" className="mb-1 block font-medium text-dark">Código de 6 dígitos</label>
              <input
                id="fp-code"
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                inputMode="numeric"
                autoComplete="one-time-code"
                placeholder="000000"
                aria-invalid={!!errors.code}
                className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-center font-mono text-xl tracking-[0.4em]"
              />
              {errors.code && <span className="mt-1 block text-xs text-danger">{errors.code}</span>}
            </div>
            <div className="text-sm">
              <label htmlFor="fp-password" className="mb-1 block font-medium text-dark">Nova palavra-passe</label>
              <span className="relative block">
                <input
                  id="fp-password"
                  aria-describedby="fp-password-hint"
                  type={show ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full rounded-lg border border-gray/20 px-3 py-2.5 pr-10 text-sm"
                />
                <button type="button" onClick={() => setShow(!show)} aria-label={show ? 'Esconder palavra-passe' : 'Mostrar palavra-passe'} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray">
                  {show ? <EyeOff size={16} /> : <Eye size={16} />}
                </button>
              </span>
              <span id="fp-password-hint" className={`mt-1 block text-xs ${password && !strong ? 'text-danger' : 'text-gray'}`}>Pelo menos 8 caracteres, com letras e números.</span>
              {errors.password && <span className="mt-1 block text-xs text-danger">{errors.password}</span>}
            </div>
            <div className="text-sm">
              <label htmlFor="fp-confirm" className="mb-1 block font-medium text-dark">Confirmar nova palavra-passe</label>
              <input
                id="fp-confirm"
                type={show ? 'text' : 'password'}
                autoComplete="new-password"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-sm"
              />
              {confirmation && confirmation !== password && <span className="mt-1 block text-xs text-danger">As palavras-passe não coincidem.</span>}
            </div>
            {reset.isError && !errors.code && !errors.password && (
              <p role="alert" className="text-sm text-danger">{getApiErrorMessage(reset.error, 'Não foi possível repor a palavra-passe.')}</p>
            )}
            <button type="submit" disabled={!canReset} className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60">
              {reset.isPending ? 'A repor...' : 'Repor palavra-passe'}
            </button>
          </form>
          <div className="mt-4 flex items-center justify-between text-sm">
            <button type="button" onClick={() => { setStep('email'); reset.reset() }} className="text-gray">Mudar email</button>
            <button type="button" disabled={cooldown > 0 || send.isPending} onClick={() => send.mutate()} className="font-medium text-primary disabled:text-gray">
              {cooldown > 0 ? `Reenviar código (${cooldown}s)` : 'Reenviar código'}
            </button>
          </div>
        </>
      )}

      {step === 'done' && (
        <div className="mt-6 space-y-4">
          <p role="status" className="rounded-lg bg-success/10 p-4 text-sm text-success">
            Palavra-passe reposta. Por segurança, todas as sessões abertas foram terminadas.
          </p>
          <button type="button" onClick={() => navigate('/login', { replace: true })} className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white">
            Entrar
          </button>
        </div>
      )}
    </div>
  )
}
