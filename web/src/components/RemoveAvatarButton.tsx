import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ImageOff } from 'lucide-react'
import { avatarService } from '@/services/avatarService'
import { getApiErrorMessage } from '@/lib/api'

/**
 * Moderação (Outubro 2026): remover uma foto de perfil imprópria. Pede
 * confirmação; o servidor decide se esta conta pode (âmbito e hierarquia),
 * avisa a pessoa e regista na auditoria.
 */
export function RemoveAvatarButton({ userId, userName, onRemoved }: { userId: number; userName: string; onRemoved?: () => void }) {
  const [confirming, setConfirming] = useState(false)
  const mutation = useMutation({
    mutationFn: () => avatarService.removeForUser(userId),
    onSuccess: () => {
      setConfirming(false)
      onRemoved?.()
    },
  })

  if (!confirming) {
    return (
      <span className="inline-flex flex-col">
        <button
          type="button"
          onClick={() => setConfirming(true)}
          className="inline-flex items-center gap-1.5 rounded-lg border border-gray/20 px-2.5 py-1.5 text-xs font-medium text-dark hover:bg-light"
          aria-label={`Remover a foto de perfil de ${userName}`}
        >
          <ImageOff size={13} /> Remover foto
        </button>
        {mutation.isError && <span role="alert" className="mt-1 text-xs text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível remover a foto.')}</span>}
      </span>
    )
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-2 text-xs">
      Remover a foto de {userName}?
      <button type="button" onClick={() => mutation.mutate()} disabled={mutation.isPending} className="rounded-lg bg-danger px-2.5 py-1.5 font-semibold text-white disabled:opacity-50">
        {mutation.isPending ? 'A remover...' : 'Remover'}
      </button>
      <button type="button" onClick={() => setConfirming(false)} className="rounded-lg border border-gray/20 px-2.5 py-1.5 text-dark">
        Cancelar
      </button>
    </span>
  )
}
