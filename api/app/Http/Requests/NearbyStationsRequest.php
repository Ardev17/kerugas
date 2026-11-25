<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NearbyStationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required_without:municipality_id', 'numeric', 'between:-90,90'],
            'longitude' => ['required_without:municipality_id', 'numeric', 'between:-180,180'],
            'municipality_id' => ['required_without:latitude', 'exists:municipalities,id'],
            'gas_available' => ['nullable', 'boolean'],
            'queue_level' => ['nullable', 'in:LOW,MEDIUM,HIGH'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ];
    }
}
