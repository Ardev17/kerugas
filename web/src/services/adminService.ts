import { api } from '@/lib/api'
import type {
  AdminDashboardData, AdminStation, AnalyticsOverview, ApiSuccess, AuthUser, BillingLedgerEntry, BillingOverview, BottlePickup, CentralDriver, CentralPickupPayload, DemandOverview, ContactMessage, DriverStock, StationCounter, DriverStats, GasOrder, SuperAdminMetrics, MunicipalityMetrics,
  PaginatedMeta, StationComment, StationOrdersOverride,
} from '@/types'
import { triggerBlobDownload, filenameFromContentDisposition } from '@/lib/download'

interface Paginated<T> {
  success: true
  data: T[]
  meta: PaginatedMeta
}

export interface DriverStockAlert {
  driver_id: number
  driver_name: string
  on_hand: number
  reasons: Array<
    | { type: 'idle'; bottles: number }
    | { type: 'low_delivery'; picked: number; delivered: number; percent: number }
  >
}

/**
 * Consulta dos postos fechada por município (Novembro 2026).
 * O município é a regra geral; a pessoa é a excepção, e a excepção ganha.
 */
export interface StationDataMunicipality {
  id: number
  name: string
  province: string | null
  /** Fechado agora. Um bloqueio agendado ainda vem false. */
  blocked: boolean
  /** Marcado para fechar numa data futura. */
  scheduled: boolean
  blocked_from: string | null
  reason: string | null
  blocked_by: string | null
}

export type StationDataEffect = 'INHERIT' | 'ALLOW' | 'DENY'

export interface StationDataPerson {
  id: number
  name: string
  phone: string | null
  municipality: string | null
  /** Clientes e pessoal de posto: estes últimos também pagam a consulta. */
  role?: 'USER' | 'STATION_MANAGER' | 'STATION_OPERATOR' | 'STATION_DRIVER'
  effect: StationDataEffect
  /** false quando a excepção existe mas o prazo já passou. */
  active: boolean
  expires_at: string | null
  reason: string | null
}

export interface AdminPickupsOverview {
  pickups: BottlePickup[]
  counters: (StationCounter & { station_id: number; station_name: string })[]
  resets: { id: number; station_name: string | null; reset_by: string | null; bottles_before: number; reset_at: string | null }[]
}

/** Condutor na lista de escolha, com o stock de garrafas (Outubro 2026). */
/** Última posição do condutor (Novembro 2026),só para o Super Admin geral/Provincial. */
export interface DriverLocation {
  latitude: number
  longitude: number
  accuracy_m: number | null
  updated_at: string
  /** `false` = mais de 15 minutos sem posição → "sem localização". */
  fresh: boolean
}

export type DriverWithStock = AuthUser & { bottle_stock?: DriverStock | null; location?: DriverLocation | null }

export interface NearbyDriver {
  id: number
  name: string
  phone: string | null
  available: boolean
  location: DriverLocation | null
  distance_km: number | null
  bottle_stock: DriverStock | null
}

export interface NearbyDriversResponse {
  success: boolean
  data: NearbyDriver[]
  meta: { order_has_location: boolean; order_location: { latitude: number; longitude: number } | null; fresh_minutes: number }
}

export interface ConfirmOrderPayload {
  bottle_price: number
  commission: number
  quantity?: number
  cylinder_types_confirmed: string
}

