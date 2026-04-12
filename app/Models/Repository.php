<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}
