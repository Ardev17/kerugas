import type { GasOrderStatus } from '@/types'

/**
 * Como o CLIENTE vê o estado do pedido (Setembro 2026),linguagem
 * simples e só os passos que lhe interessam. Os estados internos
 * (RECEIVED/PENDING, ASSIGNED/OUT_FOR_DELIVERY) juntam-se num só passo:
 * para quem espera o gás, "um condutor foi atribuído" e "saiu para
 * entrega" são o mesmo momento,o gás vem a caminho.
 */
export const CUSTOMER_STEPS = [
  { key: 'received', label: 'Pedido recebido' },
  { key: 'contacting', label: 'Em contacto consigo' },
  { key: 'confirmed', label: 'Confirmado' },
  { key: 'on_the_way', label: 'A caminho' },
  { key: 'delivered', label: 'Entregue' },
] as const

const STEP_INDEX: Record<GasOrderStatus, number> = {
  PENDING: 0,
  RECEIVED: 0,
  CONTACTING: 1,
  CONFIRMED: 2,
  ASSIGNED: 3,
  OUT_FOR_DELIVERY: 3,
  DELIVERED: 4,
  CANCELLED: -1,
  FAILED: -1,
}

export function customerStepIndex(status: GasOrderStatus): number {
  return STEP_INDEX[status] ?? 0
}

export function isTerminalFailure(status: GasOrderStatus): boolean {
  return status === 'CANCELLED' || status === 'FAILED'
}

/** Cor do "chip" de estado nas listas. */
export function statusTone(status: GasOrderStatus): string {
  if (status === 'DELIVERED') return 'bg-success/10 text-success'
  if (isTerminalFailure(status)) return 'bg-danger/10 text-danger'
  if (status === 'ASSIGNED' || status === 'OUT_FOR_DELIVERY') return 'bg-secondary/15 text-secondary'
  return 'bg-primary/10 text-primary'
}
