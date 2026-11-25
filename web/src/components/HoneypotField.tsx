/**
 * Honeypot anti-bot (Setembro 2026 no contacto; Outubro 2026 também no
 * registo, na recuperação de senha e no pedido de gás).
 *
 * Um campo de texto que nenhuma pessoa vê: fora do ecrã, fora da ordem do
 * Tab, escondido dos leitores de ecrã e sem `name` (o preenchimento
 * automático do browser não lhe toca). Um bot que preenche todos os
 * inputs preenche-o também,e o servidor trava o pedido (ver
 * HasHoneypot no Laravel). Zero atrito para quem usa a plataforma.
 */
export function HoneypotField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return (
    <div
      aria-hidden="true"
      data-testid="honeypot"
      style={{ position: 'absolute', left: '-9999px', top: 'auto', width: '1px', height: '1px', overflow: 'hidden' }}
    >
      <label>
        Deixe este campo vazio
        <input type="text" tabIndex={-1} autoComplete="off" value={value} onChange={(e) => onChange(e.target.value)} />
      </label>
    </div>
  )
}
