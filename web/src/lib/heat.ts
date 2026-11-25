/**
 * Rampa sequencial de uma só cor (azul, claro→escuro) da paleta validada —
 * magnitude, nunca arco-íris. Passo 0 = "sem pedidos".
 */
export const RAMP = ['#cde2fb', '#86b6ef', '#3987e5', '#1c5cab', '#0d366b']

/** Índice 0–4 na rampa (escala por quintis do máximo). */
export function rampIndex(value: number, max: number): number {
  if (max <= 0 || value <= 0) return -1
  return Math.min(RAMP.length - 1, Math.floor((value / max) * RAMP.length - 1e-9))
}

