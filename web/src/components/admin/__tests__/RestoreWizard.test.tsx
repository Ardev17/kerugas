import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

const fromBackup = vi.fn()
const status = vi.fn()
vi.mock('@/services/restoreService', () => ({
  restoreService: {
    fromBackup: (...a: unknown[]) => fromBackup(...a),
    status: (...a: unknown[]) => status(...a),
    cancel: vi.fn(() => Promise.resolve()),
  },
}))

import { RestoreWizard } from '../RestoreWizard'

const source = { kind: 'backup' as const, id: 7, label: '07/10/2026 · 18 KB', hasFiles: true }

describe('RestoreWizard', () => {
  beforeEach(() => {
    fromBackup.mockReset()
    status.mockReset()
  })

  it('só deixa restaurar com a palavra-passe e a palavra RESTAURAR', async () => {
    render(<MemoryRouter><RestoreWizard source={source} onClose={() => {}} /></MemoryRouter>)
    const go = screen.getByRole('button', { name: /restaurar agora/i })
    expect(go).toBeDisabled()

    await userEvent.type(screen.getByLabelText(/palavra-passe/i), 'segredo')
    await userEvent.type(screen.getByLabelText(/para confirmar/i), 'restaurar')
    expect(go).toBeDisabled() // minúsculas não contam

    await userEvent.clear(screen.getByLabelText(/para confirmar/i))
    await userEvent.type(screen.getByLabelText(/para confirmar/i), 'RESTAURAR')
    expect(go).toBeEnabled()
  })

  it('acompanha o restauro até ao fim e pede para entrar de novo', async () => {
    fromBackup.mockResolvedValue('https://api/restores/x/status?signature=1')
    status.mockResolvedValue({ state: 'done', stage: 'done', message: 'Restauro concluído.', error: null, progress: 10, summary: null, safety_file: 'pre-restauro-1.sql.gz', started_at: null, finished_at: null })

    render(<MemoryRouter><RestoreWizard source={source} onClose={() => {}} /></MemoryRouter>)
    await userEvent.type(screen.getByLabelText(/palavra-passe/i), 'segredo')
    await userEvent.type(screen.getByLabelText(/para confirmar/i), 'RESTAURAR')
    await userEvent.click(screen.getByRole('button', { name: /restaurar agora/i }))

    expect(fromBackup).toHaveBeenCalledWith(7, 'segredo', 'RESTAURAR', true)
    await waitFor(() => expect(screen.getByText('Restauro concluído.')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: /entrar de novo/i })).toBeInTheDocument()
  })

  it('mostra o motivo quando falha (e que o estado anterior foi reposto)', async () => {
    fromBackup.mockResolvedValue('u')
    status.mockResolvedValue({ state: 'failed', stage: 'failed', message: 'O restauro falhou e o estado anterior foi reposto,o site está como estava.', error: 'Erro na instrução 3', progress: 0, summary: null, safety_file: null, started_at: null, finished_at: null })

    render(<MemoryRouter><RestoreWizard source={source} onClose={() => {}} /></MemoryRouter>)
    await userEvent.type(screen.getByLabelText(/palavra-passe/i), 'x')
    await userEvent.type(screen.getByLabelText(/para confirmar/i), 'RESTAURAR')
    await userEvent.click(screen.getByRole('button', { name: /restaurar agora/i }))

    await waitFor(() => expect(screen.getByText(/estado anterior foi reposto/i)).toBeInTheDocument())
    expect(screen.getByText('Erro na instrução 3')).toBeInTheDocument()
  })
})
