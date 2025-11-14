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
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->dropColumn('code');
            $table->string('faceset_token')->nullable(true)->after('face_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->string('code')->nullable(true)->after('face_token');
            $table->dropColumn('faceset_token');
        });
    }
};
