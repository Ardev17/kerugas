import { api } from '@/lib/api'

export interface BackupItem {
  id: number
  status: 'pending' | 'running' | 'completed' | 'failed'
  trigger: 'manual' | 'scheduled' | 'pre_restore'
  include_files: boolean
  db_bytes: number | null
  files_bytes: number | null
  files_count: number | null
  has_files: boolean
  downloadable: boolean
  purged: boolean
  error: string | null
  warning: string | null
  requested_by: string | null
  created_at: string
  started_at: string | null
  finished_at: string | null
}

export interface BackupList {
  data: BackupItem[]
  meta: { busy: boolean; keep: number; free_bytes: number | null; auto_enabled: boolean; auto_time: string | null }
}

/** Cópias de segurança (Outubro 2026),só o Super Admin geral. */
export const backupService = {
  async list(): Promise<BackupList> {
    const { data } = await api.get<BackupList>('/admin/backups')
    return data
  },
  async start(includeFiles: boolean): Promise<BackupItem> {
    const { data } = await api.post<{ data: BackupItem }>('/admin/backups', { include_files: includeFiles })
    return data.data
  },
  async link(id: number, kind: 'db' | 'files'): Promise<string> {
    const { data } = await api.get<{ data: { url: string } }>(`/admin/backups/${id}/link`, { params: { kind } })
    return data.data.url
  },
  async remove(id: number): Promise<void> {
    await api.delete(`/admin/backups/${id}`)
  },
}
