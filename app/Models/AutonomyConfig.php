<?php

namespace App\Models;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutonomyConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'scope_id',
        'stage',
        'level',
    ];

    protected function casts(): array
    {
        return [
            'scope' => AutonomyScope::class,
            'stage' => StageName::class,
            'level' => AutonomyLevel::class,
        ];
    }
}
