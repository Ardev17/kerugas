import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { AnimatePresence, motion } from 'framer-motion'
import { BellRing, X } from 'lucide-react'
import { onForegroundPush, type ForegroundPush } from '@/native/push'
import { safeInternalPath } from '@/native/deepLinks'
import { successFeedback } from '@/native/haptics'

/**
 * Uma push que chega com o app ABERTO não aparece na barra de
 * notificações do Android,mostra-se aqui, no topo do ecrã, e toca-se
 * para abrir o ecrã certo. Atualiza logo o sino e as listas afetadas.
 */
export function PushToast() {
  const [push, setPush] = useState<ForegroundPush | null>(null)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  useEffect(() => {
    return onForegroundPush((incoming) => {
      setPush(incoming)
      void successFeedback()
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
      // Um condutor com o painel aberto vê o pedido novo sem recarregar.
      void queryClient.invalidateQueries({ queryKey: ['driver-orders'] })
      void queryClient.invalidateQueries({ queryKey: ['admin-orders'] })
      void queryClient.invalidateQueries({ queryKey: ['my-orders'] })
      void queryClient.invalidateQueries({ queryKey: ['order-tracking'] })
    })
  }, [queryClient])

  useEffect(() => {
    if (!push) return
    const timer = window.setTimeout(() => setPush(null), 6000)
    return () => window.clearTimeout(timer)
  }, [push])

  const open = () => {
    const path = push ? safeInternalPath(push.path) : null
    setPush(null)
    if (path) navigate(path)
  }

  return (
    <AnimatePresence>
      {push && (
        <motion.div
          initial={{ y: -80, opacity: 0 }}
          animate={{ y: 0, opacity: 1 }}
          exit={{ y: -80, opacity: 0 }}
          transition={{ type: 'spring', stiffness: 380, damping: 30 }}
          className="native-toast fixed inset-x-3 z-[60] mx-auto max-w-lg"
        >
          <div className="flex items-start gap-3 rounded-2xl border border-gray/10 bg-white p-4 shadow-xl">
            <button onClick={open} className="flex flex-1 items-start gap-3 text-left">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                <BellRing size={18} />
              </span>
              <span className="min-w-0">
                <span className="block text-sm font-semibold text-dark">{push.title}</span>
                <span className="mt-0.5 line-clamp-2 block text-sm text-gray">{push.body}</span>
              </span>
            </button>
            <button onClick={() => setPush(null)} aria-label="Fechar" className="text-gray">
              <X size={18} />
            </button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
