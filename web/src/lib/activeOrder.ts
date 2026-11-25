/**
 * Pedido "em curso" deste aparelho (Outubro 2026).
 *
 * Quem pede gás sem conta não tem "os meus pedidos": guardamos aqui a
 * referência + o telefone usados no pedido (a prova de posse da rota
 * pública de acompanhamento) para o botão "Acompanhar pedido" continuar a
 * aparecer em qualquer ecrã até o pedido terminar. Fica só neste aparelho.
 */
const KEY = 'kerugas.activeOrder'

export interface StoredOrder {
  reference: string
  phone: string
}

export function saveActiveOrder(order: StoredOrder): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(order))
  } catch {
    /* modo privado / armazenamento bloqueado: o botão só não persiste */
  }
}

export function readActiveOrder(): StoredOrder | null {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<StoredOrder>
    return parsed.reference && parsed.phone ? { reference: parsed.reference, phone: parsed.phone } : null
  } catch {
    return null
  }
}

export function clearActiveOrder(): void {
  try {
    localStorage.removeItem(KEY)
  } catch {
    /* ignorar */
  }
}

/** Entregue, cancelado ou falhado: já não há nada a acompanhar. */
export function isOrderFinished(status: string): boolean {
  return status === 'DELIVERED' || status === 'CANCELLED' || status === 'FAILED'
}

/**
 * "Agora não" no convite para avaliar (Outubro 2026): esconde o botão de
 * avaliação desse pedido neste aparelho. A avaliação continua acessível na
 * página do pedido e em "Os meus pedidos".
 */
const DISMISSED_KEY = 'kerugas.ratingDismissed'

export function dismissRatingPrompt(reference: string): void {
  try {
    const list = readDismissed()
    if (!list.includes(reference)) localStorage.setItem(DISMISSED_KEY, JSON.stringify([...list, reference].slice(-20)))
  } catch {
    /* ignorar */
  }
}

export function isRatingPromptDismissed(reference: string): boolean {
  return readDismissed().includes(reference)
}

function readDismissed(): string[] {
  try {
    const parsed = JSON.parse(localStorage.getItem(DISMISSED_KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === 'string') : []
  } catch {
    return []
  }
}

/** Convites para avaliar só se mostram durante uma semana após a entrega. */
export const RATING_PROMPT_DAYS = 7
