/**
 * Contactos centrais do KERUGAS,a linha de apoio da própria
 * plataforma (diferente do telefone de cada posto, que é do posto
 * em si), sempre visíveis e clicáveis em vários pontos do site.
 *
 * Números oficiais (Nov. 2026): +244 922 761 180 e +244 964 066 858.
 * `operator` é opcional,só se mostra a etiqueta da operadora quando
 * é conhecida; sem ela, o número aparece sozinho. Um único sítio a
 * editar; todo o site lê daqui.
 */
export interface CentralContactNumber {
  operator?: 'Unitel' | 'Movicel' | 'Africell'
  number: string
  /** Formatado para exibição (com espaços); `number` sem espaços é o que vai no href tel: */
  display: string
}

export const CENTRAL_CONTACT_NUMBERS: CentralContactNumber[] = [
  { number: '+244922761180', display: '+244 922 761 180' },
  { number: '+244964066858', display: '+244 964 066 858' },
]
