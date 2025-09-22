<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLog extends Model
{
    protected $table = 'user_logs';

    protected $fillable = [
        'email',
        'user_id',
        'type',
        'action',
        'state',
        'message'
    ];

    // ORM
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
