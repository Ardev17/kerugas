/**
 * Links https://www.kerugas.ao/... abertos a partir de fora (SMS de
 * avaliação do condutor, WhatsApp, email) chegam ao app como URL
 * completo. Só aceitamos o nosso próprio domínio e devolvemos apenas o
 * caminho interno.
 *
 * SEGURANÇA: um URL externo é controlado por quem o enviou, por isso o
 * caminho é validado antes de chegar ao React Router,em particular,
 * barras invertidas e "//" no início são rejeitados (vetor de open
 * redirect conhecido no React Router 6, GHSA-wrjc-x8rr-h8h6).
 */
const ALLOWED_HOSTS = new Set(['www.kerugas.ao', 'kerugas.ao'])

export function internalPathFromUrl(rawUrl: string): string | null {
  let url: URL
  try {
    url = new URL(rawUrl)
  } catch {
    return null
  }

  if (url.protocol !== 'https:' || !ALLOWED_HOSTS.has(url.hostname)) {
    return null
  }

  return safeInternalPath(url.pathname + url.search)
}

/** Aceita só caminhos internos simples: "/algo", nunca "//x", "/\x" ou esquemas. */
export function safeInternalPath(path: string): string | null {
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\')) {
    return null
  }
  // Caracteres de controlo nunca aparecem num caminho legítimo do site.
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u001f]/.test(path)) {
    return null
  }
  return path
}
