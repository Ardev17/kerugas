import { describe, expect, it } from 'vitest'
import { initialsOf, safeAvatarUrl, validateAvatarFile } from '../avatar'

const file = (type: string, size = 1000) => new File([new Uint8Array(size)], 'f', { type })

describe('foto de perfil,utilitários', () => {
  it('só aceita JPG, PNG e WebP, não vazios e até 20 MB', () => {
    expect(validateAvatarFile(file('image/jpeg'))).toBeNull()
    expect(validateAvatarFile(file('image/png'))).toBeNull()
    expect(validateAvatarFile(file('image/webp'))).toBeNull()
    expect(validateAvatarFile(file('image/svg+xml'))).toMatch(/Formato/)
    expect(validateAvatarFile(file('image/gif'))).toMatch(/Formato/)
    expect(validateAvatarFile(file('text/html'))).toMatch(/Formato/)
    expect(validateAvatarFile(file('image/png', 0))).toMatch(/vazio/)
    expect(validateAvatarFile(file('image/png', 20 * 1024 * 1024 + 1))).toMatch(/grande/)
  })

  it('recusa URLs que não sejam http(s)', () => {
    expect(safeAvatarUrl('https://api.kerugas.ao/api/v1/avatars/a.webp')).toBe('https://api.kerugas.ao/api/v1/avatars/a.webp')
    expect(safeAvatarUrl('javascript:alert(1)')).toBeNull()
    expect(safeAvatarUrl('data:image/svg+xml,<svg/>')).toBeNull()
    expect(safeAvatarUrl(null)).toBeNull()
  })

  it('iniciais', () => {
    expect(initialsOf('ana maria silva')).toBe('AM')
    expect(initialsOf('  ')).toBe('?')
    expect(initialsOf(undefined)).toBe('?')
  })
})
