<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pokemon_abilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pokemon_id');
            $table->unsignedBigInteger('ability_id');
            $table->timestamps();

            $table->foreign('pokemon_id')->references('id')->on('pokemons')->onDelete('cascade');
            $table->foreign('ability_id')->references('id')->on('abilities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pokemon_abilities');
    }
};
