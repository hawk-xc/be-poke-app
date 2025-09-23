<?php

use Illuminate\Support\Facades\Auth;
use App\Models\UserLog;
use App\Models\SystemLog;

if (!function_exists('user_log')) {
    function user_log($action, $user_id = null, $email = null, $type = 'auth', $message = null) 
    {
        return UserLog::create([
            'user_id' => $user_id,
            'email'   => $email,
            'type'    => $type,
            'action'  => $action,
            'message' => $message,
        ]);
    }
}

if (!function_exists('system_log')) {
    function system_log($type = 'info', $message = null) 
    {
        SystemLog::create([
            'type' => $type,
            'message' => $message
        ]);
    }
}