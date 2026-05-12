# Flight Telemetry System (Laravel 13 + Octane + Swoole + Reverb)

Real-time flight telemetry dashboard built with Laravel 13, PHP 8.3, Laravel Octane, Swoole, Reverb, Redis, Vue 3 and Inertia.

This project was developed for the Onenex Flight Telemetry System challenge. It proxies the upstream flight list, opens one TCP connection per flight, subscribes to binary telemetry streams, validates packets, and displays live telemetry values in a WebSocket-powered dashboard.

## Technology Choices

- Laravel 13 was chosen for a familiar full-stack structure, routing, configuration, console commands, HTTP client, and broadcasting support.
- Laravel Octane with Swoole was chosen because the challenge includes long-running and concurrent work, and Swoole fits this better than a traditional request-only PHP-FPM runtime.
- Swoole coroutines were used for TCP telemetry clients so each flight can maintain its own connection without blocking other flights.
- Laravel Reverb was chosen for first-party WebSocket support and direct integration with Laravel broadcasting.
- Redis was chosen as a lightweight shared runtime service for cache and Reverb-related coordination.
- Vue 3 with Inertia was chosen to keep the dashboard inside the Laravel app while still providing reactive real-time UI updates.
- Docker Compose was chosen so reviewers can run the app, websocket server, telemetry daemon, and Redis with one command.

## Supported Telemetry Source

- Upstream host: `fts.onenex.dev`
- Flight list endpoint: `https://fts.onenex.dev:4000/flights`
- Telemetry protocol: TCP binary packet stream
- WebSocket channels: one public channel per flight, `flight.{id}`

## Features

- REST API proxy for the upstream flight list.
- Real-time dashboard listing all available flights.
- One TCP telemetry client per flight.
- Concurrent TCP processing using Swoole coroutines.
- Binary packet buffering and re-synchronization.
- CRC-16/CCITT-FALSE validation.
- Range validation for altitude, speed, acceleration, thrust and temperature.
- Telemetry values formatted to two decimal places.
- Flight connection statuses: `WAITING`, `VALID`, `CORRUPTED`, `ERROR`, `CLOSED`.
- Reconnection with exponential backoff.
- Memory limit monitor for the telemetry daemon.
- WebSocket broadcasting with Laravel Reverb and Laravel Echo.
- Docker Compose setup for one-command local review.

## Requirements

For Docker-based setup:

- Docker Desktop, OrbStack, or Docker Engine with Docker Compose.
- Internet access to reach `fts.onenex.dev`.

For non-Docker local setup:

- PHP 8.3
- Composer
- Node 22+
- Redis
- Swoole PHP extension
- PHP extensions required by Laravel/Octane, including `pcntl` and `sockets`

Docker is the recommended setup. No local Composer, npm, PHP, or Node setup is required when using Docker.

## Demo

Run the project locally and open:

```text
http://localhost:8000
```

The dashboard should show all flights from the upstream API and update telemetry values through WebSocket messages.

## Documentation

