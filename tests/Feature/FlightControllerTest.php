<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlightControllerTest extends TestCase
{
    public function test_flights_are_returned_through_resource_collection(): void
    {
        Cache::flush();

        Http::fake([
            'fts.onenex.dev:4000/flights' => Http::response([
                [
                    'id' => '1',
                    'model' => 'Boeing 737',
                    'flightNumber' => 'ONX101',
                    'origin' => 'SIN',
                    'destination' => 'BKK',
                    'telemetryPort' => 4001,
                ],
            ]),
        ]);

        $this->getJson('/api/flights')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => '1',
                        'model' => 'Boeing 737',
                        'flightNumber' => 'ONX101',
                        'origin' => 'SIN',
                        'destination' => 'BKK',
                        'telemetryPort' => 4001,
                    ],
                ],
            ]);
    }

    public function test_flights_return_bad_gateway_when_upstream_is_unavailable(): void
    {
        Cache::flush();

        Http::fake([
            'fts.onenex.dev:4000/flights' => Http::response([], 503),
        ]);

        $this->getJson('/api/flights')
            ->assertStatus(502)
            ->assertJson([
                'message' => 'Flight list failed with 503',
            ]);
    }
}
