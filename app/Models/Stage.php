<?php

namespace App\Models;

use App\Enums\StageName;
use App\Enums\StageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'name',
        'status',
        'iteration',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'name' => StageName::class,
            'status' => StageStatus::class,
            'iteration' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(StageEvent::class);
    }
}
