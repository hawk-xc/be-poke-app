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
            foreach (['face_token', 'faceset_token', 'class', 'sequence', 'embedding_id', 'passerby_uid', 'passerby_group_id', 'frame_sequence'] as $column) {
                if (Schema::hasColumn('visitor_detections', $column)) {
                    $table->dropColumn($column);
                }
            }
            $table->string('person_uid')->nullable(true)->after('label')->change();
            $table->decimal('person_pic_quality')->nullable(true)->change();
            $table->boolean('is_duplicate')->default(false)->adter('person_pic_quality');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_detections', function (Blueprint $table) {
            $table->string('face_token')->nullable(true);
            $table->string('faceset_token')->nullable(true);
            $table->string('class')->nullable(true);
            $table->integer('sequence')->nullable(true);
            $table->string('embedding_id')->nullable(true);
            $table->string('passerby_uid')->nullable(true);
            $table->string('passerby_group_id')->nullable(true);
            $table->integer('frame_sequence')->nullable(true);
            $table->string('person_uid')->nullable(true)->change();
            $table->integer('person_pic_quality')->nullable(true)->change();
            $table->dropColumn('is_duplicate');
        });
    }
};
