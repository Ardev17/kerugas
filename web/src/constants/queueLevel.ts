/**
 * Rótulos em português do nível de fila,usado em todo o site
 * (detalhes do posto, cartões de listagem, painel do operador,
 * formulário do admin, analytics). Antes estava copiado à mão em
 * 4 ficheiros diferentes; consolidado aqui para nunca mais divergir.
 */
export const QUEUE_LEVEL_LABEL: Record<string, string> = {
  LOW: 'Baixa',
  MEDIUM: 'Média',
  HIGH: 'Alta',
}

/** Variante com a palavra "Fila" incluída, para sítios que a usam sozinha (sem um rótulo "Fila" ao lado). */
export const QUEUE_LEVEL_LABEL_FULL: Record<string, string> = {
  LOW: 'Fila baixa',
  MEDIUM: 'Fila média',
  HIGH: 'Fila alta',
}
