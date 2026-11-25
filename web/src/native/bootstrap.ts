import { SystemBars, SystemBarsStyle } from '@capacitor/core'
import { isNativeApp, hasNativePlugin, nativePlatform } from '@/native/platform'
import { useThemeStore } from '@/stores/themeStore'

/**
 * Arranque do app (Android e iPhone),corre uma única vez, antes do React montar
 * (ver main.tsx). No site web não faz nada.
 *
 * - Marca o <html> com `native-app`, para o CSS aplicar margens seguras,
 *   esconder o rodapé de site, etc. (ver globals.css).
 * - Ícones da barra de estado acompanham o tema claro/escuro.
 * - `keyboard-open` no <html> enquanto o teclado está aberto, para a
 *   barra de navegação inferior sair do caminho de quem está a escrever.
 * - `offline` no <html> quando a ligação cai (ver <OfflineBanner>).
 */
export function bootstrapNativeApp(): void {
  if (!isNativeApp) return

  // `native-ios` / `native-android` para o CSS que só vale num dos sistemas.
  document.documentElement.classList.add('native-app', `native-${nativePlatform ?? 'android'}`)

  // Aparece no Logcat do Android Studio (filtro "Capacitor/Console") e na
  // consola do Xcode / Safari:
  // confirma logo para que servidor este build do app está a falar.
  console.info('[KERUGAS] API:', import.meta.env.VITE_API_URL ?? '(VITE_API_URL em falta)')

  // Rede de segurança: o splash é escondido pelo <NativeAppShell> quando
  // o primeiro ecrã está pintado. Se algo impedir o React de montar, o
  // splash nunca pode ficar para sempre no ecrã (o app pareceria
  // congelado),ao fim de 6s sai de qualquer forma.
  window.setTimeout(() => void hideSplashWhenReady(), 6000)

  syncSystemBarsWithTheme()
  void watchKeyboard()
  void watchNetwork()
}

/**
 * Esconde o splash só quando o React já pintou o primeiro ecrã,nunca
 * um flash branco entre o logo e a app. Chamado por <NativeAppShell>.
 */
export async function hideSplashWhenReady(): Promise<void> {
  if (!hasNativePlugin('SplashScreen')) return
  const { SplashScreen } = await import('@capacitor/splash-screen')
  // Dois frames: garante que o layout do primeiro ecrã já foi calculado
  // e desenhado antes de o splash desaparecer.
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      void SplashScreen.hide({ fadeOutDuration: 250 })
    })
  )
}

function syncSystemBarsWithTheme(): void {
  const apply = (theme: 'light' | 'dark') => {
    // LIGHT = ícones escuros (fundo claro); DARK = ícones claros.
    void SystemBars.setStyle({ style: theme === 'dark' ? SystemBarsStyle.Dark : SystemBarsStyle.Light }).catch(() => {})
  }
  apply(useThemeStore.getState().theme)
  useThemeStore.subscribe((state, previous) => {
    if (state.theme !== previous.theme) apply(state.theme)
  })
}

async function watchKeyboard(): Promise<void> {
  if (!hasNativePlugin('Keyboard')) return
  const { Keyboard } = await import('@capacitor/keyboard')
  const root = document.documentElement
  // Traz o campo focado para o centro da área visível, por cima do teclado.
  // Vale para qualquer modo de redimensionamento e para qualquer telemóvel.
  const revealFocused = () => {
    requestAnimationFrame(() => {
      const el = document.activeElement
      if (el instanceof HTMLElement && el.matches('input, textarea, [contenteditable="true"]')) {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' })
      }
    })
  }
  await Keyboard.addListener('keyboardWillShow', (info) => {
    // Altura do teclado disponível em CSS (--kb-height), para quem precisar.
    root.style.setProperty('--kb-height', `${info?.keyboardHeight ?? 0}px`)
    root.classList.add('keyboard-open')
    revealFocused()
  })
  await Keyboard.addListener('keyboardWillHide', () => {
    root.classList.remove('keyboard-open')
    root.style.setProperty('--kb-height', '0px')
  })
}

async function watchNetwork(): Promise<void> {
  if (!hasNativePlugin('Network')) return
  const { Network } = await import('@capacitor/network')
  const root = document.documentElement
  const apply = (connected: boolean) => {
    root.classList.toggle('offline', !connected)
    window.dispatchEvent(new CustomEvent('kerugas:network', { detail: { connected } }))
  }
  apply((await Network.getStatus()).connected)
  await Network.addListener('networkStatusChange', (status) => apply(status.connected))
}
