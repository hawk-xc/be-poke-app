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
        Schema::create('visitor_detections', function (Blueprint $table) {
            $table->id();

            // General Metadata
            $table->string('code')->nullable();          // ex: FaceRecognition
            $table->string('action')->nullable();        // ex: Appear, Stop
            $table->string('class')->nullable();         // ex: FaceAnalysis
            $table->integer('event_type')->nullable();   // ex: 1
            $table->string('name')->nullable();          // ex: FaceAnalysis
            $table->boolean('is_global_scene')->nullable();
            $table->string('locale_time')->nullable();   // ex: 2025-09-21 11:22:29
            $table->bigInteger('utc')->nullable();
            $table->bigInteger('real_utc')->nullable();
            $table->bigInteger('sequence')->nullable();

            // Face Data (FaceDetection)
            $table->integer('face_age')->nullable();
            $table->string('face_sex')->nullable();          // Man, Woman
            $table->integer('face_quality')->nullable();
            $table->json('face_angle')->nullable();          // [ -30, 40, 0 ]
            $table->json('face_bounding_box')->nullable();   // [ x1, y1, x2, y2 ]
            $table->json('face_center')->nullable();         // [ cx, cy ]
            $table->json('face_feature')->nullable();        // [ "NoGlasses", "Neutral" ]
            $table->integer('face_object_id')->nullable();

            // Additional Object
            $table->string('object_action')->nullable();     // Appear, Disappear
            $table->json('object_bounding_box')->nullable();
            $table->integer('object_age')->nullable();
            $table->string('object_sex')->nullable();
            $table->integer('frame_sequence')->nullable();
            $table->string('emotion')->nullable();           // Neutral, Happy, etc

            // Passerby
            $table->string('passerby_group_id')->nullable();
            $table->string('passerby_uid')->nullable();

            // Data FaceRecognition (Candidates) opsional
            $table->bigInteger('person_id')->nullable();
            $table->string('person_uid')->nullable();
            $table->string('person_name')->nullable();
            $table->string('person_sex')->nullable();
            $table->string('person_group_name')->nullable();
            $table->string('person_group_type')->nullable();
            $table->string('person_pic_url')->nullable();
            $table->integer('person_pic_quality')->nullable();
            $table->integer('similarity')->nullable();

            $table->date('deleted_at')->nullable(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_detections');
    }
};
