<?php

namespace App\Enums;

enum StageStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Completed = 'completed';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Stuck = 'stuck';
}
