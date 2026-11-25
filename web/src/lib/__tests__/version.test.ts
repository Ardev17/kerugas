import { describe, expect, it } from 'vitest'
import { compareVersions } from '../version'
import { safeStoreUrl, DEFAULT_STORE_URL } from '@/stores/appUpdateStore'

describe('versões do app', () => {
  it('compara número a número', () => {
    expect(compareVersions('1.10.0', '1.9.0')).toBe(1)
    expect(compareVersions('1.2', '1.2.0')).toBe(0)
    expect(compareVersions('1.1.9', '1.2.0')).toBe(-1)
    expect(compareVersions('abc', '1.0.0')).toBeNull()
  })

  it('só aceita links da Play Store', () => {
    expect(safeStoreUrl('https://play.google.com/store/apps/details?id=ao.kerugas.app')).toBe('https://play.google.com/store/apps/details?id=ao.kerugas.app')
    expect(safeStoreUrl('javascript:alert(1)')).toBe(DEFAULT_STORE_URL)
    expect(safeStoreUrl('https://evil.example/store')).toBe(DEFAULT_STORE_URL)
    expect(safeStoreUrl(undefined)).toBe(DEFAULT_STORE_URL)
  })
})
