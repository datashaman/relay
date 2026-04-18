<?php

namespace App\Models;

use App\Enums\FrameworkSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'path',
        'default_branch',
        'worktree_root',
        'setup_script',
        'teardown_script',
        'run_script',
        'framework',
        'framework_source',
    ];

    protected $casts = [
        'framework_source' => FrameworkSource::class,
    ];

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class)->withTimestamps();
    }
}
