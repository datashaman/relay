<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Component extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'external_id',
        'name',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class)->withTimestamps();
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}
