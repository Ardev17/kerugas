import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { Avatar } from '../Avatar'

describe('Avatar', () => {
  it('mostra a foto e volta às iniciais se não carregar', () => {
    const { container } = render(<Avatar name="Ana Silva" url="https://api.kerugas.ao/api/v1/avatars/x.webp" />)
    const img = container.querySelector('img') as HTMLImageElement
    expect(img).toHaveAttribute('src', expect.stringContaining('/api/v1/avatars/x.webp'))
    expect(img).toHaveAttribute('referrerpolicy', 'no-referrer')
    fireEvent.error(img)
    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByText('AS')).toBeInTheDocument()
  })

  it('sem foto, ou com URL perigoso, mostra as iniciais', () => {
    const { container, rerender } = render(<Avatar name="Ana Silva" />)
    expect(screen.getByText('AS')).toBeInTheDocument()
    rerender(<Avatar name="Ana Silva" url="javascript:alert(1)" />)
    expect(container.querySelector('img')).toBeNull()
  })
})

describe('Avatar em ecrã inteiro', () => {
  it('tocar na foto abre-a em ecrã inteiro; Escape ou ✕ fecham; o clique não ativa o que está à volta', async () => {
    const { default: userEvent } = await import('@testing-library/user-event')
    const outer = vi.fn()
    render(
      <button type="button" onClick={outer}>
        <Avatar name="Ana Silva" url="https://api.kerugas.ao/api/v1/avatars/x.webp" />
      </button>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Ver a foto de Ana Silva em ecrã inteiro' }))
    expect(outer).not.toHaveBeenCalled()
    const dialog = screen.getByRole('dialog', { name: 'Foto de Ana Silva' })
    expect(within(dialog).getByRole('img', { name: 'Foto de Ana Silva' })).toHaveAttribute('src', expect.stringContaining('/api/v1/avatars/x.webp'))
    expect(document.body.style.overflow).toBe('hidden')

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /ecrã inteiro/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Fechar foto' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(document.body.style.overflow).toBe('')
  })

  it('sem foto (iniciais) ou com zoomable=false não abre nada', () => {
    render(<><Avatar name="Ana Silva" /><Avatar name="Rui" url="https://x.ao/a.webp" zoomable={false} /></>)
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
