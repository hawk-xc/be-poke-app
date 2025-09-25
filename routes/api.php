<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Products\ProductsController;
use App\Http\Controllers\Visitor\VisitorController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Role\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group([
    'middleware' => 'api'
], function ($router) {
    
    /**
     * Authentication Module
     */
    Route::group(['prefix' => 'auth'], function() {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
    });

    // User API
    Route::apiResource('users', UserController::class);    
    Route::post('users/{id}/assign-role', [UserController::class, 'assignRole'])->name('users.assign-role');

    // Role API
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'store']);
    Route::post('roles/{id}/assign-permission', [RoleController::class, 'assignPermission'])->name('roles.assign-permission');

    // Permission API

    // Visitor API
    Route::apiResource('visitors', VisitorController::class);
    Route::post('visitors/{id}/restore', [VisitorController::class, 'restore'])->name('visitors.restore');
    Route::delete('visitors/{id}/force-delete', [VisitorController::class, 'forceDelete'])->name('visitors.forceDelete');
});