import { useState } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { authService } from '@/services/authService'

export default function ResetPassword() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')

  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''

  const mutation = useMutation({
    mutationFn: () => authService.resetPassword({ token, email, password, password_confirmation: confirmation }),
    onSuccess: () => navigate('/login', { replace: true }),
  })

  return (
    <div className="mx-auto max-w-sm px-4 py-16">
      <h1 className="text-2xl font-bold text-dark">Redefinir password</h1>

      <form onSubmit={(e) => { e.preventDefault(); mutation.mutate() }} className="mt-6 space-y-4">
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Nova password</span>
          <input
            type="password"
            required
            minLength={8}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-sm"
          />
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Confirmar nova password</span>
          <input
            type="password"
            required
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
            className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-sm"
          />
        </label>

        {mutation.isError && (
          <p className="text-sm text-danger">Não foi possível redefinir a password. O link pode ter expirado.</p>
        )}

        <button
          type="submit"
          disabled={mutation.isPending}
          className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
        >
          {mutation.isPending ? 'A guardar...' : 'Redefinir password'}
        </button>
      </form>
    </div>
  )
}
