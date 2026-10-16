<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Eventos aceites (secção 102). Lista fechada de propósito — nunca
 * aceitar nomes de evento arbitrários vindos do cliente.
 */
class TrackAnalyticsEventRequest extends FormRequest
{
    private const ALLOWED_EVENTS = [
        'page_view', 'station_view', 'station_search', 'map_open',
        'location_enabled', 'route_clicked', 'favorite_added',
        'alert_created', 'sms_query', 'review_created',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event' => ['required', Rule::in(self::ALLOWED_EVENTS)],
            'payload' => ['nullable', 'array'],
            'session_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