- [Docker Installation](#docker-installation)
- [Local Installation](#local-installation-without-docker)
- [Services](#services)
- [Configuration](#configuration)
- [Console Commands](#console-commands)
- [API](#api)
- [Telemetry Processing](#telemetry-processing)
- [Frontend Dashboard](#frontend-dashboard)
- [Testing And Verification](#testing-and-verification)
- [Architecture](#architecture)
- [Assumptions](#assumptions)
- [Known Limitations](#known-limitations)

## Docker Installation

Clone the repository:

```sh
git clone git@github.com:hninoo/test-TCP.git
cd test-TCP
```

Create a local environment file before starting Compose:

```sh
cp .env.example .env
```

Set a unique `APP_KEY`, `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` in `.env`, and set `VITE_REVERB_APP_KEY` to the same value as `REVERB_APP_KEY`. Do not enable `APP_DEBUG` outside a local reviewer stack.

If port `8000` is already in use, set a different host port in `.env` before starting Compose:

```env
APP_HOST_PORT=8000
APP_URL=http://localhost:8000
```

If port `8080` is already in use, move the Reverb host port as well:

```env
REVERB_HOST_PORT=8080
VITE_REVERB_PORT=8080
```

Build and start all services:

```sh
docker compose up -d --build
```

During startup, the `init-deps` service also installs local `vendor/` and `node_modules/` into the project folder if they are missing. These folders are ignored by Git, but they help local IDEs resolve PHP and TypeScript dependencies.

Open:

```text
http://localhost:${APP_HOST_PORT:-8000}
```

Check service status:

```sh
docker compose ps
```

View logs:

```sh
docker compose logs -f app
docker compose logs -f reverb
docker compose logs -f telemetry
```

Stop services:

```sh
docker compose down
```

Rebuild from scratch:

```sh
docker compose build --no-cache
docker compose up -d
```

## Services

Docker Compose starts these services:

- `app`: Laravel Octane/Swoole HTTP server on `http://localhost:${APP_HOST_PORT:-8000}`.
- `reverb`: Laravel Reverb WebSocket server on `ws://localhost:${REVERB_HOST_PORT:-8080}`.
- `telemetry`: long-running telemetry daemon using `php artisan telemetry:start`.
- `redis`: Redis cache/runtime service.
- `init-env`: one-shot setup service that prepares runtime `.env` values.
- `init-deps`: one-shot setup service that installs local `vendor/` and `node_modules/` when missing.

The `app`, `reverb`, and `telemetry` services use the same Docker image. This keeps dependency installation and runtime behavior consistent.

## Configuration

Docker Compose provides non-secret runtime defaults directly, but it reads `APP_KEY` and Reverb credentials from `.env`. Compose defaults `APP_ENV` to `production` and `APP_DEBUG` to `false` unless `.env` overrides them for local development.

`.env.example` is included for local setup reference. Docker Compose reads `APP_KEY`, `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` from `.env` so deploy-sensitive values are not committed in `docker-compose.yml`. Set those values before starting the services.

## Console Commands

Start the long-running telemetry daemon:

```sh
php artisan telemetry:start
```

Options:

- `--interval`: subscription interval in milliseconds. If omitted, `telemetry.default_interval_ms` is used.
- `--memory`: memory limit in MB. If omitted, `telemetry.memory_limit_mb` is used.

Probe all telemetry streams without opening the dashboard:

```sh
docker compose exec app php artisan telemetry:probe --all --interval=5000 --packets=1 --timeout=20
```

Probe one flight:

```sh
docker compose exec app php artisan telemetry:probe 1 --interval=5000 --packets=3 --timeout=20
```

The probe command is for debugging. The actual real-time daemon is the `telemetry` service running `telemetry:start`.

## API

Flight list proxy:

```text
GET /api/flights
```

Example:

```sh
curl http://localhost:8000/api/flights
```

The endpoint proxies:

```text
https://host:4000/flights
```

## Telemetry Processing

The telemetry daemon:

1. Fetches the flight list from the REST API.
2. Creates one TCP client per flight.
3. Connects to `fts.onenex.dev:{telemetryPort}`.
4. Sends a subscription message:

```json
{
  "type": "subscribe",
  "flightId": "1",
  "intervalMs": 5000
}
```

5. Buffers incoming binary stream chunks.
6. Re-synchronizes by scanning for the next `0x82` start marker.
7. Validates packet size, end marker, CRC, and telemetry ranges.
8. Broadcasts the latest status and data to `flight.{id}`.

Malformed 36-byte packet candidates, including bad end markers, are reported as
`CORRUPTED` and then the parser continues re-synchronizing from the next start
marker.


## Type conventions

`flightId` is treated as a **string** end-to-end (REST → controller → TCP →
WebSocket → frontend). Rationale:

- Upstream `/flights` API returns `id` as a JSON string (`"id": "1"`).
- The TCP server only responds to subscribe messages with a string `flightId`.
- Avoids precision loss for large numeric IDs and supports future
   non-numeric IDs without refactoring.


## Binary Packet Protocol

The parser implements the challenge protocol:

- Packet size: 36 bytes.
- Start marker: `0x82`.
- End marker: `0x80`.
- Packet size field: `0x24`.
- Byte order: big-endian.
- Float format: IEEE 754.
- CRC algorithm: CRC-16/CCITT-FALSE.
- CRC range: bytes `0x00` to `0x1E`.

Validated fields:

| Field | Valid Range | Unit |
| --- | --- | --- |
| Altitude | 9000 to 12000 | m |
| Speed | 220 to 260 | m/s |
| Acceleration | -2 to 2 | m/s2 |
| Thrust | 0 to 200000 | N |
| Temperature | -50 to 50 | C |

Valid telemetry values are rounded to two decimal places before being sent to the dashboard.

## Connection Statuses

Frontend default:

- `WAITING`: initial status after the dashboard loads the flight list.

Backend updates:

- `VALID`: a valid packet was received.
- `CORRUPTED`: structural packet validation, CRC validation, or range validation failed.
- `ERROR`: TCP connection, read, send, timeout, or connection setup error.
- `CLOSED`: TCP connection closed and reconnect is being scheduled.

## Reliability

- `docker-compose.yml` uses `restart: always` for `app`, `reverb`, and `telemetry`.
- The telemetry daemon monitors its own memory usage.
- If memory exceeds `TELEMETRY_MEMORY_LIMIT_MB`, the daemon exits with code `1`.
- Docker restarts the daemon after a crash or memory-limit exit.
- TCP clients reconnect with exponential backoff: `1, 2, 4, 8, 16, 30` seconds.
- Each flight has an independent TCP client, so one broken connection does not block other flights.

## Frontend Dashboard

The dashboard:

- Loads flights from `/api/flights`.
- Creates one card per flight.
- Initializes every card with `WAITING`.
- Subscribes to one WebSocket channel per flight: `flight.{id}`.
- Displays route, aircraft model, telemetry port, status, last update time, altitude, speed, acceleration, thrust, and temperature.

## Local Installation Without Docker

Docker is recommended. For local execution without Docker:

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
npm run build
```

Start Redis separately, then run these in separate terminals:

```sh
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000
php artisan reverb:start --host=0.0.0.0 --port=8080
php artisan telemetry:start --interval=5000
```

For local frontend development:

```sh
npm run dev
```

## Testing And Verification

Docker smoke test:

```sh
docker compose up -d --build
docker compose ps
curl http://localhost:8000/api/flights
docker compose exec app php artisan telemetry:probe --all --interval=5000 --packets=1 --timeout=20
```

Manual WebSocket verification before submission:

1. Open `http://localhost:8000`.
2. Confirm five flight cards are visible.
3. Confirm each card starts as `WAITING`.
4. Watch each card update to `VALID`, `CORRUPTED`, `ERROR`, or `CLOSED` based on live WebSocket messages.
5. Confirm altitude, speed, acceleration, thrust, and temperature values continue updating without refreshing the page.

Frontend type check:

```sh
npm run types:check
```

Frontend production build:

```sh
npm run build
```

Laravel tests:

```sh
php artisan test
```

## Architecture

```text
Browser Dashboard
    |
    | HTTP
    v
Laravel Octane App
    |
    | GET /api/flights
    v
Onenex Flights API

Telemetry Daemon
    |
    | TCP connection per flight
    v
Onenex Telemetry Servers
    |
    | parsed + validated packet
    v
Laravel Event Broadcast
    |
    | Reverb WebSocket channel flight.{id}
    v
Browser Dashboard
```

Main backend classes:

- `App\Console\Commands\StartTelemetry`
- `App\Console\Commands\ProbeTelemetry`
- `App\Services\Client`
- `App\Services\CoroutineRunner`
- `App\Services\PacketParser`
- `App\Support\Crc16Ccitt`
- `App\Support\RangeValidator`

## Assumptions

- The upstream challenge host is available at `fts.onenex.dev`.
- Flight IDs and telemetry ports come from the upstream flight list endpoint.
- Public WebSocket channels are acceptable for this challenge dashboard.
- `TELEMETRY_MEMORY_LIMIT_MB` defaults to `80` because Laravel + Swoole can exceed `20 MB` at baseline.
- Docker Compose is the primary reviewer workflow.
- `vendor/` and `node_modules/` are intentionally not committed.

## Known Limitations

- The Docker setup is intended for local challenge review, not production deployment.
- `.env.example` is a template only. Production deployments must provide unique `APP_KEY` and Reverb credentials through environment-specific secret management, with `APP_DEBUG=false`.
- The telemetry daemon reads the flight list at startup. If the upstream adds or removes flights while the daemon is already running, restart the `telemetry` service to pick up the changed fleet.
- Automated tests cover packet parsing, CRC validation, range validation, malformed packets, API proxy behavior, interval validation, broadcast sanitization, and memory-limit checks. Browser WebSocket behavior should still be verified manually before submission.
