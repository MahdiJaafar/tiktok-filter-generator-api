<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterLayer extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'filter_id',
        'asset_id',
        'type',
        'layer_order',
        'properties',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function filter(): BelongsTo
    {
        return $this->belongsTo(Filter::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
