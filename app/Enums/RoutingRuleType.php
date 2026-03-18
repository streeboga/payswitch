<?php

declare(strict_types=1);

namespace App\Enums;

enum RoutingRuleType: string
{
    case Priority = 'priority';
    case RuleBased = 'rule_based';
    case VolumeSplit = 'volume_split';
}
