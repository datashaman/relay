<?php

namespace App\Enums;

enum FrameworkSource: string
{
    case Payload = 'payload';
    case Ai = 'ai';
    case Manual = 'manual';
}
