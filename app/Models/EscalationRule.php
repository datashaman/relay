<?php

namespace App\Models;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EscalationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'condition',
        'target_level',
        'scope',
        'order',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'target_level' => AutonomyLevel::class,
            'scope' => AutonomyScope::class,
            'order' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
