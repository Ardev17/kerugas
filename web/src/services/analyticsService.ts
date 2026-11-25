import { api } from '@/lib/api'

type AnalyticsEventName =
  | 'page_view' | 'station_view' | 'station_search' | 'map_open'
  | 'location_enabled' | 'route_clicked' | 'favorite_added'
  | 'alert_created' | 'sms_query' | 'review_created'

/**
 * Tracking leve, "fire and forget" (secção 102). Nunca bloqueia a UI —
 * se o pedido falhar (rede lenta, ad-blocker), a experiência do
 * utilizador não é afetada. Nunca enviar PII no payload.
 */
export const analyticsService = {
  track(event: AnalyticsEventName, payload?: Record<string, unknown>) {
    api.post('/analytics/events', { event, payload }).catch(() => {
      // Silenciosamente ignorado de propósito,analytics nunca deve
      // gerar um erro visível ao utilizador.
    })
  },
}
