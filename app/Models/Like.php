<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Like extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'filter_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function filter(): BelongsTo
    {
        return $this->belongsTo(Filter::class);
    }
}
