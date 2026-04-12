<?php

namespace App\Enums;

enum IssueStatus: string
{
    case Queued = 'queued';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stuck = 'stuck';
}
