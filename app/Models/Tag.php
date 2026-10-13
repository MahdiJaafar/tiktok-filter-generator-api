<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'slug'];

    public function filters(): BelongsToMany
    {
        return $this->belongsToMany(Filter::class, 'filter_tag');
    }
}
