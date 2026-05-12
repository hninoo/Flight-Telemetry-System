<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource['id'],
            'model' => (string) $this->resource['model'],
            'flightNumber' => (string) $this->resource['flightNumber'],
            'origin' => (string) $this->resource['origin'],
            'destination' => (string) $this->resource['destination'],
            'telemetryPort' => (int) $this->resource['telemetryPort'],
        ];
    }
}
