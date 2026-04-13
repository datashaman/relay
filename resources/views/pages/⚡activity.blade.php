<?php

use App\Enums\RunStatus;
use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\StageEvent;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Title('Activity Feed')]
#[Layout('layouts::app')]
class extends Component {
    use WithPagination;

    #[Url]
    public string $source = '';

    #[Url]
    public string $stage = '';

    #[Url]
    public string $actor = '';

    #[Url]
    public string $autonomy = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['source', 'stage', 'actor', 'autonomy']);
        $this->resetPage();
    }

    public function with(): array
    {
        $query = StageEvent::with(['stage.run.issue.source'])
            ->orderBy('created_at', 'desc');

        if ($this->source !== '') {
            $query->whereHas('stage.run.issue', fn ($q) => $q->where('source_id', $this->source));
        }
        if ($this->stage !== '') {
            $query->whereHas('stage', fn ($q) => $q->where('name', $this->stage));
        }
        if ($this->actor !== '') {
            $query->where('actor', $this->actor);
        }
        if ($this->autonomy !== '') {
            $query->where('payload->autonomy_level', $this->autonomy);
        }

        $dayAgo = now()->subDay();
        $weekAgo = now()->subWeek();

        $completedDay = Stage::where('status', StageStatus::Completed)
            ->where('completed_at', '>=', $dayAgo)
            ->count();
        $completedWeek = Run::where('status', RunStatus::Completed)
            ->where('completed_at', '>=', $weekAgo)
            ->count();
        $failedWeek = Run::where('status', RunStatus::Failed)
            ->where('completed_at', '>=', $weekAgo)
            ->count();

        $successDenominator = $completedWeek + $failedWeek;

        return [
            'events' => $query->paginate(50),
            'sources' => Source::where('is_active', true)->orderBy('name')->get(),
            'health' => [
                'throughput_per_hour' => round($completedDay / 24, 1),
                'success_rate_pct' => $successDenominator > 0
                    ? round($completedWeek / $successDenominator * 100, 1)
                    : null,
                'active_agents' => Stage::where('status', StageStatus::Running)->count(),
                'shipped_total' => Run::where('status', RunStatus::Completed)->count(),
            ],
            'stuckCount' => Run::where('status', RunStatus::Stuck)->count(),
            'stuckRuns' => Run::with('issue.source')
                ->where('status', RunStatus::Stuck)
                ->orderByDesc('updated_at')
                ->get(),
            'hasFilters' => $this->source !== '' || $this->stage !== '' || $this->actor !== '' || $this->autonomy !== '',
        ];
    }
};
?>

