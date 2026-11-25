import type { PickupPayload } from '@/services/driverService'
import { useAuthStore } from '@/stores/authStore'

/**
 * Fila offline dos carregamentos (Outubro 2026). Em zonas sem internet o
 * condutor regista na mesma: o carregamento fica guardado no telemóvel e é
 * enviado sozinho quando a rede voltar. Cada registo tem um `client_uuid`
 *,se o envio for repetido, o servidor não duplica.
 */
const KEY = 'kerugas.pickupQueue.v1'

export interface QueuedPickup extends PickupPayload {
  station_name: string
  queued_at: string
  /**
   * Condutor que registou (Outubro 2026). Num telemóvel partilhado, a fila
   * de um condutor nunca é enviada pela conta de outro; fica guardada até
   * o dono voltar a entrar. Registos antigos (sem dono) são de quem estiver.
   */
  owner_id?: number
}

const currentOwner = (): number | undefined => useAuthStore.getState().user?.id

const isMine = (item: QueuedPickup, owner = currentOwner()) => item.owner_id === undefined || item.owner_id === owner

export function newClientUuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  // Fallback RFC 4122 v4 para WebViews antigos.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

/** A fila do condutor com sessão iniciada. */
export function readQueue(): QueuedPickup[] {
  const owner = currentOwner()
  return readAll().filter((i) => isMine(i, owner))
}

function readAll(): QueuedPickup[] {
  try {
    const raw = localStorage.getItem(KEY)
    const parsed = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function writeQueue(items: QueuedPickup[]): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(items))
  } catch {
    // armazenamento indisponível,nada a fazer
  }
}

export function enqueuePickup(item: QueuedPickup): void {
  const items = readAll().filter((i) => i.client_uuid !== item.client_uuid)
  writeQueue([...items, { ...item, owner_id: item.owner_id ?? currentOwner() }])
}

/** Um erro de rede/servidor deve ficar na fila; um erro de validação (4xx) não volta a dar certo. */
export function isRetryable(error: unknown): boolean {
  const status = (error as { response?: { status?: number } })?.response?.status
  if (!status) return true // sem resposta = sem rede
  return status >= 500 || status === 408 || status === 429 || status === 401
}

/**
 * Envia a fila por ordem. Remove o que foi aceite (ou recusado de vez) e
 * mantém o resto. Devolve quantos foram enviados e quantos foram recusados.
 */
export async function flushQueue(send: (p: PickupPayload) => Promise<unknown>): Promise<{ sent: number; rejected: QueuedPickup[]; remaining: number }> {
  const items = readQueue()
  const keep: QueuedPickup[] = []
  const rejected: QueuedPickup[] = []
  let sent = 0

  for (let i = 0; i < items.length; i++) {
    const item = items[i]
    const { station_name: _name, queued_at: _at, owner_id: _owner, ...payload } = item
    void _name
    void _at
    void _owner
    try {
      await send({ ...payload, offline: true })
      sent++
    } catch (error) {
      if (isRetryable(error)) {
        keep.push(...items.slice(i)) // sem rede: pára e guarda o resto
        break
      }
      rejected.push(item)
    }
  }

  // Preserva a fila de outros condutores deste telemóvel.
  const owner = currentOwner()
  writeQueue([...readAll().filter((i) => !isMine(i, owner)), ...keep])
  return { sent, rejected, remaining: keep.length }
}

/**
 * Antes de sair (Outubro 2026): avisa se há carregamentos por enviar. Eles
 * ficam guardados no telemóvel e são enviados quando este condutor voltar a
 * entrar,mas convém saber. Devolve false se a pessoa desistir de sair.
 */
export function confirmLogoutWithPendingPickups(): boolean {
  const pending = readQueue().length
  if (pending === 0) return true
  return window.confirm(
    `Tem ${pending} carregamento(s) ainda por enviar (sem internet). Se sair agora, ficam guardados neste telemóvel e são enviados quando voltar a entrar com a sua conta. Sair mesmo assim?`,
  )
}
