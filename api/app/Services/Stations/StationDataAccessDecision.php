<?php

namespace App\Services\Stations;

use App\Enums\StationDataAccessStatus;
use App\Models\Municipality;

/** O que o StationDataAccessService decidiu, e porquê. */
class StationDataAccessDecision
{
    public function __construct(
        public readonly StationDataAccessStatus $status,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly ?Municipality $municipality,
    ) {
    }

    public static function allowed(?Municipality $municipality): self
    {
        return new self(StationDataAccessStatus::ALLOWED, null, null, $municipality);
    }

    public function httpStatus(): int
    {
        return match ($this->status) {
            StationDataAccessStatus::NEEDS_LOGIN => 401,
            StationDataAccessStatus::BLOCKED => 403,
            StationDataAccessStatus::ALLOWED => 200,
        };
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'success' => false,
            'message' => $this->message,
            'reason' => $this->reason,
            'municipality' => $this->municipality === null ? null : [
                'id' => $this->municipality->id,
                'name' => $this->municipality->name,
            ],
        ];
    }
}
