import { useEffect, useRef } from 'react'

/**
 * Pilha de "coisas que o botão voltar do Android deve fechar primeiro"
 * (menu lateral aberto, janela modal, etc.). O componente que abre algo
 * por cima regista um handler enquanto está aberto; <NativeAppShell>
 * chama sempre o do topo antes de recuar na navegação. Sem isto, o
 * voltar do Android saltava para a página anterior com a janela ainda
 * "aberta",o comportamento que denuncia um site embrulhado num app.
 */
const handlers: Array<() => void> = []

export function runTopBackHandler(): boolean {
  const top = handlers[handlers.length - 1]
  if (!top) return false
  top()
  return true
}

/** Enquanto `active` for true, o voltar do Android chama `onBack` em vez de navegar. */
export function useBackHandler(active: boolean, onBack: () => void): void {
  const latest = useRef(onBack)
  latest.current = onBack

  useEffect(() => {
    if (!active) return
    const handler = () => latest.current()
    handlers.push(handler)
    return () => {
      const index = handlers.lastIndexOf(handler)
      if (index !== -1) handlers.splice(index, 1)
    }
  }, [active])
}
