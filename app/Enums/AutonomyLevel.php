<?php

namespace App\Enums;

enum AutonomyLevel: string
{
    case Manual = 'manual';
    case Supervised = 'supervised';
    case Assisted = 'assisted';
    case Autonomous = 'autonomous';
}
