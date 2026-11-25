import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { OrderPushPrompt } from '../OrderPushPrompt'

const auth = { isAuthenticated: false }
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))

const push = vi.hoisted(() => ({
  supported: true,
  getOrderPushStatus: vi.fn(),
  enableOrderPush: vi.fn(),
  disableOrderPush: vi.fn(),
  getWebPushStatus: vi.fn(),
  enableWebPush: vi.fn(),
  disableWebPush: vi.fn(),
}))
vi.mock('@/lib/webPush', () => ({
  isWebPushSupported: () => push.supported,
  getOrderPushStatus: push.getOrderPushStatus,
  enableOrderPush: push.enableOrderPush,
  disableOrderPush: push.disableOrderPush,
  getWebPushStatus: push.getWebPushStatus,
  enableWebPush: push.enableWebPush,
  disableWebPush: push.disableWebPush,
}))

beforeEach(() => {
  vi.clearAllMocks()
  push.supported = true
  auth.isAuthenticated = false
  push.getOrderPushStatus.mockResolvedValue('available')
  push.getWebPushStatus.mockResolvedValue('available')
})

describe('OrderPushPrompt', () => {
  it('sem conta: subscreve só este pedido, com o telefone como prova', async () => {
    push.enableOrderPush.mockResolvedValue('enabled')
    render(<OrderPushPrompt reference="KG-1" phone="923456789" />)

    fireEvent.click(await screen.findByRole('button', { name: /receber avisos deste pedido/i }))

    await waitFor(() => expect(push.enableOrderPush).toHaveBeenCalledWith('KG-1', '923456789'))
    expect(push.enableWebPush).not.toHaveBeenCalled()
    expect(await screen.findByRole('button', { name: /avisos ativados/i })).toBeTruthy()
  })

  it('com conta: liga os avisos da conta', async () => {
    auth.isAuthenticated = true
    push.enableWebPush.mockResolvedValue('enabled')
    render(<OrderPushPrompt reference="KG-1" phone={null} />)

    fireEvent.click(await screen.findByRole('button', { name: /receber avisos deste pedido/i }))

    await waitFor(() => expect(push.enableWebPush).toHaveBeenCalled())
    expect(push.enableOrderPush).not.toHaveBeenCalled()
  })

  it('desativar volta a mostrar o botão de ativar', async () => {
    push.getOrderPushStatus.mockResolvedValue('enabled')
    render(<OrderPushPrompt reference="KG-1" phone="923456789" />)

    fireEvent.click(await screen.findByRole('button', { name: /avisos ativados/i }))

    await waitFor(() => expect(push.disableOrderPush).toHaveBeenCalledWith('KG-1', '923456789'))
    expect(await screen.findByRole('button', { name: /receber avisos deste pedido/i })).toBeTruthy()
  })

  it('não mostra nada sem suporte do navegador, sem Web Push no servidor ou sem telefone nem conta', async () => {
    push.supported = false
    const a = render(<OrderPushPrompt reference="KG-1" phone="923456789" />)
    expect(a.container.innerHTML).toBe('')
    a.unmount()

    push.supported = true
    push.getOrderPushStatus.mockResolvedValue('unavailable')
    const b = render(<OrderPushPrompt reference="KG-1" phone="923456789" />)
    await waitFor(() => expect(push.getOrderPushStatus).toHaveBeenCalled())
    expect(b.container.innerHTML).toBe('')
    b.unmount()

    push.getOrderPushStatus.mockClear()
    const c = render(<OrderPushPrompt reference="KG-1" phone={null} />)
    expect(c.container.innerHTML).toBe('')
    expect(push.getOrderPushStatus).not.toHaveBeenCalled()
  })

  it('permissão bloqueada: explica como a permitir em vez de um botão que não funciona', async () => {
    push.getOrderPushStatus.mockResolvedValue('denied')
    render(<OrderPushPrompt reference="KG-1" phone="923456789" />)

    expect(await screen.findByText(/notificações estão bloqueadas/i)).toBeTruthy()
    expect(screen.queryByRole('button')).toBeNull()
  })

  it('falha de rede: avisa e o botão continua disponível', async () => {
    push.enableOrderPush.mockRejectedValue(new Error('rede'))
    render(<OrderPushPrompt reference="KG-1" phone="923456789" />)

    fireEvent.click(await screen.findByRole('button', { name: /receber avisos deste pedido/i }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByRole('button', { name: /receber avisos deste pedido/i })).toBeTruthy()
  })
})
