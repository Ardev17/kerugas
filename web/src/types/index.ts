export type QueueLevel = 'LOW' | 'MEDIUM' | 'HIGH'
export type Freshness = 'FRESH' | 'RECENT' | 'STALE' | 'EXPIRED'
export type LocationSource = 'GPS' | 'MANUAL'
export type GasOrderStatus =
  | 'PENDING' | 'RECEIVED' | 'CONTACTING' | 'CONFIRMED'
  | 'ASSIGNED' | 'OUT_FOR_DELIVERY' | 'DELIVERED' | 'CANCELLED' | 'FAILED'

export interface Province {
  id: number
  name: string
  slug: string
}

export interface Municipality {
  id: number
  name: string
  slug: string
}

export interface Station {
  id: number
  /** Número do posto (5 dígitos, automático). */
  code?: string
  name: string
  slug: string
  province?: string
  municipality?: string
  address: string | null
  reference_point: string | null
  latitude: number
  longitude: number
  phone: string | null
  opening_hours: string | null
  photo_url: string | null
  status: 'ACTIVE' | 'INACTIVE' | 'PENDING' | 'SUSPENDED'
  gas_available: boolean
  queue_level: QueueLevel | null
  cylinder_types: string | null
  confidence_score: number
  freshness: Freshness
  freshness_label: string
  status_updated_at: string | null
  distance_km?: number
}

export interface GasOrderPayload {
  customer_name: string
  customer_phone: string
  province_id: number
  municipality_id: number
  neighborhood?: string
  address?: string
  reference_point?: string
  latitude?: number
  longitude?: number
  location_source: LocationSource
  location_accuracy?: number
  quantity: number
  cylinder_type?: string
  notes?: string
  /** Honeypot anti-bot — só vai preenchido quando é um bot (ver HoneypotField). */
  website?: string
}

export interface GasOrderEvent {
  action: string
  from_status: string | null
  to_status: string | null
  notes: string | null
  user: string | null
  created_at: string
}

export interface GasOrder {
  id: number
  reference: string
  /** Pedido aceite por um posto subscrito (Novembro 2026). */
  station_id?: number | null
  accepted_station?: { id: number; name: string; code: string | null } | null
  accepted_at?: string | null
  /** Condutor: pedido terminado — o servidor já não envia os dados do cliente (Outubro 2026). */
  contact_hidden?: boolean
  customer_name: string
  customer_phone: string
  province_id?: number | null
  municipality_id?: number | null
  province?: string | null
  municipality?: string | null
  neighborhood?: string | null
  address?: string | null
  reference_point?: string | null
  latitude?: number | null
  longitude?: number | null
  location_source?: LocationSource
  quantity?: number
  cylinder_type?: string | null
  // Confirmação de garrafas (Setembro 2026) — separado por vírgula
  // quando há mais de um tipo; só existe a partir da confirmação do
  // pedido (distinto de `cylinder_type`, a estimativa inicial do cliente).
  cylinder_types_confirmed?: string | null
  notes?: string | null
  status: GasOrderStatus
  status_label: string
  priority: 'NORMAL' | 'HIGH' | 'URGENT'
  assigned_to?: string | null
  assigned_to_id?: number | null
  assigned_to_avatar_url?: string | null
  assigned_to_role?: string | null
  // Avaliação do condutor (Outubro 2026): `can_rate` = entregue por um
  // condutor e ainda sem avaliação; `driver_rating` = estrelas dadas.
  can_rate?: boolean
  driver_rating?: number | null
  created_at: string
  confirmed_at?: string | null
  contacted_at?: string | null
  completed_at?: string | null
  // Faturação por entrega (Setembro 2026) — só existe a partir do
  // momento em que o pedido é confirmado (o preço é negociado por
  // telefone antes disso, nunca calculado automaticamente).
  delivery_price?: number | null
  price_set_by?: string | null
  price_set_at?: string | null
  price_corrected_by?: string | null
  price_corrected_at?: string | null
  // Divisão da faturação (Outubro 2026) — só Admin/Super Admin recebem estes campos.
  bottle_price?: number | null
  commission?: number | null
  billing_complete?: boolean
  events?: GasOrderEvent[]
}

