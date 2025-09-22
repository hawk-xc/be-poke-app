<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visitor extends Model
{
    use SoftDeletes;

    protected $table = 'visitors';

    protected $fillable = [
        'rec_no',
        'channel',
        'start_time',
        'end_time',
        'start_time_utc',
        'end_time_utc',
        'file_path',
        'video_path',
        'length',
        'secondary_analyse_type',
        'person_uid',
        'person_group',
        'similarity',
        'person_image',
        'age',
        'sex',
        'mask',
        'glasses',
        'beard',
        'emotion',
        'attractive',
        'mouth',
        'eye',
        'strabismus',
        'nation',
        'object_image',
        'image_info_path',
        'image_length',
        'center',
        'machine_address',
        'task_id',
        'task_name',
        'deleted_at'
    ];
}
