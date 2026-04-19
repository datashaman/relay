<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\StuckState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $issue_id
 * @property int|null $repository_id
 * @property RunStatus $status
 * @property StuckState|null $stuck_state
 * @property string|null $guidance
 * @property bool $stuck_unread
 * @property string|null $branch
 * @property string|null $worktree_path
 * @property string|null $preflight_doc
 * @property array<int, array<string, mixed>>|null $preflight_doc_history
 * @property array<int, mixed>|null $known_facts
 * @property array<int, array<string, mixed>>|null $clarification_questions
 * @property array<string, mixed>|null $clarification_answers
 * @property int $preflight_round
 * @property array<int, array<string, mixed>>|null $clarification_history
 * @property string|null $clarification_channel
 * @property int $iteration
 * @property bool $has_conflicts
 * @property Carbon|null $conflict_detected_at
 * @property array<int, string>|null $conflict_files
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property-read Issue $issue
 * @property-read Repository|null $repository
 * @property-read Collection<int, Stage> $stages
 */
class Run extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_id',
        'repository_id',
        'status',
        'stuck_state',
        'guidance',
        'stuck_unread',
        'branch',
        'worktree_path',
        'preflight_doc',
        'preflight_doc_history',
        'known_facts',
        'clarification_questions',
        'clarification_answers',
        'preflight_round',
        'clarification_history',
        'clarification_channel',
        'iteration',
        'has_conflicts',
        'conflict_detected_at',
        'conflict_files',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'stuck_state' => StuckState::class,
            'preflight_doc_history' => 'array',
            'known_facts' => 'array',
            'clarification_questions' => 'array',
            'clarification_answers' => 'array',
            'clarification_history' => 'array',
            'conflict_files' => 'array',
            'stuck_unread' => 'boolean',
            'has_conflicts' => 'boolean',
            'preflight_round' => 'integer',
            'iteration' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'conflict_detected_at' => 'datetime',
        ];
    }

    /**
     * Runs that are still actively in the pipeline — i.e. have a
     * worktree and should be considered for conflict detection.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            RunStatus::Pending,
            RunStatus::Running,
            RunStatus::Stuck,
        ]);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }
}
