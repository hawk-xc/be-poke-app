<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
        'faceset_token',
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

        // revert_by
        'revert_by'
    ];

    public function visitorIn()
    {
        return $this->hasOne(VisitorDetection::class, 'rec_no', 'rec_no_in')
            ->where('label', 'in');
    }

    public function scopeVisitorIn(Builder $query)
    {
        return $query->where('label', 'in');
    }

    public function scopeVisitorOut($query)
    {
        return $query->where('label', 'out');
    }

    public function scopeMatched($query)
    {
        return $query->where('is_matched', true)
            ->where('is_registered', true);
    }

    public function scopeRegistered($query)
    {
        return $query->where('is_registered', true)
            ->whereNotNull('embedding_id');
    }

    public function scopeToday(Builder $query)
    {
        return $query->whereDate('locale_time', today());
    }

    public function scopeThisWeek(Builder $query)
    {
        return $query->whereBetween('locale_time', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    public function scopeThisMonth(Builder $query)
    {
        return $query->whereBetween('locale_time', [
            now()->startOfMonth(),
            now()->endOfMonth()
        ]);
    }

    public function scopeThisYear(Builder $query)
    {
        return $query->whereBetween('locale_time', [
            now()->startOfYear(),
            now()->endOfYear()
        ]);
    }
}
