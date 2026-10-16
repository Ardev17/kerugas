<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Folha Excel do histórico de estado de postos. As linhas já chegam
 * prontas (array associativo por linha) do StationStatusExportService
 * — esta classe só descreve como o maatwebsite/excel deve apresentá-las.
 */
class StationStatusExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly Collection $rows)
    {
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Data/Hora', 'Posto', 'Município', 'Província', 'Gás Disponível', 'Fila', 'Confiança', 'Origem', 'Atualizado por'];
    }

    public function title(): string
    {
        return 'Histórico KeruGás';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => [
                'fillType' => 'solid',
                'startColor' => ['rgb' => '0B5CFF'],
            ]],
        ];
    }
}