export const adminService = {
  async dashboard(): Promise<AdminDashboardData> {
    const { data } = await api.get<ApiSuccess<AdminDashboardData>>('/admin/dashboard')
    return data.data
  },

  // Postos
  async listStations(params: { search?: string; status?: string; page?: number } = {}): Promise<Paginated<AdminStation>> {
    const { data } = await api.get<Paginated<AdminStation>>('/admin/stations', { params })
    return data
  },
  async createStation(payload: Partial<AdminStation>): Promise<AdminStation> {
    const { data } = await api.post<ApiSuccess<AdminStation>>('/admin/stations', payload)
    return data.data
  },
  async updateStation(id: number, payload: Partial<AdminStation>): Promise<AdminStation> {
    const { data } = await api.put<ApiSuccess<AdminStation>>(`/admin/stations/${id}`, payload)
    return data.data
  },
  async deleteStation(id: number): Promise<void> {
    await api.delete(`/admin/stations/${id}`)
  },
  async updateStationStatus(
    id: number,
    payload: { gas_available: boolean; queue_level: string; cylinder_types?: string; status?: AdminStation['status'] }
  ): Promise<AdminStation> {
    const { data } = await api.post<ApiSuccess<AdminStation>>(`/admin/stations/${id}/status`, payload)
    return data.data
  },
  /**
   * Liga/desliga o acesso do OPERADOR ao seu próprio analytics,o
   * backend recusa com 403 se quem pedir não for SUPER_ADMIN, mesmo
   * que a rota em si só exija a permissão `stations.update` (que o
   * Admin comum também tem). O frontend só mostra o controlo a quem
   * realmente pode usá-lo, mas a aplicação real da regra vive no
   * backend, não aqui.
   */

  /**
   * Interruptor dos pedidos do posto (Novembro 2026). O servidor avisa o
   * operador sozinho, e devolve em `meta` se a subscrição está paga e qual é
   * a resposta efectiva depois do interruptor.
   */
  /**
   * Presença do posto na aplicação (Novembro 2026, a dormir). Aceita os dois
   * campos em separado: só se envia o que mudou.
   */
  async setStationListing(
    id: number,
    payload: { override?: StationOrdersOverride; platform_owned?: boolean; reason?: string },
  ): Promise<AdminStation> {
    const { data } = await api.post<ApiSuccess<AdminStation>>(`/admin/stations/${id}/listing`, payload)
    return data.data
  },

  async setStationOrdersOverride(
    id: number,
    payload: { override: StationOrdersOverride; reason?: string },
  ): Promise<{ station: AdminStation; operatorNotified: boolean; subscriptionActive: boolean; canReceiveOrders: boolean }> {
    const { data } = await api.post<ApiSuccess<AdminStation> & {
      meta: { operator_notified: boolean; subscription_active: boolean; can_receive_orders: boolean }
    }>(`/admin/stations/${id}/orders-override`, payload)

    return {
      station: data.data,
      operatorNotified: data.meta.operator_notified,
      subscriptionActive: data.meta.subscription_active,
      canReceiveOrders: data.meta.can_receive_orders,
    }
  },
  /** Aviso MANUAL por SMS ao operador (template "Aviso ao operador"). */
  async sendStationSubscriptionNotice(id: number): Promise<void> {
    await api.post(`/admin/stations/${id}/subscription-notice`)
  },

  // Utilizadores
  async listUsers(params: { search?: string; role?: string; page?: number } = {}): Promise<Paginated<AuthUser>> {
    const { data } = await api.get<Paginated<AuthUser>>('/admin/users', { params })
    return data
  },
  async updateUser(id: number, payload: { station_id?: number | null; role?: string; status?: string; province_id?: number | null; municipality_id?: number | null; sms_daily_limit?: number | null; can_promote_users?: boolean }): Promise<AuthUser> {
    const { data } = await api.put<ApiSuccess<AuthUser>>(`/admin/users/${id}`, payload)
    return data.data
  },

  // Comentários
  async listComments(status = 'PENDING'): Promise<Paginated<StationComment & { station_id?: number }>> {
    const { data } = await api.get<Paginated<StationComment & { station_id?: number }>>('/admin/comments', { params: { status } })
    return data
  },
  async updateCommentStatus(id: number, status: string): Promise<void> {
    await api.patch(`/admin/comments/${id}/status`, { status })
  },

  // Denúncias
  async listReports(status?: string): Promise<Paginated<Record<string, unknown>>> {
    const { data } = await api.get<Paginated<Record<string, unknown>>>('/admin/reports', { params: { status } })
    return data
  },
  async updateReportStatus(id: number, status: string): Promise<void> {
    await api.patch(`/admin/reports/${id}/status`, { status })
  },

  // Pedidos
  async listOrders(params: { status?: string; search?: string; page?: number } = {}): Promise<Paginated<GasOrder>> {
    const { data } = await api.get<Paginated<GasOrder>>('/admin/orders', { params })
    return data
  },
  async getOrder(id: number): Promise<GasOrder> {
    const { data } = await api.get<ApiSuccess<GasOrder>>(`/admin/orders/${id}`)
    return data.data
  },
  async contactOrder(id: number): Promise<void> {
    await api.post(`/admin/orders/${id}/contact`)
  },
  /**
   * Confirmação (Outubro 2026, Quero Gás intermediária): valor das garrafas
   * (total) + comissão (nossa) + tipo(s) de garrafa. Sem posto,o pedido sai
   * do stock do condutor. O total é a soma, calculada no servidor. `quantity` só sobe.
   */
  async confirmOrder(id: number, payload: ConfirmOrderPayload): Promise<void> {
    await api.post(`/admin/orders/${id}/confirm`, payload)
  },
  /** Completa um pedido antigo (só total),a soma tem de igualar o total existente. */
  async completeOrderBilling(id: number, payload: { bottle_price: number; commission: number; quantity: number }): Promise<void> {
    await api.patch(`/admin/orders/${id}/billing`, payload)
  },
  /**
   * Envio do pedido a um Condutor do MESMO município (Setembro 2026)
   *,o backend recusa (422) se o condutor for de outro município ou
   * estiver indisponível.
   */
  /** Pedido aceite por um posto (Novembro 2026): só o Super Admin geral/Provincial o retira. */
  async withdrawOrderFromStation(id: number, reason?: string): Promise<void> {
    await api.post(`/admin/orders/${id}/withdraw-station`, { reason })
  },
  async assignOrderToDriver(id: number, driverId: number): Promise<void> {
    await api.post(`/admin/orders/${id}/assign-driver`, { driver_id: driverId })
  },
  /** Condutores do município do pedido, do mais perto do cliente para o mais longe (Novembro 2026). */
  async nearbyDrivers(orderId: number): Promise<NearbyDriversResponse> {
    const { data } = await api.get<NearbyDriversResponse>(`/admin/orders/${orderId}/nearby-drivers`)
    return data
  },
  async listDrivers(params: { municipality_id?: number; province_id?: number; available_only?: boolean } = {}): Promise<Paginated<DriverWithStock>> {
    const { data } = await api.get<Paginated<DriverWithStock>>('/admin/drivers', { params })
    return data
  },
  /**
   * Estatísticas/ranking simples de UM condutor (Setembro 2026),
   * visível ao Admin (só da sua própria província) e Super Admin
   * (qualquer condutor),mesmo cálculo que o condutor vê de si
   * próprio, ver `driverService.getStats`.
   */
  async getDriverStats(driverId: number): Promise<DriverStats> {
    const { data } = await api.get<ApiSuccess<DriverStats>>(`/admin/drivers/${driverId}/stats`)
    return data.data
  },
  /**
   * Exportação (CSV/PDF) de entregas por condutor (Setembro 2026),
   * para pagamento/comissão. Mesmo âmbito por província de
   * `exportStationStatus`,um ADMIN só exporta a sua própria
   * província, `province_id` é sempre forçado no backend.
   */
  async exportDriverDeliveries(params: {
    format: 'csv' | 'pdf'
    period: 'day' | 'week' | 'month'
    date?: string
    driver_id?: number
    municipality_id?: number
    province_id?: number
  }): Promise<void> {
    const response = await api.get<Blob>('/admin/drivers/export', { params, responseType: 'blob' })
    const filename = filenameFromContentDisposition(response.headers['content-disposition'], `kerugas-entregas-condutor.${params.format}`)
    await triggerBlobDownload(response.data, filename)
  },
  /**
   * Criação administrativa de contas (Setembro 2026),usada
   * principalmente para condutores; o backend recusa (403) criar
   * ADMIN/SUPER_ADMIN/DRIVER a quem não for SUPER_ADMIN.
   */
  async createUser(payload: {
    name: string
    phone: string
    email?: string
    password: string
    role: string
    /** PIN de SMS do condutor (obrigatório para DRIVER; diferente da palavra-passe). */
    sms_pin?: string
    province_id?: number | null
    municipality_id?: number | null
    /** Posto do Admin de Posto / Condutor de Posto (Novembro 2026). */
    station_id?: number | null
  }): Promise<AuthUser> {
    const { data } = await api.post<ApiSuccess<AuthUser>>('/admin/users', payload)
    return data.data
  },
  async deliveryOrder(id: number): Promise<void> {
    await api.post(`/admin/orders/${id}/delivery`)
  },
  async completeOrder(id: number): Promise<void> {
    await api.post(`/admin/orders/${id}/complete`)
  },
  async cancelOrder(id: number, reason?: string): Promise<void> {
    await api.post(`/admin/orders/${id}/cancel`, { reason })
  },
  /**
   * Correção de um preço já registado,o backend recusa (403) a
   * quem não for SUPER_ADMIN, mesmo tendo a permissão `orders.manage`.
   */
  async updateOrderPrice(id: number, payload: { bottle_price: number; commission: number } | { delivery_price: number }): Promise<GasOrder> {
    const { data } = await api.patch<ApiSuccess<GasOrder>>(`/admin/orders/${id}/price`, payload)
    return data.data
  },

  // Analytics
  async analytics(): Promise<AnalyticsOverview> {
    const { data } = await api.get<ApiSuccess<AnalyticsOverview>>('/admin/analytics')
    return data.data
  },

  // Painel de métricas do Super Admin (Outubro 2026)
  async metrics(days: 7 | 30 | 90 = 30): Promise<SuperAdminMetrics> {
    const { data } = await api.get<ApiSuccess<SuperAdminMetrics>>('/admin/metrics', { params: { days } })
    return data.data
  },

  /** Métricas por município (Novembro 2026): só Super Admin geral/Provincial. */
  async municipalityMetrics(params: { period: string; from?: string; to?: string; province_id?: number; municipality_id?: number }): Promise<MunicipalityMetrics> {
    const { data } = await api.get<ApiSuccess<MunicipalityMetrics>>('/admin/metrics/municipalities', { params })
    return data.data
  },

  // Consulta dos postos fechada por município (Novembro 2026)
  async stationDataMunicipalities(): Promise<StationDataMunicipality[]> {
    const { data } = await api.get<ApiSuccess<StationDataMunicipality[]>>('/admin/station-data-access/municipalities')
    return data.data
  },

  async setStationDataMunicipality(
    id: number,
    payload: { blocked: boolean; from?: string; reason?: string },
  ): Promise<StationDataMunicipality> {
    const { data } = await api.put<ApiSuccess<StationDataMunicipality>>(`/admin/station-data-access/municipalities/${id}`, payload)
    return data.data
  },

  async stationDataUsers(search?: string): Promise<StationDataPerson[]> {
    const { data } = await api.get<ApiSuccess<StationDataPerson[]>>('/admin/station-data-access/users', {
      params: search ? { search } : undefined,
    })
    return data.data
  },

  async setStationDataUser(
    id: number,
    payload: { effect: StationDataEffect; expires_at?: string; reason?: string },
  ): Promise<StationDataPerson> {
    const { data } = await api.put<ApiSuccess<StationDataPerson>>(`/admin/station-data-access/users/${id}`, payload)
    return data.data
  },

  // Faturação
  async billing(date?: string): Promise<BillingOverview> {
    const { data } = await api.get<ApiSuccess<BillingOverview>>('/admin/billing', { params: date ? { date } : undefined })
    return data.data
  },
  /** Marca como pago ao posto tudo o que está por pagar (opcionalmente até um dia). */
  async payStation(stationId: number, until?: string): Promise<{ amount: number; pickups: number }> {
    const { data } = await api.post<ApiSuccess<{ amount: number; pickups: number }>>(`/admin/billing/stations/${stationId}/pay`, until ? { until } : {})
    return data.data
  },
  /** "Recebi X" de um condutor (parcial permitido; nunca acima do em dívida). */
  async receiveDriverCash(driverId: number, amount: number, note?: string): Promise<{ amount: number; outstanding: number }> {
    const { data } = await api.post<ApiSuccess<{ amount: number; outstanding: number }>>(`/admin/billing/drivers/${driverId}/receipts`, { amount, note: note || undefined })
    return data.data
  },
  async billingLedger(): Promise<BillingLedgerEntry[]> {
    const { data } = await api.get<ApiSuccess<BillingLedgerEntry[]>>('/admin/billing/ledger')
    return data.data
  },
  async undoBillingLedger(id: number): Promise<void> {
    await api.post(`/admin/billing/ledger/${id}/undo`)
  },
  /** Relatórios da faturação (Outubro 2026): Excel (csv) ou PDF. */
  async exportBillingReport(params: { type: 'day' | 'stations' | 'drivers'; format: 'csv' | 'pdf'; date?: string; from?: string; to?: string }): Promise<void> {
    const response = await api.get<Blob>('/admin/billing/export', { params, responseType: 'blob' })
    const filename = filenameFromContentDisposition(response.headers['content-disposition'], `kerugas-relatorio-${params.type}.${params.format}`)
    await triggerBlobDownload(response.data, filename)
  },
  /** Mapa de calor da procura (Outubro 2026). */
  async demand(params: { days?: 7 | 30 | 90; province_id?: number; municipality_id?: number } = {}): Promise<DemandOverview> {
    const { data } = await api.get<ApiSuccess<DemandOverview>>('/admin/analytics/demand', { params })
    return data.data
  },
  // Carregamentos nos postos e contadores (Outubro 2026)
  async pickups(params: { date?: string; station_id?: number } = {}): Promise<AdminPickupsOverview> {
    const { data } = await api.get<ApiSuccess<AdminPickupsOverview>>('/admin/pickups', { params })
    return data.data
  },
  /** Condutores cujas garrafas não batem certo com as entregas da plataforma (Outubro 2026). */
  async pickupAlerts(): Promise<DriverStockAlert[]> {
    const { data } = await api.get<ApiSuccess<DriverStockAlert[]>>('/admin/pickups/alerts')
    return data.data
  },
  // Registo pela central (Outubro 2026)
  async centralDrivers(params: { search?: string; only_enabled?: boolean } = {}): Promise<CentralDriver[]> {
    const { data } = await api.get<ApiSuccess<CentralDriver[]>>('/admin/central-pickups/drivers', { params: { search: params.search || undefined, only_enabled: params.only_enabled ? 1 : undefined } })
    return data.data
  },
  /** Só Super Admin / Provincial. Ao ligar, o motivo é obrigatório. */
  async setCentralSwitch(driverId: number, payload: { enabled: boolean; reason?: string; days?: number }): Promise<{ id: number; central_active: boolean; central_expires_at: string | null }> {
    const { data } = await api.put<ApiSuccess<{ id: number; central_active: boolean; central_expires_at: string | null }>>(`/admin/drivers/${driverId}/central-pickup`, payload)
    return data.data
  },
  async registerCentralPickup(payload: CentralPickupPayload): Promise<BottlePickup> {
    const { data } = await api.post<ApiSuccess<BottlePickup>>('/admin/pickups/central', payload)
    return data.data
  },
  /** O SMS "CARREGAR …" caiu no telemóvel da central: quem atende cola o texto (o PIN é conferido no servidor). */
  async registerSmsPickup(payload: { sender_phone: string; sms_text: string; received_at?: string; amount?: number }): Promise<BottlePickup> {
    const { data } = await api.post<ApiSuccess<BottlePickup>>('/admin/pickups/sms', payload)
    return data.data
  },
  /** O condutor esqueceu o PIN: define-se um novo, provisório (o condutor muda-o na app). */
  /** PIN de carregamento do condutor,só Super Admin Geral/Provincial; cada consulta é auditada. */
  async showSmsPin(driverId: number): Promise<{ pin: string | null; pin_set: boolean; temporary: boolean; message: string | null }> {
    const { data } = await api.get<ApiSuccess<{ pin: string | null; pin_set: boolean; temporary: boolean; message: string | null }>>(`/admin/drivers/${driverId}/sms-pin`)
    return data.data
  },
  async resetSmsPin(driverId: number, pin: string): Promise<void> {
    await api.put(`/admin/drivers/${driverId}/sms-pin`, { pin })
  },
  /** Super Admin / Provincial confirma um carregamento registado pela central. */
  async confirmCentralPickup(id: number): Promise<BottlePickup> {
    const { data } = await api.post<ApiSuccess<BottlePickup>>(`/admin/pickups/${id}/confirm`)
    return data.data
  },
  async correctPickupAmount(id: number, amount: number): Promise<BottlePickup> {
    const { data } = await api.patch<ApiSuccess<BottlePickup>>(`/admin/pickups/${id}`, { amount })
    return data.data
  },
  /** Só Super Admin. O histórico fica guardado. */
  async resetStationCounter(stationId: number): Promise<{ bottles_before: number }> {
    const { data } = await api.post<ApiSuccess<{ bottles_before: number }>>(`/admin/stations/${stationId}/counter-reset`)
    return data.data
  },

  /**
   * Exporta o histórico de estado de postos (Excel ou PDF). Herda os
   * mesmos filtros de período do operador (dia/semana/mês) e
   * acrescenta posto/município/província, todos opcionais e
   * combináveis,sem nenhum, exporta todos os postos no período.
   */
  async exportStationStatus(params: {
    format: 'csv' | 'pdf'
    period: 'day' | 'week' | 'month'
    date?: string
    station_id?: number
    municipality_id?: number
    province_id?: number
  }): Promise<void> {
    const response = await api.get<Blob>('/admin/stations/export', { params, responseType: 'blob' })
    const filename = filenameFromContentDisposition(response.headers['content-disposition'], `kerugas-historico.${params.format}`)
    await triggerBlobDownload(response.data, filename)
  },

  // Mensagens de contacto do site (Setembro 2026)
  async listContactMessages(params: { status?: string; page?: number } = {}): Promise<Paginated<ContactMessage> & { meta: PaginatedMeta & { unread_count: number } }> {
    const { data } = await api.get<Paginated<ContactMessage> & { meta: PaginatedMeta & { unread_count: number } }>('/admin/contact-messages', { params })
    return data
  },
  async markContactMessageRead(id: number): Promise<ContactMessage> {
    const { data } = await api.patch<ApiSuccess<ContactMessage>>(`/admin/contact-messages/${id}/read`)
    return data.data
  },
}
