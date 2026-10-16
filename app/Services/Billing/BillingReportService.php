<?php

namespace App\Services\Billing;

use App\Models\BottlePickup;
use App\Models\DriverCashReceipt;
use App\Models\GasOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Relatórios da faturação (Outubro 2026), em Excel (CSV com BOM e `;`,
 * abre direto no Excel — o mesmo formato das outras exportações) e PDF
 * (barryvdh/laravel-dompdf, como as restantes exportações):
 *
 *  - day:      fecho do dia — entregas, carregamentos por posto, condutores;
 *  - stations: dívida aos postos — resumo por posto + cada carregamento por pagar;
 *  - drivers:  dinheiro dos condutores num período — resumo + recebimentos.
 *
 * O âmbito é EXATAMENTE o da Faturação (BillingService): o Admin vê os
 * pedidos que confirmou e os postos/condutores da sua província; o Super
 * Admin Provincial a província; o Super Admin geral tudo.
 */
class BillingReportService
{
    public const TYPES = ['day', 'stations', 'drivers'];

    public function __construct(private readonly BillingService $billing)
    {
    }

    /** @return array{title: string, subtitle: string, stats: array, sections: array, filename: string} */
    public function build(Request $request, string $type, array $params): array
    {
        return match ($type) {
            'day' => $this->day($request, $params['date'] ?? null),
            'stations' => $this->stations($request),
            'drivers' => $this->drivers($request, $params['from'] ?? null, $params['to'] ?? null),
        };
    }

    public function download(array $report, string $format): StreamedResponse|Response
    {
        return $format === 'pdf' ? $this->pdf($report) : $this->csv($report);
    }

    // ------------------------------------------------------------------ relatórios

    private function day(Request $request, ?string $date): array
    {
        [$from, $to, $dayKey] = $this->billing->dayRange($date);

        $orders = $this->billing->delivered($request)
            ->whereNotNull('delivery_price')
            ->whereBetween('completed_at', [$from, $to])
            ->with(['priceSetBy:id,name', 'assignedUser:id,name'])
            ->orderBy('completed_at')
            ->get();

        $split = $orders->filter(fn (GasOrder $o) => $o->hasBillingSplit());
        $legacy = $orders->reject(fn (GasOrder $o) => $o->hasBillingSplit());

        $orderRows = $orders->map(fn (GasOrder $o) => [
            $this->time($o->completed_at),
            $o->order_reference,
            $o->priceSetBy?->name ?? '—',
            $o->assignedUser?->name ?? '—',
            (int) $o->quantity,
            $o->bottle_price !== null ? $this->kz($o->bottle_price) : 'por completar',
            $o->commission !== null ? $this->kz($o->commission) : 'por completar',
            $this->kz($o->delivery_price),
        ])->values()->all();

        $pickups = $this->billing->pickups($request)
            ->whereBetween('registered_at', [$from, $to])
            ->with(['station', 'driver:id,name'])
            ->orderBy('registered_at')
            ->get();

        $pickupRows = $pickups->map(fn (BottlePickup $p) => [
            $this->time($p->registered_at),
            $p->station?->name ?? '—',
            $p->driver?->name ?? '—',
            (int) $p->quantity,
            $this->kz($p->amount),
            $this->stateLabel($p),
            $p->station_paid_at ? 'Pago' : 'Por pagar',
        ])->values()->all();

        $driverRows = collect($this->billing->drivers($request, $from, $to))->map(fn (array $d) => [
            $d['driver_name'],
            $this->kz($d['collected_day']),
            $this->kz($d['collected_total']),
            $this->kz($d['received_total']),
            $this->kz($d['outstanding']),
            (int) $d['stock']['available'],
        ])->values()->all();

        $sections = [
            [
                'title' => 'Entregas do dia',
                'headings' => ['Hora', 'Referência', 'Confirmado por', 'Condutor', 'Garrafas', 'Valor das garrafas', 'Comissão', 'Total cobrado'],
                'rows' => $orderRows,
                'totals' => ['Total', '', '', '', (int) $orders->sum('quantity'), $this->kz($split->sum('bottle_price')), $this->kz($split->sum('commission')), $this->kz($orders->sum('delivery_price'))],
            ],
            [
                'title' => 'Carregamentos nos postos',
                'headings' => ['Hora', 'Posto', 'Condutor', 'Garrafas', 'Valor a pagar', 'Confirmação', 'Pagamento'],
                'rows' => $pickupRows,
                'totals' => ['Total', '', '', (int) $pickups->sum('quantity'), $this->kz($pickups->sum('amount')), '', ''],
            ],
            [
                'title' => 'Condutores',
                'headings' => ['Condutor', 'Cobrado no dia', 'Cobrado (total)', 'Entregue', 'Em falta', 'Garrafas disponíveis'],
                'rows' => $driverRows,
                'totals' => null,
            ],
        ];

        if ($legacy->isNotEmpty()) {
            $sections[0]['note'] = "{$legacy->count()} entrega(s) antiga(s) sem divisão (" . $this->kz($legacy->sum('delivery_price')) . ') — não entram no valor das garrafas nem na comissão até serem completadas.';
        }

        $label = Carbon::createFromFormat('Y-m-d', $dayKey)->format('d/m/Y');

        return [
            'title' => 'Fecho do dia',
            'subtitle' => "Dia {$label} (00h–24h, hora de Angola) · " . $this->scopeLabel($request),
            'stats' => [
                ['Entregas', (string) $orders->count()],
                ['Garrafas entregues', (string) (int) $orders->sum('quantity')],
                ['Cobrado aos clientes', $this->kz($orders->sum('delivery_price'))],
                ['Valor das garrafas', $this->kz($split->sum('bottle_price'))],
                ['Comissão Quero Gás', $this->kz($split->sum('commission'))],
                ['Carregamentos (postos)', $this->kz($pickups->sum('amount'))],
            ],
            'sections' => $sections,
            'filename' => "kerugas-fecho-do-dia-{$dayKey}",
        ];
    }

