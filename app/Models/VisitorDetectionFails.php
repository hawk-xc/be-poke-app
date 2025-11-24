<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VisitorDetectionFails extends Model
{
    use HasFactory;

    protected $table = 'visitor_detection_fails';

    protected $fillabel = [
        'rec_no',
        'status',
        'try_count'
    ];
}
