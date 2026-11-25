import { describe, it, expect } from 'vitest'
import { canEnterAdminPanel, hasSuperPowers, isGlobalSuperAdmin, isManagement, isProvinceScoped, isProvinceSuperAdmin, ROLE_LABEL } from '../roles'

const as = (role: string) => ({ role }) as { role: never }

describe('papéis', () => {
  it('Super Admin Provincial: poderes de super admin, limitado à província', () => {
    const psa = as('PROVINCE_SUPER_ADMIN')
    expect(hasSuperPowers(psa)).toBe(true)
    expect(isProvinceSuperAdmin(psa)).toBe(true)
    expect(isGlobalSuperAdmin(psa)).toBe(false)
    expect(isProvinceScoped(psa)).toBe(true)
    expect(isManagement(psa)).toBe(true)
    expect(canEnterAdminPanel(psa)).toBe(true)
    expect(ROLE_LABEL.PROVINCE_SUPER_ADMIN).toBe('Super Admin Provincial')
  })

  it('Super Admin geral não é limitado; Admin não tem super poderes', () => {
    expect(isProvinceScoped(as('SUPER_ADMIN'))).toBe(false)
    expect(hasSuperPowers(as('SUPER_ADMIN'))).toBe(true)
    expect(hasSuperPowers(as('ADMIN'))).toBe(false)
    expect(isProvinceScoped(as('ADMIN'))).toBe(true)
    expect(canEnterAdminPanel(as('MODERATOR'))).toBe(true)
    expect(canEnterAdminPanel(as('DRIVER'))).toBe(false)
    expect(hasSuperPowers(null)).toBe(false)
  })
})

describe('canRemoveAvatarOf (foto de perfil)', () => {
  it('segue a hierarquia e o âmbito de província', async () => {
    const { canRemoveAvatarOf } = await import('../roles')
    const admin = { role: 'ADMIN' as const, province_id: 1 }
    expect(canRemoveAvatarOf(admin, { role: 'USER', province_id: 1 })).toBe(true)
    expect(canRemoveAvatarOf(admin, { role: 'USER', province_id: 2 })).toBe(false)
    expect(canRemoveAvatarOf(admin, { role: 'ADMIN', province_id: 1 })).toBe(false)
    expect(canRemoveAvatarOf({ role: 'MODERATOR', province_id: null }, { role: 'DRIVER', province_id: 7 })).toBe(true)
    expect(canRemoveAvatarOf({ role: 'PROVINCE_SUPER_ADMIN', province_id: 1 }, { role: 'ADMIN', province_id: 1 })).toBe(true)
    expect(canRemoveAvatarOf({ role: 'PROVINCE_SUPER_ADMIN', province_id: 1 }, { role: 'SUPER_ADMIN', province_id: 1 })).toBe(false)
    expect(canRemoveAvatarOf({ role: 'SUPER_ADMIN', province_id: null }, { role: 'PROVINCE_SUPER_ADMIN', province_id: 3 })).toBe(true)
    expect(canRemoveAvatarOf({ role: 'USER', province_id: 1 }, { role: 'USER', province_id: 1 })).toBe(false)
  })
})
