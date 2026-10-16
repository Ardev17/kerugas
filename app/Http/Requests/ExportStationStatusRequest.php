<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros de exportação (Excel/PDF) do histórico de estado de postos.
 *
 * Partilhado entre o Operador (`/operator/station/export`, onde
 * `station_id` é sempre forçado ao posto do próprio operador,
 * ignorando qualquer valor enviado) e o Admin/Super Admin
 * (`/admin/stations/export`, onde os filtros de posto/município/
 * província são todos opcionais e combináveis).
 */
class ExportStationStatusRequest extends FormRequest
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
            // Só têm efeito na rota do Admin — o Operador nunca pode
            // escolher outro posto que não o seu (ver myStation()).
            'station_id' => ['nullable', 'exists:stations,id'],
            'municipality_id' => ['nullable', 'exists:municipalities,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
        ];
    }
}
