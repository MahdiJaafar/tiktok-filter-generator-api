<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Vintage', 'Cinematic', 'Beauty', 'Funny', 'Gaming', 'Retro', 'Artistic'] as $name) {
            Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}
