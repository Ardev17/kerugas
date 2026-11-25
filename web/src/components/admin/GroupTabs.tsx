/**
 * Abas de categoria com contagem (Outubro 2026),roda na horizontal no
 * telemóvel. Abas com 0 ficam visíveis mas discretas.
 */
export function GroupTabs({ tabs, labels, value, onChange, ariaLabel }: {
  tabs: { key: string; count: number }[]
  labels: Record<string, string>
  value: string
  onChange: (key: string) => void
  ariaLabel: string
}) {
  return (
    <div role="tablist" aria-label={ariaLabel} className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:px-0">
      {tabs.map((t) => {
        const active = t.key === value
        return (
          <button
            key={t.key}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(t.key)}
            className={`flex shrink-0 items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors ${
              active ? 'border-primary bg-primary text-white' : t.count === 0 ? 'border-gray/15 text-gray/60' : 'border-gray/20 text-dark hover:bg-light'
            }`}
          >
            {labels[t.key] ?? t.key}
            <span className={`rounded-full px-1.5 text-xs ${active ? 'bg-white/20' : 'bg-light'}`}>{t.count}</span>
          </button>
        )
      })}
    </div>
  )
}
