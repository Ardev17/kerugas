import { useQuery } from '@tanstack/react-query'
import { AlertTriangle } from 'lucide-react'
import { adminService } from '@/services/adminService'

/**
 * Entregas "por fora da central" (Outubro 2026): condutores com garrafas que
 * não aparecem em pedidos da plataforma. É um aviso para conferir o stock
 * físico e falar com o condutor,não uma prova.
 */
export function DriverStockAlerts() {
  const query = useQuery({ queryKey: ['admin-pickups', 'alerts'], queryFn: () => adminService.pickupAlerts(), retry: false })
  const alerts = query.data ?? []
  if (alerts.length === 0) return null

  return (
    <section aria-label="Garrafas por justificar" className="rounded-xl border border-amber-300 bg-amber-50 p-5">
      <h2 className="flex items-center gap-2 font-semibold text-amber-900">
        <AlertTriangle size={18} aria-hidden="true" /> Garrafas por justificar ({alerts.length})
      </h2>
      <p className="mt-1 text-xs text-amber-900/80">
        Estes condutores levaram garrafas dos postos que não aparecem em entregas feitas pela plataforma. Confira o stock físico e fale com o condutor.
      </p>
      <ul className="mt-3 space-y-2">
        {alerts.map((a) => (
          <li key={a.driver_id} className="rounded-lg bg-white p-3 text-sm">
            <p className="font-medium text-dark">{a.driver_name} <span className="font-normal text-gray">· {a.on_hand} no stock</span></p>
            <ul className="mt-1 list-disc pl-5 text-xs text-gray">
              {a.reasons.map((r) =>
                r.type === 'idle' ? (
                  <li key="idle">{r.bottles} garrafa(s) paradas há mais de 3 dias, sem pedido associado.</li>
                ) : (
                  <li key="low">Nos últimos 30 dias levou {r.picked} garrafas e entregou {r.delivered} com pedido ({r.percent} %).</li>
                ),
              )}
            </ul>
          </li>
        ))}
      </ul>
    </section>
  )
}
