<?php

namespace App\Enums;

enum StuckState: string
{
    case IterationCap = 'iteration_cap';
    case Timeout = 'timeout';
    case AgentUncertain = 'agent_uncertain';
    case ExternalBlocker = 'external_blocker';
    case JobFailed = 'job_failed';
    case PreflightNoConsensus = 'preflight_no_consensus';
}
