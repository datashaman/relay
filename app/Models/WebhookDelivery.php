<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $source_id
 * @property string $external_delivery_id
 * @property string|null $event_type
 * @property string|null $action
 * @property array<string, mixed>|null $payload
 * @property string|null $error
 * @property Carbon|null $processed_at
 * @property bool $wasRecentlyCreated
 * @property-read Source|null $source
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'external_delivery_id',
        'event_type',
        'action',
        'payload',
        'processed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
