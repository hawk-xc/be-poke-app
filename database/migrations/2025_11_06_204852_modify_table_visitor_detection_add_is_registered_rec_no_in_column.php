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
            if (Schema::hasColumn('visitor_detections', 'is_registered')) {
                return;
            }

            if (Schema::hasColumn('visitor_detections', 'is_matched')) {
                return;
            }

            $table->boolean('is_registered')->default(false)->after('label');
            $table->boolean('is_matched')->default(false)->after('is_registered');
            $table->string('rec_no_in')->nullable(true)->after('rec_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->dropColumn('is_registered');
            $table->dropColumn('is_matched');
            $table->dropColumn('rec_no_in');
        });
    }
};
