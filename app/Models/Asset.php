<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
        'width',
        'height',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function filterLayers(): HasMany
    {
        return $this->hasMany(FilterLayer::class);
    }
}
