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
        Schema::create('visitor_detection_fails', function (Blueprint $table) {
            $table->id();
            $table->string('rec_no')->nullable(false)->unique();
            $table->string('status')->nullable(true);
            $table->integer('try_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_detection_fails');
    }
};
