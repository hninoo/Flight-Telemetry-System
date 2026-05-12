<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class UpstreamUnavailableException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage() !== '' ? $this->getMessage() : 'Flights are unavailable.',
        ], Response::HTTP_BAD_GATEWAY);
    }
}
