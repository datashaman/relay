<?php

namespace App\Models;

use App\Enums\IssueStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $source_id
 * @property string $external_id
 * @property string $title
 * @property string|null $body
 * @property string|null $external_url
 * @property string|null $assignee
 * @property IssueStatus $status
 * @property array<int, string>|null $labels
 * @property bool $auto_accepted
 * @property int|null $component_id
 * @property int|null $repository_id
 * @property Carbon|null $archived_at
 * @property string|null $archived_reason
 * @property-read Component|null $component
 * @property-read Repository|null $repository
 * @property-read Source $source
 */
class Issue extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'repository_id',
        'component_id',
        'external_id',
        'title',
        'body',
        'status',
        'raw_status',
        'external_url',
        'assignee',
        'labels',
        'auto_accepted',
        'archived_at',
        'archived_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
            'labels' => 'array',
            'auto_accepted' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Scope to issues that have not been archived.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope to issues that have been archived.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Archive this issue with an optional user-provided reason.
     */
    public function archive(?string $reason = null): void
    {
        $this->update([
            'archived_at' => now(),
            'archived_reason' => $reason,
        ]);
    }

    /**
     * Unarchive this issue, clearing archived_at and archived_reason.
     */
    public function unarchive(): void
    {
        $this->update([
            'archived_at' => null,
            'archived_reason' => null,
        ]);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }
}
