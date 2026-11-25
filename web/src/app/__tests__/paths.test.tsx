import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { LEGACY_REDIRECTS, slugify, stationIdFromParam, stationPath } from '../paths'
import { LegacyRedirect, LegacyStationRedirect } from '../LegacyRedirect'

/** URLs em português (Outubro 2026): novos endereços e os antigos a redirecionar. */
function Where() {
  const l = useLocation()
  return <p data-testid="where">{`${l.pathname}${l.search}${l.hash}`}</p>
}

describe('endereços amigáveis', () => {
  it('slug sem acentos nem símbolos, igual ao Str::slug do Laravel', () => {
    expect(slugify('Posto Central,Lubango')).toBe('posto-central-lubango')
    expect(slugify('[DEMO] Posto da Huíla nº 2')).toBe('demo-posto-da-huila-no-2')
    expect(slugify('Gás & Cª')).toBe('gas-ca')
  })

  it('página do posto: número + nome; o número é o que conta', () => {
    expect(stationPath({ id: 12, name: 'Posto Central Lubango' })).toBe('/postos/12-posto-central-lubango')
    expect(stationPath({ id: 12, name: '' })).toBe('/postos/12')
    expect(stationIdFromParam('12-posto-central-lubango')).toBe(12)
    expect(stationIdFromParam('12')).toBe(12)
    expect(stationIdFromParam('posto')).toBeNaN()
  })

  it('cada endereço antigo tem rota de redirecionamento e o novo existe no router', () => {
    const router = readFileSync(resolve(__dirname, '../router.tsx'), 'utf8')
    expect(router).toContain('LEGACY_REDIRECTS.map')
    expect(router).toContain('path="/station/:id"')
    for (const [, to] of LEGACY_REDIRECTS) expect(router, to).toContain(`path="${to}"`)
  })

  it('o servidor também redireciona (301) todos os endereços antigos', () => {
    const root = resolve(__dirname, '../../../../..')
    const docker = readFileSync(resolve(root, 'infrastructure/docker/Dockerfile.frontend'), 'utf8')
    const vercel = readFileSync(resolve(__dirname, '../../../vercel.json'), 'utf8')
    const netlify = readFileSync(resolve(__dirname, '../../../public/_redirects'), 'utf8')
    for (const [from, to] of LEGACY_REDIRECTS) {
      expect(docker).toContain(`location = ${from} { return 301 ${to}$is_args$args; }`)
      expect(vercel).toContain(`"source": "${from}"`)
      expect(netlify).toContain(`${from}  ${to}  301`)
    }
    expect(docker).toContain('return 301 /postos/$1')
  })

  it('o endereço antigo leva ao novo, mantendo a pesquisa (?repetir=1)', () => {
    render(
      <MemoryRouter initialEntries={['/order?repetir=1']}>
        <Routes>
          <Route path="/order" element={<LegacyRedirect to="/pedir-gas" />} />
          <Route path="/pedir-gas" element={<Where />} />
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByTestId('where')).toHaveTextContent('/pedir-gas?repetir=1')
  })

  it('/station/12 leva a /postos/12', () => {
    render(
      <MemoryRouter initialEntries={['/station/12']}>
        <Routes>
          <Route path="/station/:id" element={<LegacyStationRedirect />} />
          <Route path="/postos/:id" element={<Where />} />
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByTestId('where')).toHaveTextContent('/postos/12')
  })
})
