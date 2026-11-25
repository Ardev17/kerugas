import { describe, expect, it, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { DriverLocationCard } from '@/components/driver/DriverLocationSharing'
import { useDriverLocationStore } from '@/stores/driverLocationStore'

describe('DriverLocationCard (Novembro 2026)', () => {
  beforeEach(() => {
    localStorage.clear()
    useDriverLocationStore.setState({ consent: false, state: 'off' })
  })

  it('mostra o aviso destacado antes de partilhar e guarda o consentimento', () => {
    render(<DriverLocationCard />)
    expect(screen.getByText(/Super Admin e o Admin Provincial/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Aceito partilhar/ }))
    expect(useDriverLocationStore.getState().consent).toBe(true)
    expect(localStorage.getItem('kerugas_driver_location_consent')).toBe('1')
  })

  it('mostra o estado e permite parar', () => {
    useDriverLocationStore.setState({ consent: true, state: 'active' })
    render(<DriverLocationCard />)
    expect(screen.getByText('A partilhar a sua localização')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Parar de partilhar/ }))
    expect(useDriverLocationStore.getState().consent).toBe(false)
  })
})
