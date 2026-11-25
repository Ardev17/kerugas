import { Capacitor } from '@capacitor/core'

/**
 * `true` quando o código está a correr dentro do app nativo (Capacitor: Android ou iOS),
 * `false` no site normal. Avaliado uma vez: a plataforma nunca muda
 * durante a vida da página.
 *
 * Regra do projeto: o site web não muda de comportamento por causa do
 * app. Tudo o que é específico do app fica atrás desta verificação (ou
 * da classe `native-app` no <html>, para CSS,ver bootstrap.ts).
 */
export const isNativeApp: boolean = Capacitor.isNativePlatform()

/** Sistema do telemóvel quando é o app nativo; `null` no site. */
export type NativePlatform = 'android' | 'ios'
export const nativePlatform: NativePlatform | null = isNativeApp ? (Capacitor.getPlatform() === 'ios' ? 'ios' : 'android') : null

/** Só verdadeiro quando o plugin nativo está mesmo disponível neste build. */
export function hasNativePlugin(name: string): boolean {
  return isNativeApp && Capacitor.isPluginAvailable(name)
}
