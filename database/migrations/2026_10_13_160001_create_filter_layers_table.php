<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('filter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->unsignedInteger('layer_order');
            $table->json('properties');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_layers');
    }
};
