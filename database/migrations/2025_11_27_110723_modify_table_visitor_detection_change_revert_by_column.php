<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->string('revert_by')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->string('revert_by')->default('human')->nullable(false)->change();
        });
    }
};

