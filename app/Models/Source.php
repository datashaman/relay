<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'external_account',
        'last_synced_at',
        'is_active',
        'config',
        'sync_error',
        'next_retry_at',
        'sync_interval',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
            'config' => 'array',
            'next_retry_at' => 'datetime',
            'sync_interval' => 'integer',
        ];
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function oauthTokens(): HasMany
    {
        return $this->hasMany(OauthToken::class);
    }

    public function filterRule(): HasOne
    {
        return $this->hasOne(FilterRule::class);
    }
}
