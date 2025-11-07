<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\User\UserController;
use App\Http\Controllers\Visitor\VisitorDetectionController;

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

Route::options('{any}', function () {
    return response()->json([], 200);
})->where('any', '.*');

Route::group([
    'middleware' => 'api'
], function () {
    
    /**
     * Authentication Module
     */
    Route::group(['prefix' => 'auth'], function() {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('new-password', [AuthController::class, 'newPassword'])->name('users.new-password');
        Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->name('users.forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('users.reset-password');
    });

    // Dashboard Statictic API
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // User API
    Route::apiResource('users', UserController::class);
    Route::post('users/{id}/assign-role', [UserController::class, 'assignRole'])->name('users.assign-role');

    // Role API
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'store']);
    Route::get('roles/{id}/without-permissions', [RoleController::class, 'showWithoutPermissions'])->name('roles.without-permissions');
    Route::post('roles/{id}/assign-permissions', [RoleController::class, 'assignPermissions'])->name('roles.assign-permissions');

    // Visitor API
    Route::apiResource('visitors', VisitorDetectionController::class);
    Route::post('visitors/{id}/restore', [VisitorDetectionController::class, 'restore'])->name('visitors.restore');
    Route::delete('visitors/{id}/force-delete', [VisitorDetectionController::class, 'forceDelete'])->name('visitors.force-delete');
    Route::get('visitors/get-report', [VisitorDetectionController::class, 'getReport'])->name('visitors.get-report');
});