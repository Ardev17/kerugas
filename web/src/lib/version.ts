/** Compara versões "1.2.3" número a número (1.10.0 > 1.9.0). Inválidas → null. */
export function compareVersions(a: string, b: string): number | null {
  const re = /^\d{1,4}(\.\d{1,4}){0,3}$/
  if (!re.test(a) || !re.test(b)) return null
  const pa = a.split('.').map(Number)
  const pb = b.split('.').map(Number)
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const diff = (pa[i] ?? 0) - (pb[i] ?? 0)
    if (diff !== 0) return diff > 0 ? 1 : -1
  }
  return 0
}
