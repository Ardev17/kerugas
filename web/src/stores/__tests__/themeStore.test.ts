import { beforeEach, describe, expect, it } from 'vitest'
import { DEFAULT_THEME, useThemeStore } from '@/stores/themeStore'

describe('tema do site e do app', () => {
  beforeEach(() => localStorage.clear())

  it('modo noturno é o principal para quem nunca escolheu', () => {
    expect(DEFAULT_THEME).toBe('dark')
    useThemeStore.getState().hydrate()
    expect(useThemeStore.getState().theme).toBe('dark')
  })

  it('quem escolheu o modo claro mantém a escolha', () => {
    localStorage.setItem('kerugas_theme', 'light')
    useThemeStore.getState().hydrate()
    expect(useThemeStore.getState().theme).toBe('light')

    useThemeStore.getState().toggle()
    expect(useThemeStore.getState().theme).toBe('dark')
    expect(localStorage.getItem('kerugas_theme')).toBe('dark')
  })
})
