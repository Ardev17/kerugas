<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros de exportação (CSV/PDF) de entregas por condutor (pedido
 * explícito do cliente, Setembro 2026) — para efeitos de
 * pagamento/comissão. `driver_id` opcional: sem ele, exporta todos os
 * condutores no período (dentro do âmbito da conta — ver
 * `DriverAdminController::export`, que força `province_id` para um
 * ADMIN limitado, exatamente como `ExportStationStatusRequest`).
 */
class ExportDriverDeliveriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate feito pelo middleware `permission:` na rota
    }

    public function rules(): array
    {
        return [
            'format' => ['required', 'in:csv,pdf'],
            'period' => ['required', 'in:day,week,month'],
            'date' => ['nullable', 'date'],
            'driver_id' => ['nullable', 'exists:users,id'],
            'municipality_id' => ['nullable', 'exists:municipalities,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
        ];
    }
}
