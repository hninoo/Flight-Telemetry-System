<?php

use App\Http\Controllers\Api\FlightController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes here are automatically prefixed with /api by the routing
| configuration in bootstrap/app.php.
|
*/

Route::get('/flights', [FlightController::class, 'index'])->middleware('throttle:60,1');
