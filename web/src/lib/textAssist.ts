/**
 * Corretor e sugestões do teclado do telemóvel (Outubro 2026).
 *
 * Nos campos onde se escreve texto livre (mensagens, comentários, notas,
 * moradas, nomes) o app pede explicitamente ao teclado que corrija e sugira
 * palavras, e que ponha maiúscula no início das frases. Nos campos que não
 * são texto (telefone, PIN, códigos, e-mail, palavras-passe, números,
 * coordenadas, ligações) o teclado fica como está,corrigir ali estragaria
 * o que a pessoa escreve.
 *
 * Quem quiser outra coisa num campo escreve o atributo à mão
 * (`spellCheck={false}`, `autoCorrect="off"`…) ou `data-no-assist`: o que já
 * está definido nunca é mexido.
 */

const SKIP_INPUT_MODES = new Set(['numeric', 'decimal', 'tel', 'email', 'url'])
const SKIP_AUTOCOMPLETE = /^(off|one-time-code|username|email|tel\b|tel-|current-password|new-password|cc-|postal-code|url)/i
const SKIP_TYPES = new Set(['password', 'email', 'tel', 'number', 'url', 'search-code', 'hidden', 'checkbox', 'radio', 'file', 'range', 'color', 'date', 'time', 'datetime-local', 'month', 'week', 'submit', 'button', 'reset', 'image'])
/** Campos técnicos pelo nome ou id (PIN, códigos, telefone, e-mail, coordenadas…). */
const SKIP_NAME = /(^|[-_ ])(pin|code|codigo|otp|token|phone|telefone|tel|telemovel|email|mail|senha|password|url|link|lat|lng|latitude|longitude|ref|iban|nif|plate|matricula)([-_ ]|$)/i
const NAME_FIELD = /(^|[-_ ])(name|nome)([-_ ]|$)/i

type Field = HTMLInputElement | HTMLTextAreaElement

/** Este campo deve ter corretor? (puro, para testar) */
export function wantsTextAssist(el: Element): boolean {
  if (!(el instanceof HTMLTextAreaElement || el instanceof HTMLInputElement)) return false
  if (el.hasAttribute('data-no-assist') || el.readOnly || el.disabled) return false
  if (el instanceof HTMLTextAreaElement) return true

  const type = (el.getAttribute('type') ?? 'text').toLowerCase()
  if (SKIP_TYPES.has(type)) return false
  if (type !== 'text' && type !== 'search') return false
  if (SKIP_INPUT_MODES.has((el.getAttribute('inputmode') ?? '').toLowerCase())) return false
  if (SKIP_AUTOCOMPLETE.test(el.getAttribute('autocomplete') ?? '')) return false
  if (SKIP_NAME.test(`${el.id} ${el.getAttribute('name') ?? ''}`)) return false
  return true
}

/** Liga o corretor num campo (só escreve o que ainda não está definido). */
export function applyTextAssist(el: Field): void {
  if (!wantsTextAssist(el)) return
  if (!el.hasAttribute('spellcheck')) el.setAttribute('spellcheck', 'true')
  if (!el.hasAttribute('autocorrect')) el.setAttribute('autocorrect', 'on')
  if (!el.hasAttribute('autocapitalize')) {
    const nameLike = el instanceof HTMLInputElement && NAME_FIELD.test(`${el.id} ${el.getAttribute('name') ?? ''}`)
    el.setAttribute('autocapitalize', nameLike ? 'words' : 'sentences')
  }
  if (!el.hasAttribute('lang') && !el.closest('[lang]')) el.setAttribute('lang', 'pt')
}

function scan(root: ParentNode): void {
  root.querySelectorAll<Field>('textarea, input').forEach(applyTextAssist)
}

/** Aplica a toda a página e aos campos que forem aparecendo. Devolve a função para desligar. */
export function installTextAssist(): () => void {
  if (typeof document === 'undefined' || typeof MutationObserver === 'undefined') return () => {}
  scan(document)
  const observer = new MutationObserver((mutations) => {
    for (const m of mutations) {
      m.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) return
        if (node instanceof HTMLTextAreaElement || node instanceof HTMLInputElement) applyTextAssist(node)
        else scan(node)
      })
    }
  })
  observer.observe(document.body, { childList: true, subtree: true })
  return () => observer.disconnect()
}
