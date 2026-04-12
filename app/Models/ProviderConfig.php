<?php

namespace App\Models;

use App\Enums\StageName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'scope',
        'scope_id',
        'stage',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'stage' => StageName::class,
            'settings' => 'array',
        ];
    }
}
