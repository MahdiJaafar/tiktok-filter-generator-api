<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['summer', 'warm', 'retro', 'film', 'neon', 'dark', 'funny', 'cinematic', 'vintage', 'colorful'] as $name) {
            Tag::firstOrCreate(['slug' => $name], ['name' => $name]);
        }
    }
}
