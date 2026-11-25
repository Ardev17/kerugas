import { useLayoutEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { applySeo } from './head'
import { defaultSeoFor } from './useSeo'

/**
 * Título, descrição, canonical e robots de cada página (Outubro 2026).
 *
 * Corre em `useLayoutEffect` de propósito: os efeitos de layout correm ANTES
 * dos `useEffect` das páginas, por isso uma página que defina o seu próprio
 * SEO com `useSeo()` (ex.: o detalhe do posto, com o nome do posto) ganha
 * sempre a estes valores por omissão.
 */
export function SeoManager() {
  const { pathname } = useLocation()

  useLayoutEffect(() => {
    applySeo(defaultSeoFor(pathname))
  }, [pathname])

  return null
}