// Avaliação do condutor (Setembro 2026) — fluxo público (referência +
// telefone do cliente), ver DriverRatingController.
export interface DriverRatingStatus {
  reference: string
  driver_name: string | null
  eligible: boolean
  already_rated: boolean
  rating: number | null
  comment: string | null
  ineligible_reason: string | null
}

// Estatísticas/ranking simples do condutor (Setembro 2026) — visível
// ao próprio condutor, ao Admin e ao Super Admin (mesmo cálculo, ver
// DriverStatsService).
export interface DriverStats {
  total_delivered: number
  total_cancelled: number
  cancellation_rate: number
  average_delivery_minutes: number | null
  average_rating: number | null
  ratings_count: number
}

/** Uma avaliação recebida pelo condutor — sem identificar quem avaliou (Outubro 2026). */
export interface DriverOwnRating {
  id: number
  rating: number
  comment: string | null
  /** Só o dia (AAAA-MM-DD), nunca a hora. */
  date: string
}

export interface DriverRatingsSummary {
  average: number | null
  count: number
  with_comment: number
  /** Chaves "5" a "1". */
  distribution: Record<string, number>
  last_30_days: { average: number | null; count: number }
}

export interface DriverRatingsPage {
  data: DriverOwnRating[]
  meta: PaginatedMeta
  summary: DriverRatingsSummary
}

export interface ApiSuccess<T> {
  success: true
  data: T
  meta?: Record<string, unknown>
}

export interface ApiError {
  success: false
  message: string
  errors?: Record<string, string[]>
  code?: string
}

export interface AuthUser {
  id: number
  name: string
  phone: string
  email: string | null
  role: 'SUPER_ADMIN' | 'PROVINCE_SUPER_ADMIN' | 'ADMIN' | 'MODERATOR' | 'STATION_OPERATOR' | 'STATION_MANAGER' | 'DRIVER' | 'STATION_DRIVER' | 'INVESTOR' | 'USER'
  status: string
  // Pessoal do posto (Novembro 2026): o posto ligado à conta.
  operated_station_id?: number | null
  managed_station_id?: number | null
  driver_station_id?: number | null
  station?: { id: number; name: string; code: string | null; municipality_id: number } | null
  province?: string
  municipality?: string
  province_id: number | null
  municipality_id: number | null
  // Vizinhos KeruGás (Outubro 2026): o bairro vai no perfil e no login.
  neighborhood_id?: number | null
  neighborhood?: string | null
  community_accepted?: boolean
  // Localização exata OPCIONAL, partilhada no registo. Ajuda a traçar
  // rota de entrega quando o utilizador pede gás.
  latitude?: number | null
  longitude?: number | null
  // Condutor (Setembro 2026) — só tem significado quando role === 'DRIVER'.
  driver_available?: boolean
  // Limite diário de SMS (Setembro 2026) — só tem significado prático
  // quando role === 'ADMIN'; `null`/undefined = sem limite.
  sms_daily_limit?: number | null
  // Admin pode promover/alterar papéis? Concedido pelo Super Admin (Outubro 2026).
  can_promote_users?: boolean
  // Meu Perfil (Outubro 2026).
  created_at?: string | null
  // Foto de perfil (Outubro 2026) — URL público ou null.
  avatar_url?: string | null
  // Eliminação pedida (Outubro 2026): data em que a conta será eliminada.
  deletion_scheduled_for?: string | null
}

// Chat por município (Setembro 2026)
export interface ChatMessageItem {
  id: number
  body: string
  user: string | null
  user_id: number
  user_avatar_url?: string | null
  role: string | null
  province_id: number
  municipality_id: number
  created_at: string
}

