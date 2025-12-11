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
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();

            // Rec Info
            $table->bigInteger('rec_no')->unique(); // RecNo unik
            $table->integer('channel')->nullable();
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();
            $table->dateTime('start_time_utc')->nullable();
            $table->dateTime('end_time_utc')->nullable();
            $table->string('file_path')->nullable();       // /picid/39010914.jpg
            $table->string('video_path')->nullable();      // exists
            $table->integer('length')->nullable();
            $table->integer('secondary_analyse_type')->nullable();

            // Person Info
            $table->bigInteger('person_uid')->nullable();   // Person.UID
            $table->string('person_group')->nullable();             // GroupName
            $table->integer('similarity')->nullable();              // Similarity %
            $table->string('person_image')->nullable();             // Person.Image[0].FilePath

            // Face Attributes (Object Info)
            $table->integer('age')->nullable();
            $table->string('sex', 10)->nullable();        // Man / Woman
            $table->boolean('mask')->nullable();          // 0 / 1
            $table->boolean('glasses')->nullable();       // 0 / 1
            $table->boolean('beard')->nullable();         // 0 / 1
            $table->string('emotion')->nullable();        // Neutral, Disgust, Calmness, dll
            $table->integer('attractive')->nullable();
            $table->integer('mouth')->nullable();
            $table->integer('eye')->nullable();
            $table->integer('strabismus')->nullable();
            $table->integer('nation')->nullable();        // kode 0,1,2...
            $table->string('object_image')->nullable();   // Object.Image.FilePath

            // Image Info
            $table->string('image_info_path')->nullable(); // ImageInfo.FilePath
            $table->bigInteger('image_length')->nullable();
            $table->json('center')->nullable();           // [x,y]

            // Machine/Task Info
            $table->integer('machine_address')->nullable();
            $table->integer('task_id')->nullable();
            $table->string('task_name')->nullable();

            // Soft Delete & Timestamp
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
