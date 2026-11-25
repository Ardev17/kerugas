import { isNativeApp } from '@/native/platform'

/**
 * Vibração curta nos momentos certos,é um dos detalhes que mais
 * separa "um site dentro de um app" de um app a sério. Usada com
 * moderação: toque na barra de navegação, e confirmação de ações que
 * importam (pedido feito, estado do posto atualizado, entrega concluída).
 *
 * No site web não faz nada. Falhas são ignoradas de propósito: um
 * telemóvel sem motor de vibração nunca deve ver um erro por causa disto.
 */
export async function tapFeedback(): Promise<void> {
  if (!isNativeApp) return
  try {
    const { Haptics, ImpactStyle } = await import('@capacitor/haptics')
    await Haptics.impact({ style: ImpactStyle.Light })
  } catch {
    // sem vibração disponível,ignorar
  }
}

export async function successFeedback(): Promise<void> {
  if (!isNativeApp) return
  try {
    const { Haptics, NotificationType } = await import('@capacitor/haptics')
    await Haptics.notification({ type: NotificationType.Success })
  } catch {
    // sem vibração disponível,ignorar
  }
}
