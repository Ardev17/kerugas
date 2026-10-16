<?php

namespace App\Services;

use App\Enums\StationUpdateSource;
use App\Models\StationStatusUpdate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Motor único de exportação do histórico de estado de postos —
 * partilhado entre `/operator/station/export` (sempre restrito ao
 * posto do operador) e `/admin/stations/export` (posto/município/
 * província opcionais e combináveis). Um único sítio para a lógica
 * de filtros, intervalo de datas e formatação de linhas evita
 * divergência entre o que o Operador e o Admin veem exportado.
 *
 * BUG CORRIGIDO: a versão "Excel" gerava mesmo um .xlsx através do
 * pacote maatwebsite/excel — mas isso exige `composer require
 * maatwebsite/excel`, e sem esse passo (fácil de esquecer, e mais um
 * pacote com dependências de extensões PHP como zip/xml, a mesma
 * categoria de problema que já tiveste no Windows) a exportação
 * rebentava com "Class not found". Substituído por geração de CSV
 * pura em PHP, sem nenhuma dependência nova — o CSV abre perfeitamente
 * no Excel (aliás, é o mesmo formato que o Excel usa internamente
 * para "guardar como CSV"), com separador ";" e BOM UTF-8 para
 * acentuação portuguesa aparecer corretamente. O PDF continua a
 * precisar do barryvdh/laravel-dompdf (não há forma de gerar PDF real
 * sem alguma biblioteca) — mas agora falha com uma mensagem clara em
 * vez de um erro técnico em bruto.
 */
class StationStatusExportService
{
    /**
     * @param array{format: string, period: string, date?: ?string, station_id?: ?int, municipality_id?: ?int, province_id?: ?int} $filters
     */
    public function export(array $filters): BinaryFileResponse|Response|StreamedResponse
    {
        [$from, $to] = $this->dateRange($filters['period'], $filters['date'] ?? null);

        $rows = $this->rows($this->query($filters, $from, $to));

        $filename = $this->filename($filters, $from);

        return $filters['format'] === 'csv'
            ? $this->downloadCsv($rows, "{$filename}.csv")
            : $this->downloadPdf($rows, $filters['period'], $from, $to, "{$filename}.pdf");
    }