export interface AuthResponse {
  user: AuthUser
  token: string
  /** Entrou durante o prazo de arrependimento: a eliminação foi cancelada. */
  deletion_cancelled?: boolean
}

export interface RegisterPayload {
  name: string
  phone: string
  email?: string
  password: string
  password_confirmation: string
  province_id: number
  municipality_id: number
  latitude?: number
  longitude?: number
  /** Honeypot anti-bot — só vai preenchido quando é um bot (ver HoneypotField). */
  website?: string
}

export interface LoginPayload {
  identifier: string
  password: string
}

export interface StationReview {
  id: number
  rating: number
  comment: string | null
  user_name: string
  user_avatar_url?: string | null
  created_at: string
}

export interface StationComment {
  id: number
  content: string
  status: string
  user_name: string
  user_avatar_url?: string | null
  // Só nas listas de moderação (Outubro 2026).
  user_id?: number
  station?: { id: number; name: string; municipality: string | null; province: string | null } | null
  created_at: string
}

export interface Favorite {
  id: number
  station: Station
  created_at: string
}

export interface Alert {
  id: number
  station: string | null
  municipality: string | null
  max_distance_km: number | null
  channels: string[]
  active: boolean
  created_at: string
}

export interface AppNotificationItem {
  id: number
  category: string
  title: string
  body: string
  data: Record<string, unknown> | null
  read: boolean
  created_at: string
}

export type StationOrdersOverride = 'INHERIT' | 'ALLOW' | 'BLOCK'

export interface AdminStation {
  id: number
  code?: string
  name: string
  slug: string
  province_id: number
  municipality_id: number
  province?: string
  municipality?: string
  address: string | null
  reference_point: string | null
  latitude: number
  longitude: number
  phone: string | null
  sms_phone: string | null
  operator_id: number | null
  operator_name?: string | null
  /** Vários operadores por posto (Outubro 2026); cada operador só pertence a um posto. */
  operators?: { id: number; name: string; phone?: string | null }[]
  operator_ids?: number[]
  /**
   * Interruptor do administrador sobre os pedidos do município (Novembro 2026).
   * INHERIT deixa decidir a subscrição paga; ALLOW abre sem pagamento;
   * BLOCK fecha mesmo com a subscrição em dia.
   */
  orders_override: StationOrdersOverride
  orders_override_reason: string | null
  orders_override_at: string | null
  /**
   * Presença do posto na aplicação (Novembro 2026, a dormir). Só faz efeito
   * quando `listing_enforced` for verdadeiro, e por omissão não é.
   */
  listing_override: StationOrdersOverride
  listing_override_reason: string | null
  platform_owned: boolean
  /** Global: o mecanismo da presença paga já está a ser exigido? */
  listing_enforced: boolean
  /** Dia em que o posto sai do mapa, se a presença expirou e corre a tolerância. */
  listing_grace_until: string | null
  /** Visível hoje. O analytics do operador vem agarrado a isto (Novembro 2026). */
  is_listed: boolean
  opening_hours: string | null
  status: 'ACTIVE' | 'INACTIVE' | 'PENDING' | 'SUSPENDED'
  gas_available: boolean
  queue_level: string | null
  cylinder_types: string | null
  confidence_score: number
  created_at: string
}

export interface AdminDashboardData {
  stations: { total: number; active: number; with_gas: number; without_gas: number; stale: number }
  users: { total: number; active: number }
  sms: { total: number; processed: number; invalid: number; failed: number }
  reports: { open: number; under_review: number }
  gas_orders: Record<string, number>
}

export interface PaginatedMeta {
  current_page: number
  last_page: number
  total?: number
}

export interface OperatorStationHistoryItem {
  gas_available: boolean
  queue_level: QueueLevel
  source: string
  created_at: string
}

