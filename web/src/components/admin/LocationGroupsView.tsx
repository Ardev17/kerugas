import { useState, type ReactNode } from 'react'
import { EmptyArt } from '@/components/illustrations/Admin'
import { useInfiniteQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, MapPin } from 'lucide-react'
import { adminGroupsService, locationParam, type GroupParams, type GroupResource, type ProvinceGroup } from '@/services/adminGroupsService'

interface Props<T> {
  resource: GroupResource
  /** Prefixo da chave da cache (ex.: ['admin-users']),as invalidações existentes continuam a funcionar. */
  queryKey: readonly unknown[]
  /** Filtros da lista (papel, estado, gás…), iguais aos usados nas contagens. */
  params: GroupParams
  groups: ProvinceGroup[] | undefined
  isLoading?: boolean
  /** false = só província (ex.: administradores). */
  withMunicipalities: boolean
  renderItem: (item: T) => ReactNode
  emptyText: string
  /** Ilustração do ecrã vazio: `clear` = tudo limpo (ex.: sem denúncias), `inbox` = nada pendente. */
  emptyArt?: 'inbox' | 'clear'
  /** Atualização automática dos itens abertos (ex.: pedidos sem tempo real). */
  refetchInterval?: number
}

/**
 * Secções província → município (Outubro 2026). Os itens de cada secção só
 * são pedidos ao servidor quando ela é aberta, com "Mostrar mais" para
 * grupos grandes. Com uma só província, ela já vem aberta.
 */
export function LocationGroupsView<T>({ resource, queryKey, params, groups, isLoading, withMunicipalities, renderItem, emptyText, emptyArt = 'inbox', refetchInterval }: Props<T>) {
  if (isLoading) return <p className="text-sm text-gray">A carregar...</p>
  if (!groups || groups.length === 0) {
    return (
      <div className="fx-slide-next rounded-xl border border-gray/15 bg-white p-6 text-center">
        <EmptyArt variant={emptyArt} className="mx-auto h-24 w-auto" />
        <p className="mt-2 text-sm text-gray">{emptyText}</p>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      {groups.map((g) => (
        <ProvinceSection key={g.province_id ?? 'none'} group={g} defaultOpen={groups.length === 1}>
          {withMunicipalities && g.municipalities.length > 0 ? (
            <div className="space-y-2">
              {g.municipalities.map((m) => (
                <MunicipalitySection key={m.municipality_id ?? 'none'} title={m.municipality} count={m.count} defaultOpen={g.municipalities.length === 1}>
                  <GroupItems<T>
                    resource={resource}
                    queryKey={queryKey}
                    params={{ ...params, province_id: locationParam(g.province_id), municipality_id: locationParam(m.municipality_id) }}
                    renderItem={renderItem}
                    refetchInterval={refetchInterval}
                  />
                </MunicipalitySection>
              ))}
            </div>
          ) : (
            <GroupItems<T> resource={resource} queryKey={queryKey} params={{ ...params, province_id: locationParam(g.province_id) }} renderItem={renderItem} refetchInterval={refetchInterval} />
          )}
        </ProvinceSection>
      ))}
    </div>
  )
}

function ProvinceSection({ group, defaultOpen, children }: { group: ProvinceGroup; defaultOpen: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <section className="rounded-xl border border-gray/15 bg-white">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
      >
        <span className="flex items-center gap-2 font-semibold text-dark">
          {open ? <ChevronDown size={18} /> : <ChevronRight size={18} />}
          <MapPin size={15} className="text-primary" aria-hidden />
          {group.province}
        </span>
        <span className="rounded-full bg-light px-2 py-0.5 text-xs font-semibold text-gray">{group.count}</span>
      </button>
      {open && <div className="border-t border-gray/10 p-3">{children}</div>}
    </section>
  )
}

function MunicipalitySection({ title, count, defaultOpen, children }: { title: string; count: number; defaultOpen: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <div className="rounded-lg border border-gray/10">
      <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm">
        <span className="flex items-center gap-2 font-medium text-dark">
          {open ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
          {title}
        </span>
        <span className="text-xs text-gray">{count}</span>
      </button>
      {open && <div className="space-y-3 border-t border-gray/10 p-3">{children}</div>}
    </div>
  )
}

function GroupItems<T>({ resource, queryKey, params, renderItem, refetchInterval }: { resource: GroupResource; queryKey: readonly unknown[]; params: GroupParams; renderItem: (item: T) => ReactNode; refetchInterval?: number }) {
  const query = useInfiniteQuery({
    queryKey: [...queryKey, 'group', params],
    queryFn: ({ pageParam }) => adminGroupsService.list<T>(resource, { ...params, page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta && last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
    refetchInterval,
  })

  if (query.isLoading) return <p className="text-sm text-gray">A carregar...</p>
  if (query.isError) return <p className="text-sm text-danger">Não foi possível carregar esta lista.</p>

  const items = query.data?.pages.flatMap((p) => p.data) ?? []

  return (
    <div className="space-y-3">
      {items.map((item) => renderItem(item))}
      {items.length === 0 && <p className="text-sm text-gray">Nada aqui.</p>}
      {query.hasNextPage && (
        <button
          type="button"
          onClick={() => void query.fetchNextPage()}
          disabled={query.isFetchingNextPage}
          className="w-full rounded-lg border border-gray/20 py-2 text-sm font-medium text-dark hover:bg-light disabled:opacity-50"
        >
          {query.isFetchingNextPage ? 'A carregar...' : 'Mostrar mais'}
        </button>
      )}
    </div>
  )
}
