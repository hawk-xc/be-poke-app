<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->enum('revert_by', ['human', 'system'])
                ->nullable()
                ->default(null)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->enum('revert_by', ['human', 'system'])
                ->default('human')
                ->nullable(false)
                ->change();
        });
    }
};

