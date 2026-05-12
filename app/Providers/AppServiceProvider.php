<?php

namespace App\Providers;

use App\Services\FlightDirectoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FlightDirectoryService::class, fn () => new FlightDirectoryService(
            scheme: (string) config('telemetry.api_scheme'),
            host: (string) config('telemetry.host'),
            port: (int) config('telemetry.api_port'),
        ));
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        DB::prohibitDestructiveCommands(app()->isProduction());
    }
}
