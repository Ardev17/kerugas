import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { StationCard } from '../StationCard'
import type { Station } from '@/types'

const baseStation: Station = {
  id: 1,
  name: '[DEMO] Posto Central Lubango',
  slug: 'demo-posto-central-lubango',
  province: 'Huíla',
  municipality: 'Lubango',
  address: null,
  reference_point: null,
  latitude: -14.9177,
  longitude: 13.4925,
  phone: null,
  opening_hours: null,
  photo_url: null,
  status: 'ACTIVE',
  gas_available: true,
  queue_level: 'LOW',
  cylinder_types: null,
  confidence_score: 80,
  freshness: 'FRESH',
  freshness_label: 'Muito recente',
  status_updated_at: new Date().toISOString(),
}

function renderCard(station: Station) {
  return render(
    <MemoryRouter>
      <StationCard station={station} />
    </MemoryRouter>
  )
}

describe('StationCard', () => {
  it('mostra o nome e o município do posto', () => {
    renderCard(baseStation)
    expect(screen.getByText('[DEMO] Posto Central Lubango')).toBeInTheDocument()
    expect(screen.getByText('Lubango')).toBeInTheDocument()
  })

  it('mostra "Gás disponível" quando gas_available é true', () => {
    renderCard(baseStation)
    expect(screen.getByText('Gás disponível')).toBeInTheDocument()
  })

  it('mostra "Gás indisponível" quando gas_available é false', () => {
    renderCard({ ...baseStation, gas_available: false })
    expect(screen.getByText('Gás indisponível')).toBeInTheDocument()
  })

  it('mostra a distância quando fornecida', () => {
    renderCard({ ...baseStation, distance_km: 3.4 })
    expect(screen.getByText(/3\.4 km/)).toBeInTheDocument()
  })

  it('não mostra distância quando não fornecida', () => {
    renderCard(baseStation)
    expect(screen.queryByText(/km/)).not.toBeInTheDocument()
  })

  it('inclui um link "Traçar rota" apontando para o Google Maps com as coordenadas certas', () => {
    renderCard(baseStation)
    const link = screen.getByRole('link', { name: /Traçar rota/i })
    expect(link).toHaveAttribute('href', expect.stringContaining('-14.9177,13.4925'))
  })
})
