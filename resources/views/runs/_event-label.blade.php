@switch($type)
    @case('started')
        Stage started
        @break
    @case('completed')
        Stage completed
        @break
    @case('failed')
        Stage failed
        @break
    @case('paused')
        Paused for approval
        @break
    @case('resumed')
        Approved and resumed
        @break
    @case('bounced')
        Bounced back
        @break
    @case('awaiting_approval')
        Awaiting approval
        @break
    @case('stuck')
        Stuck
        @break
    @case('guidance_received')
        Guidance received
        @break
    @case('restarted')
        Restarted
        @break
    @case('implement_started')
        Implementation started
        @break
    @case('implement_complete')
        Implementation complete
        @break
    @case('implement_no_tool_call')
        Implementation ended (no tool call)
        @break
    @case('implement_loop_limit')
        Tool call limit reached
        @break
    @case('tool_call')
        Tool call
        @break
    @case('clarification_requested')
        Clarification requested
        @break
    @case('clarification_answered')
        Clarification answered
        @break
    @case('escalation_rule_fired')
        Escalation rule fired
        @break
    @case('approval_requested')
        Approval requested
        @break
    @case('approved')
        Approved
        @break
    @case('release_started')
        Release started
        @break
    @case('release_complete')
        Release complete
        @break
    @case('release_no_tool_call')
        Release ended (no tool call)
        @break
    @case('release_loop_limit')
        Release tool limit reached
        @break
    @default
        @if (str_starts_with($type, 'implement.iteration.'))
            Implement iteration {{ str_replace('implement.iteration.', '', $type) }}
        @else
            {{ str_replace('_', ' ', ucfirst($type)) }}
        @endif
@endswitch
