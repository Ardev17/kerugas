import { useState } from 'react'

/**
 * Estado do honeypot de um formulário. `website` só é enviado quando
 * preenchido,uma pessoa real nunca o manda.
 */
export function useHoneypot() {
  const [value, setValue] = useState('')
  return {
    website: value || undefined,
    fieldProps: { value, onChange: setValue },
  }
}
