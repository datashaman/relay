<?php

namespace App\Enums;

enum AutonomyScope: string
{
    case Global = 'global';
    case Stage = 'stage';
    case Issue = 'issue';
}
