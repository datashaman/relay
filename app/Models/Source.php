<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property SourceType $type
 * @property string|null $webhook_secret
 * @property bool $is_intake_paused
 * @property array<int, string>|null $paused_repositories
 * @property array<string, mixed>|null $config
 * @property string|null $bot_login
 * @property string|null $bot_account_id
 */
class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'external_account',
        'bot_login',
        'bot_account_id',
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

    /**
     * Resolve the preflight clarification channel for this source.
     * Stored under config.preflight.clarification_channel; defaults to 'in_app'.
     */
    public function clarificationChannel(): string
    {
        $channel = $this->config['preflight']['clarification_channel'] ?? 'in_app';

        return in_array($channel, ['in_app', 'on_issue'], true) ? $channel : 'in_app';
    }

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
