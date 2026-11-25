import { api } from '@/lib/api'

export interface RestoreSummary {
  db_file: string
  db_bytes: number
  photos_mode: 'zip' | 'folders' | null
  photos: number | null
  database: string
  current: { tables: number | null; users: number | null }
}

export type RestoreState = 'uploading' | 'verified' | 'ready' | 'queued' | 'running' | 'done' | 'failed'

export interface RestoreStatus {
  state: RestoreState
  stage: string | null
  message: string | null
  error: string | null
  progress: number
  summary: RestoreSummary | null
  safety_file: string | null
  started_at: string | null
  finished_at: string | null
}

/** Restaurar uma cópia pelo painel (Outubro 2026),só o Super Admin geral. */
export const restoreService = {
  async create(): Promise<{ id: string; chunk_bytes: number }> {
    const { data } = await api.post<{ data: { id: string; chunk_bytes: number } }>('/admin/restores')
    return data.data
  },
  async chunk(id: string, form: FormData): Promise<{ received: number }> {
    const { data } = await api.post<{ data: { received: number } }>(`/admin/restores/${id}/chunk`, form)
    return data.data
  },
  async verify(id: string): Promise<RestoreSummary> {
    const { data } = await api.post<{ data: RestoreSummary }>(`/admin/restores/${id}/verify`, null, { timeout: 0 })
    return data.data
  },
  async start(id: string, password: string, confirm: string): Promise<string> {
    const { data } = await api.post<{ data: { status_url: string } }>(`/admin/restores/${id}/start`, { password, confirm })
    return data.data.status_url
  },
  async fromBackup(backupId: number, password: string, confirm: string, includeFiles: boolean): Promise<string> {
    const { data } = await api.post<{ data: { status_url: string } }>(`/admin/backups/${backupId}/restore`, { password, confirm, include_files: includeFiles })
    return data.data.status_url
  },
  async cancel(id: string): Promise<void> {
    await api.delete(`/admin/restores/${id}`)
  },
  /** Link assinado: funciona sem sessão e com o site em manutenção. */
  async status(url: string): Promise<RestoreStatus> {
    const { data } = await api.get<{ data: RestoreStatus }>(url)
    return data.data
  },
}
