<?php

namespace App\Support\Enums;

enum ConnectionStatus: string
{
    case Waiting = 'WAITING';
    case Valid = 'VALID';
    case Corrupted = 'CORRUPTED';
    case Error = 'ERROR';
    case Closed = 'CLOSED';
}
