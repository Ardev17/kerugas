import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import OrderGas from '../OrderGas'

const create = vi.fn()
vi.mock('@/services/gasOrderService', () => ({ gasOrderService: { create: (...a: unknown[]) => create(...a) } }))
vi.mock('@/services/territoryService', () => ({
  territoryService: {
    listProvinces: async () => [{ id: 1, name: 'Huíla' }],
    listMunicipalities: async () => [{ id: 10, name: 'Lubango', province_id: 1 }],
    locate: async () => null,
  },
}))

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <OrderGas />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Pedir gás,passo de confirmação', () => {
  it('só envia o pedido ao carregar em "Enviar pedido", nunca em "Continuar"', async () => {
    create.mockResolvedValue({ reference: 'KG-1' })
    renderPage()
    fireEvent.change(screen.getByLabelText('Nome completo'), { target: { value: 'Maria João' } })
    fireEvent.change(screen.getByLabelText('Número de telefone'), { target: { value: '923456789' } })
    fireEvent.click(screen.getByRole('button', { name: /continuar/i }))

    await waitFor(() => expect((screen.getByLabelText('Província') as HTMLSelectElement).options.length).toBeGreaterThan(1))
    fireEvent.change(screen.getByLabelText('Província'), { target: { value: '1' } })
    await waitFor(() => expect((screen.getByLabelText('Município') as HTMLSelectElement).options.length).toBeGreaterThan(1))
    fireEvent.change(screen.getByLabelText('Município'), { target: { value: '10' } })
    fireEvent.click(screen.getByRole('button', { name: /continuar/i })) // -> quantidade
    fireEvent.click(screen.getByRole('button', { name: /continuar/i })) // -> confirmação

    expect(await screen.findByText(/confirme os dados do seu pedido/i)).toBeTruthy()
    expect(create).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: /enviar pedido/i }))
    await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
    expect(create.mock.calls[0][0]).toMatchObject({ location_source: 'MANUAL', province_id: 1, municipality_id: 10 })
  })

  it('Enter num campo avança o passo em vez de enviar', async () => {
    renderPage()
    create.mockClear()
    fireEvent.change(screen.getByLabelText('Nome completo'), { target: { value: 'Maria João' } })
    fireEvent.change(screen.getByLabelText('Número de telefone'), { target: { value: '923456789' } })
    fireEvent.submit(screen.getByLabelText('Nome completo').closest('form')!)
    expect(await screen.findByRole('button', { name: /partilhar minha localização/i })).toBeTruthy()
    expect(create).not.toHaveBeenCalled()
  })
})
