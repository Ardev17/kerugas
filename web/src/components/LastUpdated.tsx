import { useEffect, useState } from 'react'
import { lastUpdateLabel } from '@/lib/timeAgo'

/** "Última atualização há 5 horas e 9 minutos",recalcula sozinho a cada 30 s. */
export function LastUpdated({ at }: { at: string | null | undefined }) {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 30_000)
    return () => window.clearInterval(id)
  }, [])

  return <>{lastUpdateLabel(at, now)}</>
}
