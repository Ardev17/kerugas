import { useSearchParams } from 'react-router-dom'
import { MessagesSquare, TrendingUp } from 'lucide-react'
import { InvestorBilling } from '@/components/investor/InvestorBilling'
import { RoomChat } from '@/components/roomchat/RoomChat'
import { useAuth } from '@/hooks/useAuth'
import { investorService } from '@/services/investorService'

/**
 * Painel do investidor (Outubro 2026): a faturação dos SEUS municípios e a
 * conversa privada com o Super Admin.
 */
export default function InvestorDashboard() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = params.get('conversa') ? 'chat' : 'billing'
  const focusId = Number(params.get('mensagem')) || undefined

  if (!user) return null

  const tabCls = (active: boolean) => `flex flex-1 items-center justify-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold ${active ? 'bg-white text-dark shadow-sm' : 'text-gray'}`

  return (
    <div className="mx-auto max-w-3xl space-y-4 px-4 py-6">
      <header>
        <h1 className="text-2xl font-extrabold text-dark">Olá, {user.name.split(' ')[0]}</h1>
        <p className="text-sm text-gray">Painel do investidor,a faturação dos municípios em que investe.</p>
      </header>

      <div role="tablist" aria-label="Secções" className="flex gap-1 rounded-full bg-light p-1">
        <button type="button" role="tab" aria-selected={tab === 'billing'} onClick={() => setParams({})} className={tabCls(tab === 'billing')}>
          <TrendingUp size={15} aria-hidden="true" /> Faturação
        </button>
        <button type="button" role="tab" aria-selected={tab === 'chat'} onClick={() => setParams({ conversa: '1' })} className={tabCls(tab === 'chat')}>
          <MessagesSquare size={15} aria-hidden="true" /> Conversar com o Super Admin
        </button>
      </div>

      {tab === 'billing' ? (
        <InvestorBilling queryKey={['investor-overview']} load={investorService.overview} onExport={investorService.exportMine} />
      ) : (
        <RoomChat
          type="investor"
          roomId={user.id}
          focusId={focusId}
          emptyTitle="Ainda não há mensagens"
          emptyText="Esta conversa é só entre si e o Super Admin. Nenhum outro investidor a vê."
          placeholder="Escreva ao Super Admin…"
        />
      )}
    </div>
  )
}
