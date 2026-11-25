import { useEffect, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'
import { WifiOff } from 'lucide-react'

/**
 * Aviso de "sem internet" no app (Setembro 2026). Em Angola a ligação
 * móvel cai com frequência,sem este aviso, a pessoa via botões que
 * "não fazem nada" e achava que o app estava avariado. O estado vem do
 * plugin de rede nativo (ver watchNetwork em bootstrap.ts).
 */
export function OfflineBanner() {
  const [offline, setOffline] = useState(() => document.documentElement.classList.contains('offline'))

  useEffect(() => {
    const handler = (event: Event) => {
      setOffline(!(event as CustomEvent<{ connected: boolean }>).detail.connected)
    }
    window.addEventListener('kerugas:network', handler)
    return () => window.removeEventListener('kerugas:network', handler)
  }, [])

  return (
    <AnimatePresence>
      {offline && (
        <motion.div
          role="status"
          initial={{ height: 0, opacity: 0 }}
          animate={{ height: 'auto', opacity: 1 }}
          exit={{ height: 0, opacity: 0 }}
          className="overflow-hidden bg-dark text-white"
        >
          <p className="flex items-center justify-center gap-2 px-4 py-2 text-xs font-medium">
            <WifiOff size={14} /> Sem ligação à internet,a mostrar a última informação carregada.
          </p>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
