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
            $table->integer('glasses')->nullable(true)->after('sequence');
            $table->integer('mustache')->nullable(true)->after('glasses');
            $table->dateTime('out_locale_time')->nullable(true)->after('locale_time');
            $table->string('gate_name')->nullable(true)->after('mustache');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->dropColumn('glasses');
            $table->dropColumn('mustache');
            $table->dropColumn('out_locale_time');
            $table->dropColumn('gate_name');
        });
    }
};
