import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AppUpdateGate } from '../components/AppUpdateGate'
import { useAppUpdateStore } from '@/stores/appUpdateStore'

const STORE = 'https://play.google.com/store/apps/details?id=ao.kerugas.app'

describe('AppUpdateGate', () => {
  beforeEach(() => useAppUpdateStore.setState({ required: false, available: false, dismissed: false, storeUrl: null }))

  it('versão abaixo da mínima: ecrã bloqueante com o link da loja', () => {
    useAppUpdateStore.getState().setRequired(STORE)
    render(<AppUpdateGate />)
    expect(screen.getByRole('alertdialog')).toHaveTextContent('Atualize a KeruGás')
    expect(screen.getByRole('link', { name: 'Atualizar agora' })).toHaveAttribute('href', STORE)
  })

  it('versão nova disponível: aviso que se pode fechar', async () => {
    useAppUpdateStore.getState().setAvailable(true, STORE)
    render(<AppUpdateGate />)
    expect(screen.getByRole('status')).toHaveTextContent('nova versão')
    await userEvent.click(screen.getByRole('button', { name: 'Fechar aviso de atualização' }))
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('nada a mostrar quando está atualizado', () => {
    const { container } = render(<AppUpdateGate />)
    expect(container).toBeEmptyDOMElement()
  })
})
