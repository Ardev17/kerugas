<?php

namespace App\Http\Requests;

use App\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', new Enum(ReportReason::class)],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