export interface AnalyticsOverview {
  scope: { type: 'province'; province: string | null } | { type: 'national' }
  searches_by_day: { date: string; total: number }[]
  page_views_by_day: { date: string; total: number }[]
  orders_by_day: { date: string; total: number }[]
  top_municipalities_searched: { name: string; total: number }[]
  stations_by_gas_availability: { name: string; value: number }[]
  stations_by_queue_level: { name: string; value: number }[]
}

/** Painel de métricas do Super Admin (Outubro 2026) — GET /admin/metrics. */
export interface MunicipalityMetricRow {
  municipality_id: number
  municipality: string | null
  province: string | null
  stations_count: number
  seconds_with_gas: number
  label_with_gas: string
  avg_seconds_per_station: number
  avg_label_per_station: string
  requests: number
}

export interface StationGasMetricRow {
  station_id: number
  station: string
  code: string | null
  municipality: string | null
  municipality_id: number
  seconds_with_gas: number
  label: string
  percent: number
}

export interface MunicipalityMetrics {
  period: { from: string; to: string; seconds: number }
  top_gas_availability: StationGasMetricRow[]
  top_requests: MunicipalityMetricRow[]
  municipalities: MunicipalityMetricRow[]
  totals: { stations: number; municipalities: number; requests: number }
  scope: { type: 'national' | 'province'; province: string | null; can_pick_province: boolean }
}

export interface SuperAdminMetrics {
  /** 'province' = Super Admin Provincial (só a sua província). */
  scope?: 'all' | 'province'
  period: { days: 7 | 30 | 90; since: string; until: string }
  orders: {
    total: number
    delivered: number
    cancelled: number
    failed: number
    in_progress: number
    completion_rate: number | null
    cancellation_rate: number | null
    revenue: number
    commission?: number
    average_ticket: number | null
    average_minutes_to_contact: number | null
    average_minutes_to_deliver: number | null
  }
  previous_orders: { total: number; delivered: number; revenue: number }
  orders_by_day: { date: string; total: number; delivered: number; cancelled: number }[]
  orders_by_status: { status: string; label: string; total: number }[]
  orders_by_province: { name: string; total: number; delivered: number }[]
  funnel: { key: string; label: string; total: number }[]
  drivers: {
    total: number
    available: number
    top: { id: number; name: string; delivered: number; cancelled: number; average_rating: number | null; ratings_count: number }[]
  }
  ratings: { count: number; average: number | null; distribution: { stars: number; total: number }[] }
  stations: { active: number; with_gas: number; without_gas: number; stale: number }
  users: { total: number; new_in_period: number; by_role: { role: string; label: string; total: number }[] }
  notifications: {
    sent: number
    read_rate: number | null
    orders_sent: number
    web_push_subscriptions: number
    order_push_subscriptions: number
    device_tokens: number
  }
}

export interface DriverStock {
  picked: number
  delivered: number
  on_hand: number
  reserved: number
  available: number
}

export type PickupState = 'CONFIRMED' | 'DRIVER_ONLY' | 'DISPUTED'

export interface BottlePickup {
  id: number
  client_uuid: string
  station_id: number
  station_name: string | null
  driver_id: number
  driver_name: string | null
  quantity: number
  amount: number
  registered_at: string | null
  synced_offline: boolean
  state: PickupState
  confirmed_by: string | null
  confirmed_at: string | null
  dispute_note: string | null
  station_paid_at: string | null
  /** Número do posto (5 dígitos). */
  station_code?: string | null
  /** APP = o condutor; CENTRAL = um admin registou por ele; SMS. */
  source?: 'APP' | 'CENTRAL' | 'SMS'
  registered_by_id?: number | null
  registered_by_name?: string | null
  central_channel?: 'PHONE' | 'IN_PERSON' | 'SMS' | 'OTHER' | null
  central_reason?: string | null
  /** Registado pela central e ainda sem confirmação (Super Admin, condutor ou posto). */
  awaiting_confirmation?: boolean
}

