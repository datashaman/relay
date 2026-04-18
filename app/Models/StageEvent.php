<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stage_id
 * @property string $type
 * @property string $actor
 * @property array<string, mixed>|null $payload
 * @property-read Stage $stage
 */
class StageEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'stage_id',
        'type',
        'actor',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }
}
