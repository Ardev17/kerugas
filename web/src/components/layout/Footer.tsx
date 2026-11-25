import { PoweredBy } from '@/components/PoweredBy'
import { Link } from 'react-router-dom'
import { CentralContactLinks } from '@/components/CentralContactLinks'
import { StoreBadges } from '@/components/DownloadAppButton'
import { SocialLinks } from '@/components/SocialLinks'

/**
 * Rodapé institucional. O texto final é obrigatório e exato,
 * conforme secção 92 e 161 do Master Build Prompt. Não alterar.
 * A tira de contactos centrais abaixo é um acrescento,não mexe em
 * nenhum dos blocos de texto já existentes.
 */
export function Footer() {
  return (
    <footer className="border-t border-gray/10 bg-dark text-light">
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 md:grid-cols-4">
        <div>
          <div className="flex items-center gap-2">
            <img src="/branding/logo-128.webp" alt="KeruGás" className="h-10 w-10 object-contain" width={40} height={40} />
            <p className="text-lg font-bold">KeruGás</p>
          </div>
          <p className="mt-2 text-sm text-gray">
            Informação inteligente para encontrar gás quando precisa.
          </p>
          {/* Redes sociais (Setembro 2026),links fictícios por agora,
              ver comentário em constants/socials.ts. */}
          <SocialLinks className="mt-4" />
          <StoreBadges className="mt-4" />
        </div>

        <div>
          <p className="text-sm font-semibold text-white">Produto</p>
          <ul className="mt-3 space-y-2 text-sm text-gray">
            <li><Link to="/encontrar-gas" className="hover:text-white">Encontrar gás</Link></li>
            <li><Link to="/mapa" className="hover:text-white">Mapa</Link></li>
            <li><Link to="/como-funciona" className="hover:text-white">Como funciona</Link></li>
            <li><Link to="/sobre" className="hover:text-white">Sobre</Link></li>
            <li><Link to="/contacto" className="hover:text-white">Contacto</Link></li>
          </ul>
        </div>

        <div>
          <p className="text-sm font-semibold text-white">Conta</p>
          <ul className="mt-3 space-y-2 text-sm text-gray">
            <li><Link to="/login" className="hover:text-white">Entrar</Link></li>
            <li><Link to="/register" className="hover:text-white">Criar conta</Link></li>
            <li><Link to="/favorites" className="hover:text-white">Favoritos</Link></li>
            <li><Link to="/alerts" className="hover:text-white">Alertas</Link></li>
          </ul>
        </div>

        <div>
          <p className="text-sm font-semibold text-white">Legal</p>
          <ul className="mt-3 space-y-2 text-sm text-gray">
            <li><Link to="/termos" className="hover:text-white">Termos</Link></li>
            <li><Link to="/privacidade" className="hover:text-white">Privacidade</Link></li>
            <li><Link to="/eliminar-conta" className="hover:text-white">Eliminar conta</Link></li>
            <li><Link to="/cookies" className="hover:text-white">Cookies</Link></li>
          </ul>
        </div>
      </div>

      <div className="border-t border-white/10 px-4 py-5">
        <div className="mx-auto max-w-6xl">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray">Central KeruGás,ligue já</p>
          <CentralContactLinks variant="compact" />
        </div>
      </div>

      <div className="border-t border-white/10 px-4 py-6 text-center text-xs text-gray">
        <p>© 2026 KERUGAS. Todos os direitos reservados.</p>
        <p className="mt-1"><PoweredBy /></p>
      </div>
    </footer>
  )
}
