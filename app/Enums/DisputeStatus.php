<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeStatus: string
{
    case Opened = 'opened';
    case EvidenceRequired = 'evidence_required';
    case Resolved = 'resolved';
    case Lost = 'lost';
    case Won = 'won';
}
