import { describe, expect, it } from 'vitest'
import { detectMotionLevel } from '../motion'

function win(opts: { reduce?: boolean; cores?: number; memory?: number; saveData?: boolean; net?: string }) {
  return {
    matchMedia: () => ({ matches: !!opts.reduce }) as MediaQueryList,
    navigator: { hardwareConcurrency: opts.cores, deviceMemory: opts.memory, connection: { saveData: opts.saveData, effectiveType: opts.net } } as unknown as Navigator,
  }
}

describe('detectMotionLevel', () => {
  it('"reduzir movimento" desliga tudo', () => {
    expect(detectMotionLevel(win({ reduce: true, cores: 8, memory: 8 }))).toBe('off')
  })
  it('telemóvel modesto, poupança de dados ou rede 2G = lite', () => {
    expect(detectMotionLevel(win({ cores: 4, memory: 8 }))).toBe('lite')
    expect(detectMotionLevel(win({ cores: 8, memory: 2 }))).toBe('lite')
    expect(detectMotionLevel(win({ cores: 8, memory: 8, saveData: true }))).toBe('lite')
    expect(detectMotionLevel(win({ cores: 8, memory: 8, net: '2g' }))).toBe('lite')
  })
  it('aparelho bom = full', () => {
    expect(detectMotionLevel(win({ cores: 8, memory: 8, net: '4g' }))).toBe('full')
  })
})
