<?php

namespace App\Services;

use App\Enums\GasOrderStatus;
use App\Models\GasOrder;
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
 * Exportação de entregas por condutor (pedido explícito do cliente,
 * Setembro 2026) — para efeitos de pagamento/comissão. Mesma estrutura
 * de `StationStatusExportService` (CSV sempre disponível, PDF via
 * barryvdh/laravel-dompdf com falha explícita se o pacote não estiver
 * instalado), reaproveitada de propósito para os dois exports não
 * divergirem em filtros de período/formatação de ficheiro.
 *
 * Só exporta pedidos `DELIVERED` — um pedido cancelado não gera
 * comissão, e o histórico de cancelamentos já está coberto pelas
 * estatísticas (`DriverStatsService`), não por esta exportação.
 */
class DriverDeliveryExportService
{
    /**
     * @param array{format: string, period: string, date?: ?string, driver_id?: ?int, municipality_id?: ?int, province_id?: ?int} $filters
     */
    public function export(array $filters): BinaryFileResponse|Response|StreamedResponse
    {
        [$from, $to] = $this->dateRange($filters['period'], $filters['date'] ?? null);

        $orders = $this->query($filters, $from, $to)->limit(20000)->get();
        $rows = $this->rows($orders);

        $filename = $this->filename($filters, $from);

        return $filters['format'] === 'csv'
            ? $this->downloadCsv($rows, "{$filename}.csv")
            : $this->downloadPdf($rows, $orders, $filters['period'], $from, $to, "{$filename}.pdf");
    }

    private function downloadCsv(Collection $rows, string $filename): StreamedResponse
    {
        $headings = ['Data/Hora entrega', 'Referência', 'Condutor', 'Cliente', 'Município', 'Província', 'Preço de entrega (Kz)'];

        return response()->streamDownload(function () use ($rows, $headings) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $headings, ';');

            foreach ($rows as $row) {
                // 'preco_raw' existe só para o cálculo do resumo do
                // PDF (ver summaryStats()) — nunca deve ir para o CSV,
                // que segue exatamente as colunas de $headings acima.
                fputcsv($handle, [
                    $row['data_hora'], $row['referencia'], $row['condutor'], $row['cliente'],
                    $row['municipio'], $row['provincia'], $row['preco'],
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function downloadPdf(Collection $rows, Collection $orders, string $period, Carbon $from, Carbon $to, string $filename): BinaryFileResponse|Response
    {
        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException(
                'A exportação em PDF requer uma dependência que ainda não foi instalada no servidor. '
                . 'Peça ao responsável técnico para correr: composer require barryvdh/laravel-dompdf'
            );
        }

        try {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.driver-deliveries', [
                'rows' => $rows,
                'periodLabel' => $this->periodLabel($period, $from, $to),
                'generatedAt' => now()->format('d/m/Y H:i'),
                'logoDataUri' => $this->logoDataUri(),
                'stats' => $this->summaryStats($orders),
            ])->setPaper('a4', 'landscape')->download($filename);
        } catch (Throwable $e) {
            Log::channel('single')->error('[DriverDeliveryExportService] Falha ao gerar PDF', ['error' => $e->getMessage()]);

            throw new RuntimeException('Não foi possível gerar o PDF. Tente novamente ou contacte o suporte.');
        }
    }

    private function logoDataUri(): ?string
    {
        $path = resource_path('images/kerugas-logo.png');

        if (!is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }

    /**
     * Recebe os modelos `GasOrder` originais (não as linhas já
     * formatadas em string) — somar valores monetários a partir de
     * texto já formatado ("1.234,56 Kz") é frágil; o `delivery_price`
     * numérico do modelo é a fonte da verdade.
     */
    private function summaryStats(Collection $orders): array
    {
        return [
            'total' => $orders->count(),
            'condutores_unicos' => $orders->pluck('assigned_to')->unique()->count(),
            'valor_total' => number_format((float) $orders->sum('delivery_price'), 2, ',', '.') . ' Kz',
        ];
    }

    private function query(array $filters, Carbon $from, Carbon $to): Builder
    {
        $query = GasOrder::query()
            ->with(['assignedUser', 'municipality', 'province'])
            ->where('status', GasOrderStatus::DELIVERED)
            ->whereNotNull('assigned_to')
            ->whereBetween('completed_at', [$from, $to])
            ->orderBy('completed_at');

        if (!empty($filters['driver_id'])) {
            $query->where('assigned_to', $filters['driver_id']);
        }

        if (!empty($filters['municipality_id'])) {
            $query->where('municipality_id', $filters['municipality_id']);
        }

        if (!empty($filters['province_id'])) {
            $query->where('province_id', $filters['province_id']);
        }

        return $query;
    }

    private function rows(Collection $orders): Collection
    {
        return $orders->map(fn (GasOrder $order) => [
            'data_hora' => $order->completed_at?->format('d/m/Y H:i') ?? '—',
            'referencia' => $order->order_reference,
            'condutor' => $order->assignedUser?->name ?? '—',
            'cliente' => $order->customer_name,
            'municipio' => $order->municipality?->name ?? '—',
            'provincia' => $order->province?->name ?? '—',
            'preco' => $order->delivery_price !== null ? number_format((float) $order->delivery_price, 2, ',', '.') . ' Kz' : '—',
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
        $scope = $filters['driver_id'] ?? $filters['municipality_id'] ?? $filters['province_id'] ?? 'geral';

        return 'kerugas-entregas-condutor-' . $filters['period'] . '-' . $from->format('Y-m-d') . '-' . $scope;
    }
}
