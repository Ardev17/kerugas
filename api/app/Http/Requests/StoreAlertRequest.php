<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'station_id' => ['nullable', 'exists:stations,id'],
            'municipality_id' => ['required_without:station_id', 'nullable', 'exists:municipalities,id'],
            'max_distance_km' => ['nullable', 'integer', 'min:1', 'max:200'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['in:sms,email,push,in_app'],
        ];
    }
}
