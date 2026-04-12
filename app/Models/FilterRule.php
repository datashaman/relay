<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'include_labels',
        'exclude_labels',
        'unassigned_only',
        'auto_accept_labels',
    ];

    protected function casts(): array
    {
        return [
            'include_labels' => 'array',
            'exclude_labels' => 'array',
            'unassigned_only' => 'boolean',
            'auto_accept_labels' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
