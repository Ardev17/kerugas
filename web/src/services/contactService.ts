import { api } from '@/lib/api'
import type { ApiSuccess } from '@/types'

/**
 * Formulário de Contacto público do site (Setembro 2026),substitui o
 * protótipo anterior que só fazia setSent(true) sem enviar nada.
 */
export const contactService = {
  async send(payload: { name: string; contact: string; message: string; website?: string }): Promise<void> {
    await api.post<ApiSuccess<null>>('/contact', payload)
  },
}