    private function downloadCsv(Collection $rows, string $filename): StreamedResponse
    {
        $headings = ['Data/Hora', 'Posto', 'Município', 'Província', 'Gás Disponível', 'Fila', 'Confiança', 'Origem', 'Atualizado por'];

        return response()->streamDownload(function () use ($rows, $headings) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8: sem isto, o Excel no Windows mostra acentos
            // portugueses trocados (ex: "É" em vez de "Confiança").
            fwrite($handle, "\xEF\xBB\xBF");

            // ";" como separador: o Excel em configurações regionais
            // portuguesas/europeias usa "," como separador decimal,
            // por isso espera ";" a separar colunas em CSV — com ","
            // o ficheiro abre tudo numa coluna só.
            fputcsv($handle, $headings, ';');

            foreach ($rows as $row) {
                fputcsv($handle, array_values($row), ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function downloadPdf(Collection $rows, string $period, Carbon $from, Carbon $to, string $filename): BinaryFileResponse|Response
    {
        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException(
                'A exportação em PDF requer uma dependência que ainda não foi instalada no servidor. '
                . 'Peça ao responsável técnico para correr: composer require barryvdh/laravel-dompdf'
            );
        }

        try {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.station-status', [
                'rows' => $rows,
                'periodLabel' => $this->periodLabel($period, $from, $to),
                'generatedAt' => now()->format('d/m/Y H:i'),
                'logoDataUri' => $this->logoDataUri(),
                'stats' => $this->summaryStats($rows),
            ])->setPaper('a4', 'landscape')->download($filename);
        } catch (Throwable $e) {
            Log::channel('single')->error('[StationStatusExportService] Falha ao gerar PDF', ['error' => $e->getMessage()]);

            throw new RuntimeException('Não foi possível gerar o PDF. Tente novamente ou contacte o suporte.');
        }
    }

    /**
     * Logótipo embutido em base64 (data URI) em vez de um caminho de
     * ficheiro — o dompdf resolve caminhos relativos de forma
     * inconsistente consoante o ambiente (Windows/Laragon vs
     * produção), e uma URL remota exigiria `isRemoteEnabled` no
     * config do dompdf. Um data URI funciona sempre, sem configuração.
     */
    private function logoDataUri(): ?string
    {
        $path = resource_path('images/kerugas-logo.png');

        if (!is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }

    /**
     * Pequeno resumo em destaque no topo do PDF — dá contexto imediato
     * sem obrigar a percorrer a tabela inteira.
     */
    private function summaryStats(Collection $rows): array
    {
        $total = $rows->count();
        $comGas = $rows->where('gas_disponivel', 'Sim')->count();

        return [
            'total' => $total,
            'com_gas' => $comGas,
            'sem_gas' => $total - $comGas,
            'postos_unicos' => $rows->pluck('posto')->unique()->count(),
            'percentagem_com_gas' => $total > 0 ? round(($comGas / $total) * 100) : 0,
        ];
    }

    private function query(array $filters, Carbon $from, Carbon $to): Builder
    {
        $query = StationStatusUpdate::query()
            ->with(['station.province', 'station.municipality', 'updatedBy'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at');

        if (!empty($filters['station_id'])) {
            $query->where('station_id', $filters['station_id']);
        }

        if (!empty($filters['municipality_id'])) {
            $query->whereHas('station', fn ($q) => $q->where('municipality_id', $filters['municipality_id']));
        }

        if (!empty($filters['province_id'])) {
            $query->whereHas('station', fn ($q) => $q->where('province_id', $filters['province_id']));
        }

        return $query;
    }

    private function rows(Builder $query): Collection
    {
        // limite de segurança: uma exportação não deve tentar carregar
        // centenas de milhares de linhas para memória de uma vez.
        return $query->limit(20000)->get()->map(fn (StationStatusUpdate $u) => [
            'data_hora' => $u->created_at->format('d/m/Y H:i'),
            'posto' => $u->station->name,
            'municipio' => $u->station->municipality->name,
            'provincia' => $u->station->province->name,
            'gas_disponivel' => $u->gas_available ? 'Sim' : 'Não',
            'fila' => $u->queue_level->label(),
            'confianca' => $u->confidence_score . '%',
            'origem' => $this->sourceLabel($u->source),
            'atualizado_por' => $u->updatedBy?->name ?? '—',
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(string $period, ?string $date): array
    {
        $reference = $date ? Carbon::parse($date) : now();

        return match ($period) {
            'day' => [$reference->copy()->startOfDay(), $reference->copy()->endOfDay()],
            'week' => [$reference->copy()->startOfWeek(), $reference->copy()->endOfWeek()],
            'month' => [$reference->copy()->startOfMonth(), $reference->copy()->endOfMonth()],
        };
    }

    private function periodLabel(string $period, Carbon $from, Carbon $to): string
    {
        return match ($period) {
            'day' => 'Dia ' . $from->format('d/m/Y'),
            'week' => 'Semana de ' . $from->format('d/m/Y') . ' a ' . $to->format('d/m/Y'),
            'month' => 'Mês de ' . $from->translatedFormat('F Y'),
        };
    }

    private function filename(array $filters, Carbon $from): string
    {
        $scope = $filters['station_id'] ?? $filters['municipality_id'] ?? $filters['province_id'] ?? 'geral';

        return 'kerugas-historico-' . $filters['period'] . '-' . $from->format('Y-m-d') . '-' . $scope;
    }

    private function sourceLabel(StationUpdateSource $source): string
    {
        return match ($source) {
            StationUpdateSource::OFFICIAL_SMS => 'SMS',
            StationUpdateSource::OPERATOR => 'Operador',
            StationUpdateSource::ADMIN => 'Admin',
            StationUpdateSource::COMMUNITY => 'Comunidade',
        };
    }
}
