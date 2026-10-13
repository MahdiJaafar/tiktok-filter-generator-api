<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Filter extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'category_id',
        'parent_filter_id',
        'name',
        'description',
        'status',
        'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function layers(): HasMany
    {
        return $this->hasMany(FilterLayer::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'filter_tag');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_filter')
            ->withTimestamps('created_at', false);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_filter_id');
    }

    public function remixes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_filter_id');
    }
}
