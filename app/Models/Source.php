<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'external_account',
        'last_synced_at',
        'is_active',
        'is_intake_paused',
        'paused_repositories',
        'backlog_threshold',
        'config',
        'sync_error',
        'next_retry_at',
        'sync_interval',
        'webhook_secret',
        'webhook_last_delivery_at',
        'webhook_last_error',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
            'is_intake_paused' => 'boolean',
            'paused_repositories' => 'array',
            'backlog_threshold' => 'integer',
            'config' => 'array',
            'next_retry_at' => 'datetime',
            'sync_interval' => 'integer',
            'webhook_secret' => 'encrypted',
            'webhook_last_delivery_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Source $source): void {
            if (! $source->webhook_secret) {
                $source->webhook_secret = Str::random(40);
            }
        });
    }

    public function ensureWebhookSecret(): string
    {
        if (! $this->webhook_secret) {
            $this->update(['webhook_secret' => Str::random(40)]);
        }

        return $this->webhook_secret;
    }

    public function isRepositoryPaused(string $repoFullName): bool
    {
        return in_array($repoFullName, $this->paused_repositories ?? [], true);
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

    public function components(): HasMany
    {
        return $this->hasMany(Component::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
