export type ConnectionStatus = 'WAITING' | 'VALID' | 'CORRUPTED' | 'ERROR' | 'CLOSED';

export interface Flight {
    id: string;
    model: string;
    flightNumber: string;
    origin: string;
    destination: string;
    telemetryPort: number;
}

export interface TelemetryData {
    flightNumber: string;
    packetNumber: number;
    altitude: number;
    speed: number;
    acceleration: number;
    thrust: number;
    temperature: number;
}

export interface TelemetryPayload {
    flightId: string;
    flightNumber: string;
    status: ConnectionStatus;
    data: TelemetryData | null;
    timestamp: number;
}
