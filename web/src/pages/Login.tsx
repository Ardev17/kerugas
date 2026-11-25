import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { authService } from '@/services/authService'
import { useAuthStore } from '@/stores/authStore'
import { safeInternalPath } from '@/native/deepLinks'
import { AuthShell } from '@/components/fx/AuthShell'
import { getApiErrorMessage } from '@/lib/api'
import axios from 'axios'

const schema = z.object({
  identifier: z.string().min(3, 'Informe o seu telefone ou email.'),
  password: z.string().min(1, 'Informe a sua password.'),
})

type FormValues = z.infer<typeof schema>

export default function Login() {
  const navigate = useNavigate()
  const location = useLocation()
  const setSession = useAuthStore((s) => s.setSession)

  const { register, handleSubmit, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { identifier: '', password: '' },
    mode: 'onBlur',
    reValidateMode: 'onChange',
  })

  const mutation = useMutation({
    mutationFn: authService.login,
    onSuccess: ({ user, token }) => {
      setSession(user, token)
      // Segurança (Outubro 2026): o caminho de volta vem do URL que a
      // pessoa abriu,só aceitamos caminhos internos simples (nunca
      // "//site" nem "/\\site", vetor de open redirect do React Router 6).
      const rawFrom = (location.state as { from?: Location })?.from?.pathname
      const from = rawFrom ? safeInternalPath(rawFrom) ?? undefined : undefined
      // Condutor (Setembro 2026): não tem uso nenhum para o /dashboard
      // genérico de cliente,vai sempre direto para o seu painel,
      // exceto se veio de um link que já apontava para outro sítio
      // (ex: partilhou um link de /driver diretamente).
      const redirectTo = from ?? (user.role === 'DRIVER' || user.role === 'STATION_DRIVER' ? '/driver' : user.role === 'INVESTOR' ? '/investidor' : user.role === 'STATION_MANAGER' ? '/posto' : '/dashboard')
      navigate(redirectTo, { replace: true })
    },
  })

  return (
    <AuthShell title="Entrar" subtitle="Aceda à sua conta KERUGAS.">
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="fx-stagger mt-6 space-y-4">
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Telefone ou email</span>
          <input className="fx-input w-full rounded-xl border border-gray/20 px-3.5 py-3 text-sm" {...register('identifier')} />
          {errors.identifier && <span className="mt-1 block text-xs text-danger">{errors.identifier.message}</span>}
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Password</span>
          <input type="password" className="fx-input w-full rounded-xl border border-gray/20 px-3.5 py-3 text-sm" {...register('password')} />
          {errors.password && <span className="mt-1 block text-xs text-danger">{errors.password.message}</span>}
        </label>

        {/* Outubro 2026: a mensagem vem do servidor ("Credenciais inválidas." ou, depois de
            vários erros nesta rede, quanto tempo esperar),com atalho para recuperar. */}
        {mutation.isError && (
          <p role="alert" className="text-sm text-danger">
            {getApiErrorMessage(mutation.error, 'Não foi possível entrar. Verifique os dados e tente novamente.')}
            {axios.isAxiosError(mutation.error) && mutation.error.response?.status === 429 && (
              <>
                {' '}
                <Link to="/forgot-password" className="font-semibold underline">
                  Recuperar a palavra-passe
                </Link>
              </>
            )}
          </p>
        )}

        <button
          type="submit"
          disabled={mutation.isPending}
          className="fx-btn-fire w-full rounded-xl px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
        >
          {mutation.isPending ? 'A entrar...' : 'Entrar'}
        </button>
      </form>

      <div className="mt-4 flex justify-between text-sm">
        <Link to="/forgot-password" className="text-gray hover:text-primary">Esqueceu a password?</Link>
        <Link to="/register" className="font-medium text-primary">Criar conta</Link>
      </div>
    </AuthShell>
  )
}
