<?php

namespace App\Events;

use App\Models\Station;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GasAvailabilityChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Station $station)
    {
    }
}
