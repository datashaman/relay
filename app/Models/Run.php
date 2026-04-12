<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\StuckState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_id',
        'status',
        'stuck_state',
        'branch',
        'worktree_path',
        'preflight_doc',
        'known_facts',
        'clarification_questions',
        'clarification_answers',
        'iteration',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'stuck_state' => StuckState::class,
            'known_facts' => 'array',
            'clarification_questions' => 'array',
            'clarification_answers' => 'array',
            'iteration' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }
}
