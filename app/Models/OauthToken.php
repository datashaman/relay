<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'provider',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