export interface CentralDriver {
  id: number
  name: string
  phone: string | null
  province: string | null
  central_active: boolean
  central_expires_at: string | null
  central_reason: string | null
  central_enabled_by: string | null
  sms_pin_set: boolean
  /** PIN definido por um admin e ainda não mudado pelo condutor. */
  sms_pin_temporary?: boolean
}

export interface CentralPickupPayload {
  driver_id: number
  station_id: number
  quantity: number
  amount: number
  picked_at?: string
  channel: 'PHONE' | 'IN_PERSON' | 'SMS' | 'OTHER'
  reason: string
}

export interface StationCounter {
  since_reset: number
  pending_confirmation: number
  today: number
  last_reset_at: string | null
  last_reset_bottles: number | null
}

export interface BillingStationRow {
  station_id: number
  station_name: string
  day_pickups: number
  day_bottles: number
  day_total: number
  unpaid_pickups: number
  unpaid_bottles: number
  unpaid_total: number
  unconfirmed_pickups: number
}

export interface BillingDriverRow {
  driver_id: number
  driver_name: string
  phone: string | null
  orders: number
  collected_day: number
  collected_total: number
  received_total: number
  outstanding: number
  stock: DriverStock
}

export interface BillingLegacyOrder {
  id: number
  order_reference: string
  customer_name: string | null
  quantity: number
  delivery_price: number
  completed_at: string | null
}

export interface BillingOverview {
  scope: 'own' | 'province' | 'all'
  date: string
  summary: {
    orders: number
    bottles: number
    collected_total: number
    bottles_total: number
    commission_total: number
    legacy_orders: number
    legacy_total: number
    stations_day_total: number
    stations_unpaid_total: number
  }
  stations: BillingStationRow[]
  daily: { date: string; count: number; collected: number; bottles: number; commission: number }[]
  legacy: { count: number; items: BillingLegacyOrder[] }
  drivers: BillingDriverRow[]
  by_admin?: { admin_id: number; admin_name: string; province?: string | null; total: number; commission: number; count: number }[]
}

export interface BillingLedgerEntry {
  id: number
  action: 'billing.station_paid' | 'billing.station_payment_undone' | 'billing.cash_received' | 'billing.cash_receipt_reversed'
  by: string
  at: string | null
  details: Record<string, unknown>
  undone: boolean
  can_undo: boolean
}

// Central de Operações KeruGás — fluxo 100% manual (ver
// docs/central-operacoes.md). Nunca confundir com receção automática
// de SMS: `Operation` representa um atendimento que um admin iniciou
// manualmente depois de ler um SMS no telefone físico da Central.
export type OperationStatus = 'DRAFT' | 'SENT' | 'CANCELLED'
export type SmsDispatchStatus = 'PENDING' | 'QUEUED' | 'SENT' | 'DELIVERED' | 'FAILED' | 'UNKNOWN'
export type SmsTemplateType =
  | 'GENERAL' | 'AVAILABILITY' | 'NO_GAS' | 'DELIVERY_REQUEST' | 'STATION_INFO' | 'NOT_REGISTERED'
  | 'SUBSCRIPTION_REQUIRED' | 'SUBSCRIPTION_EXPIRED' | 'SUBSCRIPTION_ACTIVATED' | 'OPERATOR_ANALYTICS'
  | 'FREE_CONSULTS_LOW' | 'FREE_CONSULTS_EXHAUSTED' | 'FREE_CONSULTS_RENEWED' | 'MUNICIPALITY_PAID'
  | 'ACCESS_GRANTED' | 'ACCESS_REVOKED' | 'STATION_OFF_MAP' | 'ORDER_TAKEN_BY_OTHER'

/** Tipos de template que entregam uma consulta de gás (gastam consultas grátis). */
export const CONSULT_TEMPLATE_TYPES: SmsTemplateType[] = ['AVAILABILITY', 'NO_GAS', 'STATION_INFO']