    private function stations(Request $request): array
    {
        $pickups = $this->billing->pickups($request)->payable()
            ->whereNull('station_paid_at')
            ->with(['station', 'driver:id,name'])
            ->orderBy('station_id')->orderBy('registered_at')
            ->get();

        $byStation = $pickups->groupBy('station_id')->map(fn ($rows) => [
            $rows->first()->station?->name ?? 'Posto removido',
            $rows->count(),
            (int) $rows->sum('quantity'),
            $rows->whereNull('confirmed_at')->count(),
            $this->kz($rows->sum('amount')),
            (float) $rows->sum('amount'),
        ])->sortByDesc(fn ($r) => $r[5])->map(fn ($r) => array_slice($r, 0, 5))->values()->all();

        $detail = $pickups->map(fn (BottlePickup $p) => [
            $p->station?->name ?? '—',
            $this->dateTime($p->registered_at),
            $p->driver?->name ?? '—',
            (int) $p->quantity,
            $this->kz($p->amount),
            $this->stateLabel($p),
        ])->values()->all();

        return [
            'title' => 'Dívida aos postos',
            'subtitle' => 'Carregamentos por pagar até ' . now($this->billing->timezone())->format('d/m/Y H:i') . ' · ' . $this->scopeLabel($request),
            'stats' => [
                ['Postos com dívida', (string) count($byStation)],
                ['Carregamentos por pagar', (string) $pickups->count()],
                ['Garrafas', (string) (int) $pickups->sum('quantity')],
                ['Total em dívida', $this->kz($pickups->sum('amount'))],
            ],
            'sections' => [
                [
                    'title' => 'Resumo por posto',
                    'headings' => ['Posto', 'Carregamentos', 'Garrafas', 'Sem confirmação do posto', 'Por pagar'],
                    'rows' => $byStation,
                    'totals' => ['Total', $pickups->count(), (int) $pickups->sum('quantity'), $pickups->whereNull('confirmed_at')->count(), $this->kz($pickups->sum('amount'))],
                ],
                [
                    'title' => 'Detalhe dos carregamentos',
                    'headings' => ['Posto', 'Data', 'Condutor', 'Garrafas', 'Valor', 'Confirmação'],
                    'rows' => $detail,
                    'totals' => null,
                ],
            ],
            'filename' => 'kerugas-divida-postos-' . now($this->billing->timezone())->format('Y-m-d'),
        ];
    }

    private function drivers(Request $request, ?string $fromDate, ?string $toDate): array
    {
        $tz = $this->billing->timezone();
        $start = $fromDate ? Carbon::createFromFormat('Y-m-d', $fromDate, $tz)->startOfDay() : Carbon::now($tz)->startOfMonth();
        $end = $toDate ? Carbon::createFromFormat('Y-m-d', $toDate, $tz)->endOfDay() : Carbon::now($tz)->endOfDay();
        $from = $start->copy()->setTimezone(config('app.timezone'));
        $to = $end->copy()->setTimezone(config('app.timezone'));

        // Saldo atual (em falta hoje) + stock vêm do mesmo cálculo da Faturação.
        $current = collect($this->billing->drivers($request, $from, $to))->keyBy('driver_id');
        $ids = $current->keys();

        $periodOrders = GasOrder::query()->where('status', \App\Enums\GasOrderStatus::DELIVERED)
            ->whereIn('assigned_to', $ids)->whereNotNull('delivery_price')
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('assigned_to, COUNT(*) as orders, SUM(delivery_price) as total')
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $receipts = DriverCashReceipt::query()->whereIn('driver_id', $ids)
            ->whereBetween('received_at', [$from, $to])
            ->with(['driver:id,name', 'receiver:id,name'])
            ->orderBy('received_at')->get();
        $receivedByDriver = $receipts->groupBy('driver_id')->map(fn ($r) => (float) $r->sum('amount'));

        $summary = $current->map(fn (array $d) => [
            $d['driver_name'],
            (int) ($periodOrders[$d['driver_id']]->orders ?? 0),
            $this->kz($periodOrders[$d['driver_id']]->total ?? 0),
            $this->kz($receivedByDriver[$d['driver_id']] ?? 0),
            $this->kz($d['outstanding']),
            (int) $d['stock']['available'],
        ])->values()->all();

        $receiptRows = $receipts->map(fn (DriverCashReceipt $r) => [
            $this->dateTime($r->received_at),
            $r->driver?->name ?? '—',
            $this->kz($r->amount),
            $r->receiver?->name ?? '—',
            $r->note ?? '',
        ])->values()->all();

        $label = $start->format('d/m/Y') . ' a ' . $end->format('d/m/Y');

        return [
            'title' => 'Dinheiro dos condutores',
            'subtitle' => "Período {$label} · " . $this->scopeLabel($request),
            'stats' => [
                ['Cobrado no período', $this->kz($periodOrders->sum('total'))],
                ['Recebido no período', $this->kz($receipts->sum('amount'))],
                ['Em falta hoje (todos)', $this->kz($current->sum('outstanding'))],
            ],
            'sections' => [
                [
                    'title' => 'Resumo por condutor',
                    'headings' => ['Condutor', 'Entregas no período', 'Cobrado no período', 'Recebido no período', 'Em falta hoje', 'Garrafas disponíveis'],
                    'rows' => $summary,
                    'totals' => ['Total', (int) $periodOrders->sum('orders'), $this->kz($periodOrders->sum('total')), $this->kz($receipts->sum('amount')), $this->kz($current->sum('outstanding')), ''],
                ],
                [
                    'title' => 'Recebimentos no período',
                    'headings' => ['Data', 'Condutor', 'Valor', 'Recebido por', 'Nota'],
                    'rows' => $receiptRows,
                    'totals' => null,
                ],
            ],
            'filename' => 'kerugas-condutores-' . $start->format('Y-m-d') . '-a-' . $end->format('Y-m-d'),
        ];
    }

