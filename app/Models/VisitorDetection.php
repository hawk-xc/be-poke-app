<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use function PHPSTORM_META\map;

class VisitorDetection extends Model
{
    use SoftDeletes;

    protected $table = 'visitor_detections';

    protected $fillable = [
        // Machine Learning Data
        'is_registered',
        'rec_no_in',
        'is_matched',
        'face_token',

        // Basic detection data
        'code',
        'action',
        'class',
        'event_type',
        'name',
        'is_global_scene',
        'locale_time',
        'utc',
        'real_utc',
        'sequence',
        
        // Face Data (FaceDetection)
        'face_age',
        'face_sex',
        'face_quality',
        'face_angle',
        'face_bounding_box',
        'face_center',
        'face_feature',
        'face_object_id',
        'glasses',
        'mustache',
        'out_locale_time',
        'gate_name',
        
        // Additional Object
        'object_action',
        'object_bounding_box',
        'object_age',
        'object_sex',
        'frame_sequence',
        'emotion',
        
        // Passerby
        'passerby_group_id',
        'passerby_uid',
        
        // Data FaceRecognition (Candidates)
        'person_id',
        'person_uid',
        'person_name',
        'person_sex',
        'person_group_name',
        'person_group_type',
        'person_pic_url',
        'person_pic_quality',
        'similarity',
        
        // Soft delete
        'deleted_at',

        'label',
        'event_type',
        'rec_no',
        'channel',
        'status',
        'embedding_id',
        'duration',
    ];
}
