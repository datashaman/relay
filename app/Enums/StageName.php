<?php

namespace App\Enums;

enum StageName: string
{
    case Preflight = 'preflight';
    case Implement = 'implement';
    case Verify = 'verify';
    case Release = 'release';
}