    // ------------------------------------------------------------------ ficheiros

    private function csv(array $report): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // o Excel reconhece os acentos
            fputcsv($out, ['KeruGás — ' . $report['title']], ';', '"', '\\');
            fputcsv($out, [$report['subtitle']], ';', '"', '\\');
            fputcsv($out, ['Gerado em ' . now($this->billing->timezone())->format('d/m/Y H:i')], ';', '"', '\\');
            fputcsv($out, [], ';', '"', '\\');
            foreach ($report['stats'] as [$label, $value]) {
                fputcsv($out, [$label, $value], ';', '"', '\\');
            }
            foreach ($report['sections'] as $section) {
                fputcsv($out, [], ';', '"', '\\');
                fputcsv($out, [$section['title']], ';', '"', '\\');
                fputcsv($out, $section['headings'], ';', '"', '\\');
                foreach ($section['rows'] as $row) {
                    fputcsv($out, $row, ';', '"', '\\');
                }
                if (empty($section['rows'])) {
                    fputcsv($out, ['Sem registos.'], ';', '"', '\\');
                }
                if (!empty($section['totals'])) {
                    fputcsv($out, $section['totals'], ';', '"', '\\');
                }
                if (!empty($section['note'])) {
                    fputcsv($out, [$section['note']], ';', '"', '\\');
                }
            }
            fclose($out);
        }, $report['filename'] . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pdf(array $report): Response
    {
        if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException('A exportação em PDF requer o pacote barryvdh/laravel-dompdf (composer require barryvdh/laravel-dompdf).');
        }

        try {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.billing-report', [
                'report' => $report,
                'generatedAt' => now($this->billing->timezone())->format('d/m/Y H:i'),
                'logoDataUri' => is_file(resource_path('images/kerugas-logo.png'))
                    ? 'data:image/png;base64,' . base64_encode(file_get_contents(resource_path('images/kerugas-logo.png')))
                    : null,
            ])->setPaper('a4', 'landscape')->download($report['filename'] . '.pdf');
        } catch (Throwable $e) {
            Log::channel('single')->error('[BillingReportService] Falha ao gerar PDF', ['error' => $e->getMessage()]);

            throw new RuntimeException('Não foi possível gerar o PDF. Tente novamente ou contacte o suporte.');
        }
    }

    // ------------------------------------------------------------------ formatos

    private function kz(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.') . ' Kz';
    }

    private function time($date): string
    {
        return $date ? $date->copy()->setTimezone($this->billing->timezone())->format('H:i') : '—';
    }

    private function dateTime($date): string
    {
        return $date ? $date->copy()->setTimezone($this->billing->timezone())->format('d/m/Y H:i') : '—';
    }

    private function stateLabel(BottlePickup $p): string
    {
        return match ($p->confirmationState()) {
            'CONFIRMED' => 'Confirmado pelo posto',
            'DISPUTED' => 'Contestado',
            default => 'Só condutor',
        };
    }

    private function scopeLabel(Request $request): string
    {
        /** @var User $u */
        $u = $request->user();

        return match (true) {
            $u->isGlobalSuperAdmin() => 'Todas as províncias',
            $u->isProvinceSuperAdmin() => 'Província: ' . ($u->province?->name ?? '—'),
            default => 'Pedidos confirmados por ' . $u->name . ($u->province ? ' · condutores e postos de ' . $u->province->name : ''),
        };
    }
}
