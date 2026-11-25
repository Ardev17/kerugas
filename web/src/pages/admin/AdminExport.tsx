import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { adminService } from '@/services/adminService'
import { territoryService } from '@/services/territoryService'
import { ExportControls } from '@/components/ExportControls'
import { useAuth } from '@/hooks/useAuth'
import { isGlobalSuperAdmin } from '@/lib/roles'

/**
 * Exportação de dados para Admin/Super Admin,herda o mesmo painel
 * de período (dia/semana/mês) do Operador via <ExportControls>, e
 * acrescenta os filtros de âmbito: posto, município, província.
 * Todos opcionais e combináveis; sem nenhum, exporta todos os postos.
 *
 * ÂMBITO POR PROVÍNCIA: o backend (`StationExportController::export`)
 * já força sempre `province_id` à província do ADMIN, ignorando
 * silenciosamente qualquer outro valor enviado,mas deixar o filtro
 * "Província" editável e a fingir que aceita outra escolha seria
 * confuso. Por isso, para um ADMIN escopado, o filtro fica bloqueado
 * na sua própria província (mesmo padrão já usado no formulário de
 * postos em `AdminStations.tsx`); só o SUPER_ADMIN o vê editável.
 */
export default function AdminExport() {
  const { user } = useAuth()
  const isSuperAdmin = isGlobalSuperAdmin(user) // escolhe qualquer província
  const [provinceId, setProvinceId] = useState<number | null>(isSuperAdmin ? null : user?.province_id ?? null)
  const [municipalityId, setMunicipalityId] = useState<number | null>(null)
  const [stationId, setStationId] = useState<number | null>(null)

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(provinceId!),
    enabled: !!provinceId,
  })
  // Lista simples de postos para o filtro,volume atual do produto
  // não justifica um endpoint dedicado de pesquisa remota.
  const stationsQuery = useQuery({
    queryKey: ['admin-stations-for-export'],
    queryFn: () => adminService.listStations({ page: 1 }),
  })

  const stationsInScope = (stationsQuery.data?.data ?? []).filter(
    (s) => !municipalityId || s.municipality_id === municipalityId
  )

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-xl font-bold text-dark">Exportar dados</h1>
        <p className="mt-1 text-sm text-gray">
          Histórico de atualizações de estado dos postos, em Excel ou PDF.
        </p>
        {!isSuperAdmin && (
          <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
            {user?.province
              ? <>A exportação está limitada aos dados da província de <strong className="text-dark">{user.province}</strong></>
              : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin para conseguir exportar dados.'}
          </p>
        )}
      </div>

      <ExportControls
        onExport={(period, date, format) =>
          adminService.exportStationStatus({
            period,
            date,
            format,
            station_id: stationId ?? undefined,
            municipality_id: municipalityId ?? undefined,
            province_id: provinceId ?? undefined,
          })
        }
        extraFilters={
          <div className="mt-4 border-t border-gray/10 pt-4">
            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-gray">
              Âmbito (opcional,sem filtros, exporta todos os postos)
            </p>
            <div className="grid gap-3 sm:grid-cols-3">
              <label className="block text-sm">
                <span className="mb-1 block font-medium text-dark">Província</span>
                {isSuperAdmin ? (
                  <select
                    value={provinceId ?? ''}
                    onChange={(e) => {
                      setProvinceId(Number(e.target.value) || null)
                      setMunicipalityId(null)
                      setStationId(null)
                    }}
                    className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                  >
                    <option value="">Todas</option>
                    {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                  </select>
                ) : (
                  <p className="w-full rounded-lg border border-gray/10 bg-light px-3 py-2 text-sm text-gray">
                    {user?.province ?? '—'}
                  </p>
                )}
              </label>

              <label className="block text-sm">
                <span className="mb-1 block font-medium text-dark">Município</span>
                <select
                  value={municipalityId ?? ''}
                  onChange={(e) => { setMunicipalityId(Number(e.target.value) || null); setStationId(null) }}
                  disabled={!provinceId}
                  className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm disabled:opacity-50"
                >
                  <option value="">Todos</option>
                  {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
              </label>

              <label className="block text-sm">
                <span className="mb-1 block font-medium text-dark">Posto</span>
                <select
                  value={stationId ?? ''}
                  onChange={(e) => setStationId(Number(e.target.value) || null)}
                  className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                >
                  <option value="">Todos</option>
                  {stationsInScope.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </label>
            </div>
          </div>
        }
      />
    </div>
  )
}
