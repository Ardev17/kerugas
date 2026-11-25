import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminBackups from '../AdminBackups'
import type { BackupItem, BackupList } from '@/services/backupService'

const list = vi.hoisted(() => vi.fn())
const start = vi.hoisted(() => vi.fn())
const link = vi.hoisted(() => vi.fn())
const remove = vi.hoisted(() => vi.fn())

vi.mock('@/services/backupService', () => ({ backupService: { list, start, link, remove } }))

const item = (extra: Partial<BackupItem> = {}): BackupItem => ({
  id: 1, status: 'completed', trigger: 'manual', include_files: true, db_bytes: 5_242_880, files_bytes: 2_097_152, files_count: 12,
  has_files: true, downloadable: true, purged: false, error: null, warning: null, requested_by: 'Aristides', created_at: '2026-10-09T08:00:00Z',
  started_at: '2026-10-09T08:00:01Z', finished_at: '2026-10-09T08:03:00Z', ...extra,
})
const overview = (items: BackupItem[], busy = false): BackupList => ({
  data: items,
  meta: { busy, keep: 7, free_bytes: 50 * 1024 ** 3, auto_enabled: false, auto_time: '02:30' },
})

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><AdminBackups /></QueryClientProvider>)
}

describe('AdminBackups', () => {
  beforeEach(() => {
    list.mockReset().mockResolvedValue(overview([item()]))
    start.mockReset().mockResolvedValue(item({ id: 2, status: 'pending' }))
    link.mockReset().mockResolvedValue('https://exemplo.ao/api/v1/backups/1/download/db?signature=x')
    remove.mockReset().mockResolvedValue(undefined)
  })

  it('mostra o histórico com tamanhos e quem pediu', async () => {
    renderPage()
    expect(await screen.findByText('Concluída')).toBeInTheDocument()
    expect(screen.getByText(/Pedida por Aristides/)).toBeInTheDocument()
    expect(screen.getByText('5.0 MB')).toBeInTheDocument()
    expect(screen.getByText('Espaço livre:', { exact: false })).toBeInTheDocument()
  })

  it('o botão inicia a cópia (com fotos por omissão) e avisa que continua em segundo plano', async () => {
    renderPage()
    await screen.findByText('Concluída')
    await userEvent.click(screen.getByRole('button', { name: /Fazer backup/ }))

    await waitFor(() => expect(start).toHaveBeenCalledWith(true))
    expect(await screen.findByRole('status')).toHaveTextContent(/segundo plano/)
  })

  it('pode fazer a cópia só da base de dados', async () => {
    renderPage()
    await screen.findByText('Concluída')
    await userEvent.click(screen.getByLabelText(/Incluir as fotos/))
    await userEvent.click(screen.getByRole('button', { name: /Fazer backup/ }))

    await waitFor(() => expect(start).toHaveBeenCalledWith(false))
  })

  it('com uma cópia a decorrer o botão fica desativado', async () => {
    list.mockResolvedValue(overview([item({ id: 3, status: 'running' })], true))
    renderPage()

    const button = await screen.findByRole('button', { name: /A fazer a cópia/ })
    expect(button).toBeDisabled()
    expect(screen.getByText('A decorrer')).toBeInTheDocument()
  })

  it('mostra o motivo quando a cópia falhou', async () => {
    list.mockResolvedValue(overview([item({ status: 'failed', error: 'mysqldump: Access denied', downloadable: false, db_bytes: null })]))
    renderPage()

    expect(await screen.findByText('Falhou')).toBeInTheDocument()
    expect(screen.getByText('mysqldump: Access denied')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Base de dados/ })).not.toBeInTheDocument()
  })

  it('mostra erro quando já há uma cópia a decorrer no servidor', async () => {
    start.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { message: 'Já existe uma cópia de segurança a decorrer.' } } })
    renderPage()
    await screen.findByText('Concluída')
    await userEvent.click(screen.getByRole('button', { name: /Fazer backup/ }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/Já existe uma cópia/)
  })

  it('pede um link temporário ao descarregar', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: /Base de dados/ }))

    await waitFor(() => expect(link).toHaveBeenCalledWith(1, 'db'))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://exemplo.ao/api/v1/backups/1/download/db?signature=x'))
    vi.unstubAllGlobals()
  })

  it('só apaga depois de confirmar', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    renderPage()
    const trash = await screen.findByRole('button', { name: 'Apagar cópia' })

    await userEvent.click(trash)
    expect(remove).not.toHaveBeenCalled()
    await userEvent.click(trash)
    await waitFor(() => expect(remove).toHaveBeenCalledWith(1))
    confirm.mockRestore()
  })
})
