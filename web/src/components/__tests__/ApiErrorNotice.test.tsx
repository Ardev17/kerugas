import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError } from 'axios'
import { ApiErrorNotice } from '../ApiErrorNotice'

describe('aviso de falha da API', () => {
  it('sem resposta do servidor diz que não há ligação e deixa tentar outra vez', async () => {
    const retry = vi.fn()
    render(<ApiErrorNotice error={new AxiosError('Network Error', 'ERR_NETWORK')} onRetry={retry} />)
    expect(screen.getByRole('alert').textContent).toContain('Sem ligação ao servidor KeruGás')
    await userEvent.click(screen.getByRole('button', { name: /Tentar outra vez/ }))
    expect(retry).toHaveBeenCalled()
  })

  it('erro do servidor mostra o código', () => {
    const error = new AxiosError('fail', 'ERR_BAD_RESPONSE', undefined, undefined, { status: 500 } as never)
    render(<ApiErrorNotice error={error} onRetry={() => {}} />)
    expect(screen.getByRole('alert').textContent).toContain('erro 500')
  })
})
