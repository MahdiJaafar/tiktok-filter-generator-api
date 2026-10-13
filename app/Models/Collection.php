<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'name', 'description'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function filters(): BelongsToMany
    {
        return $this->belongsToMany(Filter::class, 'collection_filter')
            ->withTimestamps('created_at', false);
    }
}
