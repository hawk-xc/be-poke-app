<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VisitorQueue extends Model
{
    use HasFactory;

    protected $table = 'visitor_queues';

    protected $fillable = [
        'rec_no',
        'status',
        'label'
    ];
}
