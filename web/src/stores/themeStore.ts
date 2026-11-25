import { create } from 'zustand'

type Theme = 'light' | 'dark'

interface ThemeState {
  theme: Theme
  toggle: () => void
  hydrate: () => void
}

const STORAGE_KEY = 'kerugas_theme'
const LEGACY_ADMIN_KEY = 'kerugas_admin_theme'

/**
 * Tema de TODO o site (secção 80/89: "Criar modo escuro",pedido
 * expandido pelo cliente para valer para qualquer visitante, autenticado
 * ou não, não só o Admin). Um único store partilhado entre PublicLayout
 * e AdminLayout, aplicado ao wrapper de topo em App.tsx.
 */
/**
 * Modo noturno é o PRINCIPAL (Outubro 2026, decisão do cliente): quem
 * nunca escolheu um tema vê o site e o app escuros. Quem já escolheu
 * (botão sol/lua) mantém a sua escolha,fica guardada neste aparelho.
 * Lido logo na criação do store, para o primeiro ecrã já sair no tema
 * certo (o index.html também arranca com `class="dark"`, sem piscar).
 */
export const DEFAULT_THEME: Theme = 'dark'

function readStoredTheme(): Theme | null {
  try {
    // Migra a preferência antiga (quando o modo escuro só existia no
    // admin) para a chave nova, sitewide, sem obrigar a escolher outra vez.
    const stored = localStorage.getItem(STORAGE_KEY) ?? localStorage.getItem(LEGACY_ADMIN_KEY)
    return stored === 'dark' || stored === 'light' ? stored : null
  } catch {
    return null // armazenamento bloqueado
  }
}

export const useThemeStore = create<ThemeState>((set, get) => ({
  theme: readStoredTheme() ?? DEFAULT_THEME,

  toggle: () => {
    const next = get().theme === 'dark' ? 'light' : 'dark'
    try {
      localStorage.setItem(STORAGE_KEY, next)
    } catch {
      /* sem armazenamento: vale só nesta visita */
    }
    set({ theme: next })
  },

  hydrate: () => {
    set({ theme: readStoredTheme() ?? DEFAULT_THEME })
  },
}))
