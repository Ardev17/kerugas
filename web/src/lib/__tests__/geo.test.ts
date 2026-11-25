import { describe, expect, it } from 'vitest'
import { GEO_MESSAGES, directionsUrl, isValidCoordinate } from '../geo'

describe('isValidCoordinate', () => {
  it('aceita coordenadas reais de Angola', () => {
    expect(isValidCoordinate(-14.9177, 13.4925)).toBe(true) // Lubango
    expect(isValidCoordinate(-8.839, 13.2894)).toBe(true) // Luanda
    expect(isValidCoordinate(-17.0667, 15.7333)).toBe(true) // Ondjiva
  })

  it('aceita os limites do mundo e o ponto 0,0', () => {
    expect(isValidCoordinate(90, 180)).toBe(true)
    expect(isValidCoordinate(-90, -180)).toBe(true)
    expect(isValidCoordinate(0, 0)).toBe(true)
  })

  it('rejeita fora dos limites', () => {
    expect(isValidCoordinate(90.0001, 0)).toBe(false)
    expect(isValidCoordinate(-90.0001, 0)).toBe(false)
    expect(isValidCoordinate(0, 180.0001)).toBe(false)
    expect(isValidCoordinate(0, -180.0001)).toBe(false)
  })

  it('rejeita NaN, infinitos, nulos, undefined e texto (mesmo numérico)', () => {
    expect(isValidCoordinate(NaN, 0)).toBe(false)
    expect(isValidCoordinate(0, NaN)).toBe(false)
    expect(isValidCoordinate(Infinity, 0)).toBe(false)
    expect(isValidCoordinate(0, -Infinity)).toBe(false)
    expect(isValidCoordinate(null, null)).toBe(false)
    expect(isValidCoordinate(undefined, 1)).toBe(false)
    expect(isValidCoordinate('-14.9', '13.4')).toBe(false)
    expect(isValidCoordinate({}, [])).toBe(false)
  })
})

describe('directionsUrl', () => {
  it('monta o link de rota do Google Maps com o destino em latitude,longitude', () => {
    expect(directionsUrl(-14.9177, 13.4925)).toBe('https://www.google.com/maps/dir/?api=1&destination=-14.9177,13.4925')
  })

  it('o link é um URL válido, https, sem origem forçada (o Maps usa a posição atual)', () => {
    const url = new URL(directionsUrl(-14.9177, 13.4925)!)
    expect(url.protocol).toBe('https:')
    expect(url.hostname).toBe('www.google.com')
    expect(url.pathname).toBe('/maps/dir/')
    expect(url.searchParams.get('api')).toBe('1')
    expect(url.searchParams.get('destination')).toBe('-14.9177,13.4925')
    expect(url.searchParams.has('origin')).toBe(false)
  })

  it('mantém a precisão das coordenadas da base de dados (7 casas)', () => {
    expect(directionsUrl(-14.9177123, 13.4925456)).toContain('destination=-14.9177123,13.4925456')
  })

  it('devolve null,nunca "null,null" nem "NaN",quando as coordenadas não servem', () => {
    for (const [lat, lng] of [[null, null], [undefined, undefined], [NaN, 1], [1, NaN], ['1', '2'], [91, 0], [0, 181]]) {
      expect(directionsUrl(lat, lng)).toBeNull()
    }
  })

  it('não deixa passar texto injetado no URL', () => {
    expect(directionsUrl('1&origin=evil', '2')).toBeNull()
  })
})

describe('GEO_MESSAGES', () => {
  it('há uma mensagem em português para cada motivo, sempre com o passo seguinte', () => {
    for (const message of Object.values(GEO_MESSAGES)) {
      expect(message.length).toBeGreaterThan(20)
      expect(message).not.toMatch(/denied|permission|timeout|unavailable/i)
    }
  })
})
