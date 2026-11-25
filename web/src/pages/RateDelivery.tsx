import { useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { motion } from 'framer-motion'
import { Star, CheckCircle2 } from 'lucide-react'
import { gasOrderService } from '@/services/gasOrderService'
import { getApiErrorMessage } from '@/lib/api'
import { readActiveOrder } from '@/lib/activeOrder'
import type { DriverRatingStatus } from '@/types'

/**
 * Avaliação do condutor (pedido explícito do cliente, Setembro 2026).
 * Página PÚBLICA (sem login,ver DriverRatingController): o cliente
 * chega aqui pela referência do pedido (ex: link enviado por SMS) e
 * confirma o seu telefone para provar que é dele,o mesmo nível de
 * verificação já usado noutros fluxos por telefone nesta app.
 *
 * Fluxo em dois passos: 1) telefone -> consulta o estado (elegível?
 * já avaliado?); 2) se elegível, estrelas + comentário opcional.
 */
export default function RateDelivery() {
  const { reference } = useParams<{ reference: string }>()
  const location = useLocation()
  const queryClient = useQueryClient()
  // Telefone já conhecido (vindo do botão/pedido, ou guardado neste
  // aparelho ao fazer o pedido): poupa o cliente a escrevê-lo.
  const knownPhone =
    (location.state as { phone?: string } | null)?.phone ??
    (readActiveOrder()?.reference === reference ? readActiveOrder()?.phone : undefined) ??
    ''
  const [phone, setPhone] = useState(knownPhone)
  const [status, setStatus] = useState<DriverRatingStatus | null>(null)
  const [rating, setRating] = useState(0)
  const [hoverRating, setHoverRating] = useState(0)
  const [comment, setComment] = useState('')

  const lookupMutation = useMutation({
    mutationFn: () => gasOrderService.getRatingStatus(reference!, phone),
    onSuccess: (data) => setStatus(data),
  })

  const submitMutation = useMutation({
    mutationFn: () => gasOrderService.submitRating(reference!, { customer_phone: phone, rating, comment: comment.trim() || undefined }),
    // Já avaliou: o botão "Avaliar o condutor" desaparece.
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['active-order'] })
      void queryClient.invalidateQueries({ queryKey: ['my-orders'] })
    },
  })

  if (!reference) return null

  if (submitMutation.isSuccess) {
    return (
      <div className="mx-auto flex max-w-lg flex-col items-center px-4 py-16 text-center">
        <motion.div initial={{ scale: 0.8, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} transition={{ duration: 0.35, ease: 'easeOut' }}>
          <CheckCircle2 size={56} className="text-success" aria-hidden="true" />
        </motion.div>
        <h1 className="mt-4 text-2xl font-bold text-dark">Obrigado pela sua avaliação!</h1>
        <p className="mt-2 text-gray">A sua opinião ajuda-nos a melhorar o serviço de entrega.</p>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-lg px-4 py-12">
      <h1 className="text-xl font-bold text-dark">Avaliar a entrega</h1>
      <p className="mt-1 text-sm text-gray">Pedido {reference}</p>

      {!status && (
        <div className="mt-6 rounded-xl border border-gray/15 bg-white p-5">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">O seu número de telefone</span>
            <input
              type="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="923123456"
              className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
            />
          </label>
          <p className="mt-1 text-xs text-gray">O mesmo número usado para fazer este pedido de gás.</p>

          {lookupMutation.isError && (
            <p className="mt-3 text-sm text-danger">
              {getApiErrorMessage(lookupMutation.error, 'Não foi possível verificar o pedido. Confirme a referência e o telefone.')}
            </p>
          )}

          <button
            onClick={() => lookupMutation.mutate()}
            disabled={phone.trim() === '' || lookupMutation.isPending}
            className="mt-4 w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {lookupMutation.isPending ? 'A verificar...' : 'Continuar'}
          </button>
        </div>
      )}

      {status && status.already_rated && (
        <div className="mt-6 rounded-xl border border-gray/15 bg-light p-5 text-center">
          <p className="font-medium text-dark">Este pedido já foi avaliado.</p>
          {status.rating && (
            <div className="mt-2 flex justify-center gap-1">
              {[1, 2, 3, 4, 5].map((n) => (
                <Star key={n} size={22} className={n <= status.rating! ? 'fill-amber-400 text-amber-400' : 'text-gray/30'} />
              ))}
            </div>
          )}
          {status.comment && <p className="mt-3 text-sm text-gray">"{status.comment}"</p>}
        </div>
      )}

      {status && !status.already_rated && !status.eligible && (
        <div className="mt-6 rounded-xl border border-gray/15 bg-light p-5 text-center">
          <p className="text-dark">{status.ineligible_reason ?? 'Este pedido ainda não pode ser avaliado.'}</p>
        </div>
      )}

      {status && !status.already_rated && status.eligible && (
        <div className="mt-6 rounded-xl border border-gray/15 bg-white p-5">
          {status.driver_name && <p className="text-sm text-gray">Condutor: <span className="font-medium text-dark">{status.driver_name}</span></p>}

          <p className="mt-3 text-sm font-medium text-dark">Como avalia a entrega?</p>
          <div className="mt-2 flex gap-1">
            {[1, 2, 3, 4, 5].map((n) => (
              <button
                key={n}
                type="button"
                onClick={() => setRating(n)}
                onMouseEnter={() => setHoverRating(n)}
                onMouseLeave={() => setHoverRating(0)}
                aria-label={`${n} estrela${n > 1 ? 's' : ''}`}
                className="p-0.5"
              >
                <Star size={32} className={n <= (hoverRating || rating) ? 'fill-amber-400 text-amber-400' : 'text-gray/30'} />
              </button>
            ))}
          </div>

          <label className="mt-4 block text-sm">
            <span className="mb-1 block font-medium text-dark">Comentário (opcional)</span>
            <textarea
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              maxLength={500}
              rows={3}
              className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
              placeholder="Diga-nos como correu a entrega..."
            />
          </label>

          {submitMutation.isError && (
            <p className="mt-3 text-sm text-danger">
              {getApiErrorMessage(submitMutation.error, 'Não foi possível enviar a avaliação. Tente novamente.')}
            </p>
          )}

          <button
            onClick={() => submitMutation.mutate()}
            disabled={rating === 0 || submitMutation.isPending}
            className="mt-4 w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {submitMutation.isPending ? 'A enviar...' : 'Enviar avaliação'}
          </button>
        </div>
      )}
    </div>
  )
}