// --- Subscrições mensais (Outubro 2026) ---
/**
 * STATION_ANALYTICS está DESCONTINUADO desde Novembro de 2026: o analytics
 * passou a vir incluído em STATION_LISTING. Fica no tipo só para os registos
 * antigos continuarem a ler-se e a aparecer na listagem.
 */
export type SubscriptionKind = 'SMS_CONSULT' | 'STATION_ANALYTICS' | 'STATION_ORDERS' | 'STATION_LISTING'
export type SubscriptionState = 'ACTIVE' | 'EXPIRED' | 'CANCELLED' | 'SCHEDULED'

export interface Subscription {
  id: number
  type: SubscriptionKind
  type_label: string
  phone: string | null
  station: { id: number; name: string; operator_name: string | null } | null
  state: SubscriptionState
  starts_at: string
  expires_at: string
  months: number
  /** Pedidos do município (Novembro 2026): blocos de semanas. */
  weeks?: number | null
  amount_kz: number | null
  payment_reference: string | null
  notes: string | null
  activated_by: string | null
  cancelled_at: string | null
  created_at: string
}

export interface SubscriptionSummary {
  active_sms: number
  active_stations: number
  active_station_orders?: number
  expiring_7d: number
  /** Postos com operador que não apareceriam se a presença já fosse exigida. */
  stations_listing_blocked: number
}

export interface SubscriptionSettings {
  sms_enforcement: boolean
  free_consults_per_month: number
  bank_name: string | null
  account_holder: string | null
  iban: string | null
  contact: string | null
  price_sms_kz: number | null
  price_analytics_kz: number | null
  /** Pedidos do município: preço por SEMANA (Novembro 2026). */
  price_station_orders_week_kz?: number
  /** Presença do posto na aplicação — traz o analytics incluído. */
  price_station_listing_week_kz?: number | null
  /** Exigir presença paga. Desligado por omissão. */
  listing_enforcement?: boolean
  /** Dias de tolerância depois de a presença expirar. */
  listing_grace_days?: number
}

/** Subscrição do posto para receber os pedidos do município (Novembro 2026). */
export interface StationOrdersSubscriptionInfo {
  active: boolean
  can_accept: boolean
  override: 'INHERIT' | 'ALLOW' | 'BLOCK'
  override_reason: string | null
  listing_enforced: boolean
  listing_visible: boolean
  valid_until: string | null
  municipality: string | null
  price_per_week_kz: number
  price_label: string
  payment: { bank_name: string | null; account_holder: string | null; iban: string | null; contact: string }
}

export interface StationOrdersPage {
  data: GasOrder[]
  meta: {
    current_page: number
    last_page: number
    total: number
    scope: 'open' | 'mine'
    subscription: StationOrdersSubscriptionInfo
    drivers: { id: number; name: string; phone: string; available: boolean }[]
  }
}

/** Situação de um telemóvel perante o limite de consultas grátis. */
export interface SmsSubscriptionStatus {
  enforced: boolean
  limit: number
  used: number
  remaining: number
  subscribed: boolean
  valid_until: string | null
  blocked: boolean
  suggested_template_code: string | null
  suggested_template_id?: number | null
}

export interface OperatorSubscriptionInfo {
  /** Visível na aplicação — e, desde Novembro de 2026, com analytics incluído. */
  visible: boolean
  listing_enforced: boolean
  grace_until: string | null
  subscribed: boolean
  valid_until: string | null
  payment: {
    bank_name: string | null
    account_holder: string | null
    iban: string | null
    contact: string
    price: string | null
  }
}

export interface SmsTemplate {
  id: number
  name: string
  code: string
  description: string | null
  content: string
  type: SmsTemplateType
  active: boolean
  created_at: string
  updated_at: string
}

export interface Operation {
  id: number
  admin_id: number
  phone: string
  province_id: number | null
  municipality_id: number | null
  original_message: string | null
  sms_template_id: number | null
  status: OperationStatus
  created_at: string
}

