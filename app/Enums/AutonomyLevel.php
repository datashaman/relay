<?php

namespace App\Enums;

enum AutonomyLevel: string
{
    case Manual = 'manual';
    case Supervised = 'supervised';
    case Assisted = 'assisted';
    case Autonomous = 'autonomous';

    public function order(): int
    {
        return match ($this) {
            self::Manual => 0,
            self::Supervised => 1,
            self::Assisted => 2,
            self::Autonomous => 3,
        };
    }

    public function isTighterThanOrEqual(self $other): bool
    {
        return $this->order() <= $other->order();
    }

    public function isLooserThanOrEqual(self $other): bool
    {
        return $this->order() >= $other->order();
    }
}
