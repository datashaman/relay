<?php

namespace App\Models;

use App\Enums\IssueStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property IssueStatus $status
 * @property array<int, string>|null $labels
 * @property bool $auto_accepted
 * @property int|null $component_id
 * @property int|null $repository_id
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
    ];

    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
            'labels' => 'array',
            'auto_accepted' => 'boolean',
        ];
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
