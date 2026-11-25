import { useState, type ReactNode } from 'react'
import { FileSpreadsheet, FileText, Loader2 } from 'lucide-react'
import axios from 'axios'

export type ExportPeriod = 'day' | 'week' | 'month'
export type ExportFormat = 'csv' | 'pdf'

interface ExportControlsProps {
  onExport: (period: ExportPeriod, date: string, format: ExportFormat) => Promise<void>
  /** Filtros extra (posto/município/província),só usado pelo Admin. */
  extraFilters?: ReactNode
  /**
   * Título/descrição opcionais (Setembro 2026),por omissão mantém o
   * texto original (histórico de postos), para não alterar o
   * comportamento de quem já usa este componente sem os passar; a
   * exportação de entregas por condutor passa o seu próprio texto.
   */
  title?: string
  description?: string
}

const PERIOD_LABEL: Record<ExportPeriod, string> = {
  day: 'Dia', week: 'Semana', month: 'Mês',
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

/**
 * Painel de exportação partilhado entre o Operador e o Admin,a
 * mesma UI de período/data/formato, com um slot opcional (`extraFilters`)
 * para os filtros adicionais que só o Admin tem (posto/município/
 * província). Isto garante que a experiência de "herdar a filtragem
 * do operador" é literal: o mesmo componente, não uma reimplementação
 * parecida que podia divergir com o tempo.
 */
/**
 * Pedidos de exportação usam `responseType: 'blob'` (para o ficheiro
 * em si), o que significa que uma resposta de ERRO (ex: 403,Super
 * Admin desativou o analytics) também chega como blob, não como JSON
 * já interpretado. Sem isto, mostraríamos sempre um genérico "não foi
 * possível gerar o ficheiro", escondendo a mensagem real do backend.
 */
async function extractExportErrorMessage(err: unknown): Promise<string> {
  const fallback = 'Não foi possível gerar o ficheiro. Tente novamente.'

  if (axios.isAxiosError(err) && err.response?.data instanceof Blob) {
    try {
      const text = await err.response.data.text()
      const parsed = JSON.parse(text) as { message?: string }
      return parsed.message ?? fallback
    } catch {
      return fallback
    }
  }

  return fallback
}

export function ExportControls({ onExport, extraFilters, title, description }: ExportControlsProps) {
  const [period, setPeriod] = useState<ExportPeriod>('day')
  const [date, setDate] = useState(todayIso())
  const [exportingFormat, setExportingFormat] = useState<ExportFormat | null>(null)
  const [error, setError] = useState<string | null>(null)

  const handleExport = async (format: ExportFormat) => {
    setError(null)
    setExportingFormat(format)
    try {
      await onExport(period, date, format)
    } catch (err) {
      setError(await extractExportErrorMessage(err))
    } finally {
      setExportingFormat(null)
    }
  }

  return (
    <div className="rounded-xl border border-gray/15 bg-white p-5">
      <p className="font-semibold text-dark">{title ?? 'Exportar dados'}</p>
      <p className="mt-1 text-sm text-gray">
        {description ?? 'Descarregue o histórico de atualizações em Excel ou PDF.'}
      </p>

      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">Período</span>
          <select
            value={period}
            onChange={(e) => setPeriod(e.target.value as ExportPeriod)}
            className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
          >
            {(Object.keys(PERIOD_LABEL) as ExportPeriod[]).map((p) => (
              <option key={p} value={p}>{PERIOD_LABEL[p]}</option>
            ))}
          </select>
        </label>

        <label className="block text-sm">
          <span className="mb-1 block font-medium text-dark">
            {period === 'day' ? 'Data' : period === 'week' ? 'Uma data dessa semana' : 'Uma data desse mês'}
          </span>
          <input
            type="date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
          />
        </label>
      </div>

      {extraFilters}

      {error && <p className="mt-3 text-sm text-danger">{error}</p>}

      <div className="mt-4 flex flex-col gap-2 sm:flex-row">
        <button
          onClick={() => handleExport('csv')}
          disabled={exportingFormat !== null}
          className="flex flex-1 items-center justify-center gap-2 rounded-lg border-2 border-success/40 px-4 py-2.5 text-sm font-semibold text-success disabled:opacity-60"
        >
          {exportingFormat === 'csv' ? <Loader2 size={16} className="animate-spin" /> : <FileSpreadsheet size={16} />}
          {exportingFormat === 'csv' ? 'A gerar Excel...' : 'Exportar Excel'}
        </button>
        <button
          onClick={() => handleExport('pdf')}
          disabled={exportingFormat !== null}
          className="flex flex-1 items-center justify-center gap-2 rounded-lg border-2 border-danger/40 px-4 py-2.5 text-sm font-semibold text-danger disabled:opacity-60"
        >
          {exportingFormat === 'pdf' ? <Loader2 size={16} className="animate-spin" /> : <FileText size={16} />}
          {exportingFormat === 'pdf' ? 'A gerar PDF...' : 'Exportar PDF'}
        </button>
      </div>
    </div>
  )
}