<div class="space-y-6">
    {{-- Pipeline Health strip --}}
    <section class="bg-surface-container-low rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
            <span class="font-label text-[10px] text-outline uppercase tracking-widest">Pipeline Health</span>
            <span class="font-label text-[10px] text-secondary uppercase tracking-widest">Live</span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="flex flex-col">
                <span class="font-headline text-2xl text-secondary">{{ number_format($health['throughput_per_hour'], 1) }}<span class="text-sm text-on-surface-variant">/hr</span></span>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest mt-0.5">Throughput (24h)</span>
            </div>
            <div class="flex flex-col">
                <span class="font-headline text-2xl text-secondary">
                    @if ($health['success_rate_pct'] === null)
                        <span class="text-on-surface-variant">—</span>
                    @else
                        {{ $health['success_rate_pct'] }}<span class="text-sm text-on-surface-variant">%</span>
                    @endif
                </span>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest mt-0.5">Success Rate (7d)</span>
            </div>
            <div class="flex flex-col">
                <span class="font-headline text-2xl text-on-surface">{{ $health['active_agents'] }}</span>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest mt-0.5">Active Agents</span>
            </div>
            <div class="flex flex-col">
                <span class="font-headline text-2xl text-on-surface">{{ number_format($health['shipped_total']) }}</span>
                <span class="font-label text-[10px] text-outline uppercase tracking-widest mt-0.5">Total Shipped</span>
            </div>
        </div>
    </section>

    {{-- System / Stuck Issues callout --}}
    @if ($stuckCount > 0)
        <section class="bg-surface-container-low rounded-xl overflow-hidden border-l-4 border-stage-stuck shadow-[0_0_40px_-16px_rgba(239,159,39,0.3)]">
            <div class="flex items-center justify-between px-4 py-2 bg-surface-container">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-stage-stuck animate-pulse"></span>
                    <span class="font-label text-[10px] text-stage-stuck uppercase tracking-widest">System / Stuck Issues</span>
                </div>
                <span class="font-label text-[10px] text-stage-stuck uppercase tracking-widest">
                    {{ $stuckCount }} {{ \Illuminate\Support\Str::plural('issue', $stuckCount) }}
                </span>
            </div>
            <div class="divide-y divide-outline-variant/20">
                @foreach ($stuckRuns as $run)
                    <a href="{{ route('issues.show', $run->issue) }}" wire:key="stuck-{{ $run->id }}" class="flex items-center justify-between gap-3 px-4 py-2 hover:bg-surface-container transition-colors">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-label text-[10px] text-primary uppercase tracking-widest">
                                    {{ $run->issue->source->type->value === 'jira' ? $run->issue->external_id : 'RLY-' . $run->issue->external_id }}
                                </span>
                                <span class="font-label text-[10px] text-stage-stuck uppercase tracking-widest">
                                    {{ str_replace('_', ' ', $run->stuck_state?->value ?? 'stuck') }}
                                </span>
                            </div>
                            <p class="text-sm text-on-surface line-clamp-1">{{ $run->issue->title }}</p>
                        </div>
                        <span class="font-label text-[10px] text-outline uppercase tracking-widest whitespace-nowrap">
                            {{ $run->updated_at->diffForHumans(null, true) }} ago
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Event stream header --}}
    <div class="flex items-center justify-between px-1">
        <h1 class="font-headline text-xl font-bold text-on-surface">Event Stream</h1>
        <span class="font-label text-[10px] text-outline uppercase tracking-widest">
            {{ number_format($events->total()) }} {{ \Illuminate\Support\Str::plural('event', $events->total()) }}
        </span>
    </div>

    {{-- Filter pills row --}}
    <div class="bg-surface-container-low rounded-xl p-4">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
            <div>
                <label for="source" class="block font-label text-[10px] text-outline uppercase tracking-widest mb-1">Source</label>
                <select wire:model.live="source" id="source" class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-1.5">
                    <option value="">All Sources</option>
                    @foreach ($sources as $src)
                        <option value="{{ $src->id }}">{{ $src->external_account ?? $src->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="stage" class="block font-label text-[10px] text-outline uppercase tracking-widest mb-1">Stage</label>
                <select wire:model.live="stage" id="stage" class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-1.5">
                    <option value="">All Stages</option>
                    @foreach (\App\Enums\StageName::cases() as $stageName)
                        <option value="{{ $stageName->value }}">{{ ucfirst($stageName->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="actor" class="block font-label text-[10px] text-outline uppercase tracking-widest mb-1">Actor</label>
                <select wire:model.live="actor" id="actor" class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-1.5">
                    <option value="">All Actors</option>
                    <option value="system">System</option>
                    <option value="user">User</option>
                    <option value="preflight_agent">Preflight Agent</option>
                    <option value="implement_agent">Implement Agent</option>
                    <option value="verify_agent">Verify Agent</option>
                    <option value="release_agent">Release Agent</option>
                </select>
            </div>
            <div>
                <label for="autonomy" class="block font-label text-[10px] text-outline uppercase tracking-widest mb-1">Autonomy</label>
                <select wire:model.live="autonomy" id="autonomy" class="w-full rounded-md bg-surface-container-lowest border-outline-variant text-on-surface text-sm px-3 py-1.5">
                    <option value="">All Levels</option>
                    @foreach (\App\Enums\AutonomyLevel::cases() as $level)
                        <option value="{{ $level->value }}">{{ ucfirst($level->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                @if ($hasFilters)
                    <button type="button" wire:click="clearFilters" class="rounded-md bg-surface-container-high text-on-surface-variant px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                        Clear
                    </button>
                @endif
            </div>
        </div>
    </div>

    @if ($events->isEmpty())
        <div class="mt-4 rounded-xl bg-surface-container-low p-8 text-center text-on-surface-variant">
            <p class="text-lg">No activity yet.</p>
            <p class="text-sm mt-1">Events from agent runs and user actions will appear here.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($events as $event)
                @php
                    $stage = $event->stage;
                    $run = $stage?->run;
                    $issue = $run?->issue;
                    $source = $issue?->source;
                    $isAlert = in_array($event->type, ['stuck', 'failed', 'bounced']);
                    $isToolCall = $event->type === 'tool_call';
                    $issueRef = $issue ? ($source?->type->value === 'jira' ? $issue->external_id : 'RLY-' . $issue->external_id) : null;

                    $stageSlug = $stage?->name->value;
                    $humanAskedLabel = match ($event->type) {
                        'clarification_requested' => ucfirst($stageSlug ?? 'preflight') . ' · Clarification Needed',
                        'approval_requested' => ucfirst($stageSlug ?? 'stage') . ' · Approval Needed',
                        default => null,
                    };
                    $actionLabel = match (true) {
                        $isAlert => 'System Alert',
                        $event->actor === 'user' => 'Human Action',
                        $humanAskedLabel !== null => $humanAskedLabel,
                        $event->type === 'started' => 'Started',
                        $event->type === 'completed' => 'Completed',
                        $event->type === 'failed' => 'Failed',
                        $event->type === 'bounced' => 'Bounced',
                        $event->type === 'approved' => 'Approved',
                        $event->type === 'tool_call' => 'Tool Call',
                        $event->type === 'stuck' => 'Stuck',
                        default => str_replace('_', ' ', $event->type),
                    };
                    $actionLabelColor = match (true) {
                        $isAlert => 'text-stage-stuck',
                        $event->actor === 'user' => 'text-secondary',
                        $humanAskedLabel !== null => 'text-stage-stuck',
                        $stageSlug === 'preflight' => 'text-stage-preflight',
                        $stageSlug === 'implement' => 'text-stage-implement',
                        $stageSlug === 'verify' => 'text-stage-verify',
                        $stageSlug === 'release' => 'text-stage-release',
                        default => 'text-on-surface-variant',
                    };

                    $cardClass = $isAlert
                        ? 'bg-surface-container-low border-l-4 border-stage-stuck shadow-[0_0_40px_-16px_rgba(239,159,39,0.25)]'
                        : 'bg-surface-container-low';
                @endphp
                <div class="rounded-xl {{ $cardClass }} px-4 py-3" wire:key="event-{{ $event->id }}">
                    <div class="flex items-start gap-3">
                        <x-event-actor :actor="$isAlert ? 'system' : $event->actor" />

                        <div class="flex-1 min-w-0 space-y-2">
                            <div class="flex items-center gap-2 flex-wrap font-label text-[10px] uppercase tracking-widest">
                                <span class="{{ $actionLabelColor }}">{{ $actionLabel }}</span>
                                <span class="text-outline-variant">·</span>
                                <span class="text-outline">{{ $event->created_at->format('H:i:s') }} UTC</span>
                                @if ($run && $run->iteration > 1)
                                    <span class="text-outline-variant">·</span>
                                    <span class="text-outline">↺ {{ $run->iteration }}</span>
                                @endif
                            </div>

                            @if ($event->type === 'stuck' && $run)
                                <h3 class="text-sm font-semibold text-on-surface leading-snug">
                                    @if ($issueRef)
                                        <span class="font-label text-xs text-primary mr-1">{{ $issueRef }}</span>
                                    @endif
                                    {{ $issue?->title ?? 'Stuck' }}
                                </h3>
                                @if (! empty($event->payload['reason']))
                                    <p class="text-sm text-on-surface-variant italic border-l-2 border-outline-variant pl-3">
                                        {{ $event->payload['reason'] }}
                                    </p>
                                @endif
                                <div class="flex items-center gap-2 pt-1">
                                    <a href="{{ route('issues.show', $run->issue) }}" class="rounded-md bg-primary text-on-primary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                                        Give Guidance
                                    </a>
                                    <a href="{{ route('runs.timeline', $run) }}" class="rounded-md bg-surface-container-high text-on-surface px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                                        View Timeline
                                    </a>
                                </div>
                            @else
                                <h3 class="text-sm font-semibold text-on-surface leading-snug">
                                    <x-event-label :type="$event->type" />
                                    @if ($issueRef)
                                        <span class="text-on-surface-variant">for</span>
                                        <span class="font-label text-xs text-primary">{{ $issueRef }}</span>
                                    @endif
                                </h3>

                                @if ($issue && ! $isToolCall)
                                    <p class="text-xs text-on-surface-variant line-clamp-2">
                                        {{ $issue->title }}
                                    </p>
                                    <p class="font-label text-[10px] text-outline uppercase tracking-widest">
                                        {{ $source->external_account ?? $source->name }}
                                    </p>
                                @endif

                                @if ($isToolCall && $event->payload)
                                    <div class="rounded-md bg-surface-container-lowest px-3 py-2 font-label text-xs text-on-surface space-y-0.5">
                                        @if (! empty($event->payload['tool']))
                                            <div><span class="text-outline">EXEC:</span> <span class="text-stage-verify">{{ $event->payload['tool'] }}</span></div>
                                        @endif
                                        @if (! empty($event->payload['path']))
                                            <div><span class="text-outline">PATH:</span> {{ $event->payload['path'] }}</div>
                                        @endif
                                        @if (! empty($event->payload['count']))
                                            <div><span class="text-outline">COUNT:</span> {{ $event->payload['count'] }}</div>
                                        @endif
                                        @if (! empty($event->payload['mode']))
                                            <div><span class="text-outline">MODE:</span> <span class="text-stage-implement">{{ $event->payload['mode'] }}</span></div>
                                        @endif
                                    </div>
                                @elseif ($event->type === 'completed' && $stage?->name === \App\Enums\StageName::Verify && $event->payload)
                                    <div class="flex items-center gap-2 flex-wrap">
                                        @if (! empty($event->payload['tests']))
                                            <span class="inline-flex items-center rounded bg-stage-verify/20 text-stage-verify px-2 py-0.5 font-label text-[10px] uppercase tracking-widest">
                                                TESTS: {{ $event->payload['tests'] }}
                                            </span>
                                        @endif
                                        @if (! empty($event->payload['coverage_delta']))
                                            <span class="inline-flex items-center rounded bg-stage-verify/20 text-stage-verify px-2 py-0.5 font-label text-[10px] uppercase tracking-widest">
                                                COVERAGE: {{ $event->payload['coverage_delta'] }}
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    <x-event-payload :event="$event" />
                                @endif

                                @php
                                    $needsApproval = $event->type === 'approval_requested'
                                        && $stage?->status === \App\Enums\StageStatus::AwaitingApproval;
                                    $needsClarification = $event->type === 'clarification_requested'
                                        && $stage?->status === \App\Enums\StageStatus::AwaitingApproval;
                                @endphp

                                @if ($needsApproval)
                                    <div class="flex items-center gap-2 pt-2">
                                        <form method="POST" action="{{ route('issues.approve', $stage) }}" class="contents">
                                            @csrf
                                            <button type="submit" class="rounded-md bg-secondary text-on-secondary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-secondary/90">
                                                Approve
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('issues.reject-stage', $stage) }}" class="contents"
                                              onsubmit="return confirm('Reject this stage?')">
                                            @csrf
                                            <button type="submit" class="rounded-md bg-surface-container-high text-on-surface px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-surface-container-highest">
                                                Reject
                                            </button>
                                        </form>
                                        <a href="{{ route('issues.show', $issue) }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline ml-auto">
                                            Open Issue →
                                        </a>
                                    </div>
                                @elseif ($needsClarification)
                                    <div class="flex items-center gap-2 pt-2">
                                        <a href="{{ route('preflight.show', $run) }}" class="rounded-md bg-primary text-on-primary px-3 py-1.5 font-label text-[10px] uppercase tracking-widest hover:bg-primary/90">
                                            Answer Questions
                                        </a>
                                        <a href="{{ route('issues.show', $issue) }}" class="font-label text-[10px] text-primary uppercase tracking-widest hover:underline ml-auto">
                                            Open Issue →
                                        </a>
                                    </div>
                                @endif
                            @endif
                        </div>

                        @if ($run && $event->type !== 'stuck' && ! ($needsApproval ?? false) && ! ($needsClarification ?? false))
                            <a href="{{ route('runs.timeline', $run) }}" class="shrink-0 font-label text-[10px] text-primary uppercase tracking-widest hover:underline pt-1" title="View run timeline">
                                View →
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $events->links() }}
        </div>
    @endif
</div>
