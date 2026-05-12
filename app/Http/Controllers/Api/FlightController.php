<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Services\FlightDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class FlightController extends Controller
{
    public function index(FlightDirectoryService $directory): JsonResponse
    {
        $cacheKey = 'flights:list';
        $cacheTtl = (int) config('telemetry.cache_ttl_seconds', 60);

        $flights = Cache::remember($cacheKey, $cacheTtl, fn () => $directory->all());

        return FlightResource::collection($flights)->response();
    }
}
