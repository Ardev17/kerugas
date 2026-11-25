import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminOperationsCenter from '../AdminOperationsCenter'

vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: 'SUPER_ADMIN', name: 'Ardev', province_id: null } }) }))

const ops = vi.hoisted(() => ({
  listTemplates: vi.fn(), startOperation: vi.fn(), stationsFor: vi.fn(), preview: vi.fn(), send: vi.fn(),
  subscriptionStatus: vi.fn(), stats: vi.fn(), history: vi.fn(),
}))
vi.mock('@/services/operationsService', () => ({ operationsService: ops }))
const territory = vi.hoisted(() => ({ listProvinces: vi.fn(), listMunicipalities: vi.fn() }))
vi.mock('@/services/territoryService', () => ({ territoryService: territory }))

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><AdminOperationsCenter /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Central de Operações,informação de um posto por SMS (Novembro 2026)', () => {
  beforeEach(() => {
    Object.values(ops).forEach((f) => f.mockReset())
    territory.listProvinces.mockResolvedValue([{ id: 1, name: 'Benguela' }])
    territory.listMunicipalities.mockResolvedValue([{ id: 5, name: 'Lobito' }])
    ops.listTemplates.mockResolvedValue({
      data: [
        { id: 10, name: 'Informação de posto', code: 'STATION_INFO', type: 'STATION_INFO', content: 'x', active: true, description: null },
        { id: 11, name: 'Sem gás', code: 'NO_GAS', type: 'NO_GAS', content: 'y', active: true, description: null },
      ],
      allowedVariables: [],
    })
    ops.startOperation.mockResolvedValue({ id: 99, phone: '+244923000111', province_id: 1, municipality_id: 5 })
    ops.stationsFor.mockResolvedValue([
      { id: 7, name: 'Posto Restinga', code: '00007', slug: 'r', reference_point: null, gas_available: true, queue_level: null, queue_level_label: null, status_updated_at: null, freshness: 'FRESH' },
      { id: 8, name: 'Posto Compão', code: '00008', slug: 'c', reference_point: null, gas_available: false, queue_level: null, queue_level_label: null, status_updated_at: null, freshness: 'FRESH' },
    ])
    ops.subscriptionStatus.mockResolvedValue({ blocked: false, used: 0, limit: 3, enforced: false, has_subscription: false, suggested_template_id: null })
    ops.preview.mockResolvedValue({
      recipient: '+244923000111', province: 'Benguela', municipality: 'Lobito',
      template: { id: 10, name: 'Informação de posto', type: 'STATION_INFO' },
      station: { id: 8, name: 'Posto Compão', code: '00008' },
      message: 'KERUGAS: Posto Compao (no 00008, Lobito). Gas: sem gas.', characters: 55, encoding: 'GSM-7', segments: 1,
    })
  })

  it('escolhe-se o posto do município (um por mensagem) e a pré-visualização mostra o contador', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.type(screen.getByPlaceholderText(/923123456/), '923000111')
    await user.click(screen.getByRole('button', { name: /Continuar/ }))

    await user.selectOptions(await screen.findByLabelText('Template'), '10')
    const previewBtn = screen.getByRole('button', { name: /Gerar pré-visualização/ })
    expect(previewBtn).toBeDisabled()

    const stationSelect = await screen.findByLabelText(/Posto/, { selector: 'select' })
    await waitFor(() => expect(screen.getAllByRole('option', { name: /Posto Compão/ }).length).toBeGreaterThan(0))
    await user.selectOptions(stationSelect, '8')
    await user.click(previewBtn)

    await waitFor(() => expect(ops.preview).toHaveBeenCalledWith(99, 10, 8))
    expect(await screen.findByText('55 caracteres')).toBeInTheDocument()
    expect(screen.getByText(/Acentos retirados no envio/)).toBeInTheDocument()
  })

  it('os outros templates não pedem posto', async () => {
    const user = userEvent.setup()
    renderPage()
    await user.type(screen.getByPlaceholderText(/923123456/), '923000111')
    await user.click(screen.getByRole('button', { name: /Continuar/ }))
    await user.selectOptions(await screen.findByLabelText('Template'), '11')

    expect(screen.queryByLabelText(/Posto/, { selector: 'select' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /Gerar pré-visualização/ }))
    await waitFor(() => expect(ops.preview).toHaveBeenCalledWith(99, 11, null))
  })
})
