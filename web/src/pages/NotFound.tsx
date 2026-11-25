import { Link } from 'react-router-dom'
import { LostCylinderArt } from '@/components/illustrations/Admin'

export default function NotFound() {
  return (
    <div className="mx-auto flex max-w-md flex-col items-center px-4 py-24 text-center">
      <LostCylinderArt className="h-44 w-auto" />
      <h1 className="mt-4 text-3xl font-extrabold text-dark">Página não encontrada</h1>
      <p className="mt-2 text-gray">O endereço que procura não existe ou foi movido.</p>
      <Link to="/" className="fx-btn-fire mt-6 rounded-xl px-6 py-3 text-sm font-semibold text-white">
        Voltar ao início
      </Link>
    </div>
  )
}