export interface OperationStationOption {
  id: number
  name: string
  /** Número do posto (00001...). */
  code?: string | null
  slug: string
  address?: string | null
  reference_point: string | null
  gas_available: boolean
  queue_level: QueueLevel | null
  queue_level_label: string | null
  status_updated_at: string | null
  freshness: Freshness
}

export interface SmsPreview {
  recipient: string
  province: string | null
  municipality: string | null
  template: { id: number; name: string; type: SmsTemplateType }
  /** Posto escolhido no template "Informação de posto" (Novembro 2026). */
  station?: { id: number; name: string; code: string | null } | null
  message: string
  characters: number
  encoding: 'GSM-7' | 'UCS-2'
  segments: number
}

export interface SmsDispatch {
  id: number
  provider: string
  provider_message_id: string | null
  recipient: string
  message: string
  sms_template_id: number | null
  operation_id: number | null
  status: SmsDispatchStatus
  error_code: string | null
  error_message: string | null
  sent_at: string | null
  delivered_at: string | null
  failed_at: string | null
  created_at: string
  template?: SmsTemplate
  creator?: { id: number; name: string }
}

export interface SmsStats {
  total_sent: number
  total_delivered: number
  total_failed: number
  success_rate: number
  operations_processed: number
  most_consulted_municipalities: { municipality: string | null; total: number }[]
}

// Saldo de créditos do provedor de SMS (Setembro 2026) — só a Kamba
// tem noção de créditos; `supported: false` para mock/production.
export interface SmsCredits {
  supported: boolean
  provider: string
  balance: number | null
  message: string | null
}

// Mensagens de contacto do site (Setembro 2026)
export interface ContactMessage {
  id: number
  name: string
  contact: string
  message: string
  status: 'NEW' | 'READ'
  created_at: string
}

// Uso de SMS por Admin (Setembro 2026) — monitorização/auditoria de
// custos, estritamente Super Admin (ver SmsDispatchController::usageByAdmin()).
export interface SmsAdminUsage {
  id: number
  name: string
  province: string | null
  daily_limit: number | null
  sent_today: number
  remaining_today: number | null
  sent_this_month: number
  last_sent_at: string | null
}

export interface OperatorAnalytics {
  kpis: {
    total_updates_30d: number
    updates_today: number
    current_confidence_score: number
    avg_confidence_score_30d: number | null
    gas_available_percentage_30d: number | null
    most_common_queue_level: string | null
  }
  updates_by_day: { date: string; total: number }[]
  confidence_trend: { date: string; confidence_score: number }[]
  gas_availability_ratio: { name: string; value: number }[]
  queue_level_distribution: { name: string; value: number }[]
  source_breakdown: { name: string; value: number }[]
}

// Mapa de calor da procura (Outubro 2026)
export interface DemandOverview {
  scope: 'all' | 'province'
  days: 7 | 30 | 90
  totals: { orders: number; delivered: number; cancelled: number; bottles: number; with_location: number; location_rate: number | null }
  peak: { hour: number | null; weekday: string | null }
  points: { lat: number; lng: number; count: number; delivered: number }[]
  weekday_hour: { weekdays: string[]; grid: number[][] }
  by_hour: number[]
  neighborhoods: { neighborhood: string; municipality: string | null; orders: number; delivered: number; bottles: number }[]
  municipalities: { municipality: string; orders: number; delivered: number }[]
  stations: { id: number; name: string; lat: number; lng: number }[]
}

// Admin de Posto / gerente (Novembro 2026)
export interface StationStaffPerson {
  id: number
  name: string
  phone: string
  status: string
  available?: boolean
}

export interface StationManagerOverview {
  station: AdminStation
  operators: StationStaffPerson[]
  managers: StationStaffPerson[]
  drivers: StationStaffPerson[]
}
