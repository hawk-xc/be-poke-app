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
            $table->string('event_type')->nullable()->change();
            $table->integer('rec_no')->nullable(true)->after('id');
            $table->tinyInteger('channel')->nullable(true)->after('rec_no');
            $table->boolean('status')->default(false)->after('similarity');
            $table->string('embedding_id')->nullable(true)->after('person_id');
            $table->integer('duration')->default(0)->nullable(true)->after('embedding_id')->description('duration in minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->dropColumn('rec_no');
            $table->dropColumn('channel');
            $table->dropColumn('status');
            $table->dropColumn('embedding_id');
            $table->dropColumn('duration');
        });
    }
};
