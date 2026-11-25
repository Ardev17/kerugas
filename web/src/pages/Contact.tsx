import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Mail, MessageSquare, Loader2, AlertTriangle } from 'lucide-react'
import { CentralContactLinks } from '@/components/CentralContactLinks'
import { SocialLinks } from '@/components/SocialLinks'
import { contactService } from '@/services/contactService'
import { getApiErrorMessage } from '@/lib/api'
import { HoneypotField } from '@/components/HoneypotField'
import { useHoneypot } from '@/hooks/useHoneypot'

export default function Contact() {
  const [name, setName] = useState('')
  const [contact, setContact] = useState('')
  const [message, setMessage] = useState('')
  // Honeypot anti-spam,ver HoneypotField
  const honeypot = useHoneypot()

  const sendMutation = useMutation({
    mutationFn: () => contactService.send({ name, contact, message, website: honeypot.website }),
  })

  return (
    <div className="mx-auto max-w-lg px-4 py-16">
      <h1 className="text-3xl font-bold text-dark">Contacto</h1>
      <p className="mt-2 text-gray">
        Alguma dúvida, sugestão, ou quer cadastrar o seu posto no KeruGás? Fale connosco.
      </p>

      <div className="mt-6 rounded-xl border border-primary/20 bg-primary/5 p-4">
        <p className="mb-3 text-sm font-semibold text-dark">Ligue diretamente para a central</p>
        <CentralContactLinks />
      </div>

      {sendMutation.isSuccess ? (
        <div className="mt-8 rounded-xl border border-success/30 bg-success/5 p-6 text-center">
          <p className="font-medium text-dark">Mensagem enviada.</p>
          <p className="mt-1 text-sm text-gray">A nossa equipa responde o mais breve possível.</p>
        </div>
      ) : (
        <form
          onSubmit={(e) => { e.preventDefault(); sendMutation.mutate() }}
          className="mt-8 space-y-4"
        >
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Nome</span>
            <input
              required
              maxLength={150}
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-sm"
            />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Email ou telefone</span>
            <input
              required
              maxLength={150}
              value={contact}
              onChange={(e) => setContact(e.target.value)}
              className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-sm"
            />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Mensagem</span>
            <textarea
              required
              rows={4}
              maxLength={3000}
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              className="w-full rounded-lg border border-gray/20 px-3 py-2.5 text-sm"
            />
          </label>

          <HoneypotField {...honeypot.fieldProps} />

          {sendMutation.isError && (
            <p className="flex items-center gap-1.5 text-sm text-danger">
              <AlertTriangle size={14} />
              {getApiErrorMessage(sendMutation.error, 'Não foi possível enviar a mensagem. Tente novamente.')}
            </p>
          )}

          <button
            type="submit"
            disabled={sendMutation.isPending}
            className="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {sendMutation.isPending && <Loader2 size={16} className="animate-spin" />}
            {sendMutation.isPending ? 'A enviar...' : 'Enviar mensagem'}
          </button>
        </form>
      )}

      <div className="mt-10 grid gap-4 sm:grid-cols-2">
        <div className="flex items-center gap-3 rounded-lg bg-light p-4">
          <Mail size={18} className="text-primary" />
          <span className="text-sm text-dark">atendimento@kerugas.com</span>
        </div>
        <div className="flex items-center gap-3 rounded-lg bg-light p-4">
          <MessageSquare size={18} className="text-primary" />
          <span className="text-sm text-dark">Envie "GAS" por SMS para consultar postos</span>
        </div>
      </div>

      <div className="mt-10">
        <p className="mb-3 text-sm font-semibold text-dark">Siga o KeruGás nas redes sociais</p>
        <SocialLinks variant="buttons" />
      </div>
    </div>
  )
}
