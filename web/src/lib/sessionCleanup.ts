/**
 * Limpeza ao terminar sessão (Outubro 2026). Num telemóvel partilhado, a
 * pessoa seguinte nunca pode ver dados da anterior: a cache de pedidos à API
 * (React Query) é esvaziada e os dados locais ligados à conta saem.
 *
 * NÃO é apagado: a fila offline de carregamentos do condutor,está marcada
 * com o dono e só é enviada quando ESSE condutor voltar a entrar (perder
 * esses registos seria perder dinheiro/garrafas por contabilizar).
 */
import { QUERY_CACHE_KEY } from './queryPersistence'

export const SESSION_CLEARED_EVENT = 'kerugas:session-cleared'

const LOCAL_KEYS = [
  'kerugas.activeOrder', // pedido em curso (referência + telefone)
  'kerugas.ratingDismissed', // avaliações de entregas que a pessoa adiou
  'kerugas.recentStations.v1', // postos recentes do condutor
  'kerugas.orderPush', // pedidos com avisos ativos neste aparelho
  // Mapa e fichas guardados para abrir depressa (Novembro 2026): o que se vê
  // depende de quem tem sessão,consulta paga, contas da equipa,por isso
  // não pode passar para a pessoa seguinte.
  QUERY_CACHE_KEY,
]

export function clearLocalSessionData(): void {
  for (const key of LOCAL_KEYS) {
    try {
      localStorage.removeItem(key)
    } catch {
      /* armazenamento bloqueado */
    }
  }
  window.dispatchEvent(new Event(SESSION_CLEARED_EVENT))
}
