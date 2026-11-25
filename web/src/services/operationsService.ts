import { api } from '@/lib/api'
import type {
  ApiSuccess, Operation, OperationStationOption, PaginatedMeta,
  SmsAdminUsage, SmsCredits, SmsDispatch, SmsPreview, SmsStats, SmsSubscriptionStatus, SmsTemplate,
} from '@/types'

interface Paginated<T> {
  success: true
  data: T[]
  meta: PaginatedMeta
}

/**
 * Central de Operações KeruGás. Todo o fluxo aqui é iniciado
 * manualmente por um admin/super admin,nunca a partir de um SMS
 * recebido automaticamente. Ver docs/central-operacoes.md.
 */
export const operationsService = {
  async startOperation(payload: {
    phone: string
    province_id?: number | null
    municipality_id?: number | null
    original_message?: string | null
  }): Promise<Operation> {
    const { data } = await api.post<ApiSuccess<Operation>>('/admin/operations', payload)
    return data.data
  },

  async stationsFor(operationId: number): Promise<OperationStationOption[]> {
    const { data } = await api.get<ApiSuccess<OperationStationOption[]>>(`/admin/operations/${operationId}/stations`)
    return data.data
  },

  /** `stationId`: só para o template "Informação de posto",um posto por mensagem (Novembro 2026). */
  async preview(operationId: number, smsTemplateId: number, stationId?: number | null): Promise<SmsPreview> {
    const { data } = await api.post<ApiSuccess<SmsPreview>>(`/admin/operations/${operationId}/preview`, {
      sms_template_id: smsTemplateId,
      ...(stationId ? { station_id: stationId } : {}),
    })
    return data.data
  },

  async send(operationId: number, smsTemplateId: number, stationId?: number | null): Promise<SmsDispatch> {
    const { data } = await api.post<ApiSuccess<SmsDispatch>>(`/admin/operations/${operationId}/send`, {
      sms_template_id: smsTemplateId,
      ...(stationId ? { station_id: stationId } : {}),
    })
    return data.data
  },

  /** Consultas grátis gastas pelo cliente este mês e se a Central o bloqueia. */
  async subscriptionStatus(operationId: number): Promise<SmsSubscriptionStatus> {
    const { data } = await api.get<ApiSuccess<SmsSubscriptionStatus>>(`/admin/operations/${operationId}/subscription`)
    return data.data
  },

  async cancel(operationId: number): Promise<Operation> {
    const { data } = await api.post<ApiSuccess<Operation>>(`/admin/operations/${operationId}/cancel`)
    return data.data
  },

  // Templates
  async listTemplates(): Promise<{ data: SmsTemplate[]; allowedVariables: string[] }> {
    const { data } = await api.get<ApiSuccess<SmsTemplate[]> & { meta: { allowed_variables: string[] } }>('/admin/sms-templates')
    return { data: data.data, allowedVariables: data.meta.allowed_variables }
  },
  async createTemplate(payload: Partial<SmsTemplate>): Promise<SmsTemplate> {
    const { data } = await api.post<ApiSuccess<SmsTemplate>>('/admin/sms-templates', payload)
    return data.data
  },
  async updateTemplate(id: number, payload: Partial<SmsTemplate>): Promise<SmsTemplate> {
    const { data } = await api.put<ApiSuccess<SmsTemplate>>(`/admin/sms-templates/${id}`, payload)
    return data.data
  },
  async deactivateTemplate(id: number): Promise<SmsTemplate> {
    const { data } = await api.delete<ApiSuccess<SmsTemplate>>(`/admin/sms-templates/${id}`)
    return data.data
  },

  // Histórico e relatórios
  async history(params: {
    status?: string
    recipient?: string
    date_from?: string
    date_to?: string
    page?: number
  } = {}): Promise<Paginated<SmsDispatch>> {
    const { data } = await api.get<Paginated<SmsDispatch>>('/admin/sms/history', { params })
    return data
  },

  async stats(params: { date_from?: string; date_to?: string } = {}): Promise<SmsStats> {
    const { data } = await api.get<ApiSuccess<SmsStats>>('/admin/sms/stats', { params })
    return data.data
  },

  /**
   * Saldo de créditos do provedor de SMS (Setembro 2026),estritamente
   * Super Admin (o backend recusa, 403, a um Admin comum mesmo tendo
   * `sms.view`), para alertar antes de ficar sem saldo a meio de um
   * envio real. Só devolve `balance` quando `SMS_PROVIDER=kamba`.
   */
  async credits(): Promise<SmsCredits> {
    const { data } = await api.get<ApiSuccess<SmsCredits>>('/admin/sms/credits')
    return data.data
  },

  /**
   * Uso de SMS por Admin (Setembro 2026),monitorização/auditoria de
   * custos, estritamente Super Admin (o backend recusa, 403, a um Admin
   * comum). Mostra quantos SMS cada Admin enviou hoje/este mês face ao
   * seu limite diário configurado (ver adminService.updateUser()).
   */
  async usageByAdmin(): Promise<SmsAdminUsage[]> {
    const { data } = await api.get<ApiSuccess<SmsAdminUsage[]>>('/admin/sms/usage-by-admin')
    return data.data
  },
}
