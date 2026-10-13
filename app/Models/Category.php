<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'slug'];

    public function filters(): HasMany
    {
        return $this->hasMany(Filter::class);
    }
}
