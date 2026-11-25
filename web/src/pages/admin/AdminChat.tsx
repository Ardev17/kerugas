import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { territoryService } from '@/services/territoryService'
import { useAuth } from '@/hooks/useAuth'
import { DriversRoomChat } from '@/components/roomchat/DriversRoomChat'
import { isGlobalSuperAdmin } from '@/lib/roles'

/**
 * Chat por município, lado Admin/Super Admin (pedido explícito do
 * cliente, Setembro 2026). Escolhe-se um município (e, para Super
 * Admin, primeiro a província) antes de abrir o painel,o
 * a conversa dos condutores mostra sempre um único município de cada vez, com o
 * backend a impor o âmbito real (Admin só da sua província; Super
 * Admin de qualquer uma).
 */
export default function AdminChat() {
  const { user } = useAuth()
  const isSuperAdmin = isGlobalSuperAdmin(user) // escolhe qualquer província

  const [provinceId, setProvinceId] = useState<number | undefined>(isSuperAdmin ? undefined : (user?.province_id ?? undefined))
  // Vindo de uma notificação (?municipio=…&mensagem=…): abre logo essa conversa.
  const [params] = useSearchParams()
  const focus = Number(params.get('municipio')) ? { municipality: Number(params.get('municipio')), message: Number(params.get('mensagem')) || undefined } : null
  const [municipalityId, setMunicipalityId] = useState<number | undefined>(focus?.municipality)

  const provincesQuery = useQuery({
    queryKey: ['provinces'],
    queryFn: territoryService.listProvinces,
    enabled: isSuperAdmin,
  })

  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(provinceId!),
    enabled: !!provinceId,
  })

  const selectedMunicipality = municipalitiesQuery.data?.find((m) => m.id === municipalityId)

  return (
    <div>
      <h1 className="text-xl font-bold text-dark">Chat por município</h1>
      <p className="mt-1 text-sm text-gray">
        Pergunte a condutores e outros admins sobre locais/referências para ajudar entregas.
      </p>

      <div className="mt-4 flex flex-wrap gap-2">
        {isSuperAdmin && (
          <select
            aria-label="Escolher província"
            value={provinceId ?? ''}
            onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : undefined); setMunicipalityId(undefined) }}
            className="rounded-lg border border-gray/20 px-3 py-2 text-sm"
          >
            <option value="">Selecione a província</option>
            {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        )}
        <select
          aria-label="Escolher município"
          value={municipalityId ?? ''}
          onChange={(e) => setMunicipalityId(e.target.value ? Number(e.target.value) : undefined)}
          disabled={!provinceId}
          className="rounded-lg border border-gray/20 px-3 py-2 text-sm disabled:opacity-50"
        >
          <option value="">Selecione o município</option>
          {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
      </div>

      <div className="mt-4">
        {municipalityId ? (
          <DriversRoomChat key={municipalityId} municipalityId={municipalityId} municipalityLabel={selectedMunicipality?.name} focusId={focus?.municipality === municipalityId ? focus.message : undefined} />
        ) : (
          <p className="rounded-xl border border-dashed border-gray/20 p-8 text-center text-sm text-gray">
            Escolha um município para ver e participar na conversa.
          </p>
        )}
      </div>
    </div>
  )
}
