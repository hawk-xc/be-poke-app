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
        Schema::create('visitor_queues', function (Blueprint $table) {
            $table->id();
            $table->integer('rec_no')->nullable(false);
            $table->enum('status', ['registered', 'expire', 'pending'])->default('pending')->nullable(true);
            $table->enum('label', ['in', 'out'])->default('in')->nullable(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_queues');
    }
};
