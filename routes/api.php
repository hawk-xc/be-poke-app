<?php

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Role\RoleController;
use App\Http\Controllers\Permission\PermissionController;
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

Route::group(
    [
        'middleware' => 'api',
    ],
    function () {
        /**
         * Authentication Module
         */
        Route::group(['prefix' => 'auth'], function () {
            Route::post('login', [AuthController::class, 'login']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
            Route::get('me', [AuthController::class, 'me']);
            Route::put('me', [AuthController::class, 'update']);
            Route::put('new-password', [AuthController::class, 'newPassword'])->name('users.new-password');
            Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->name('users.forgot-password');
            Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('users.reset-password');
        });

        // Dashboard Statictic API
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // User API
        Route::group(['prefix' => 'users'], function () {
            Route::apiResource('/', UserController::class)->parameters(['' => 'user']);
            Route::post('{id}/assign-role', [UserController::class, 'assignRole'])->name('users.assign-role');
            Route::post('{id}/revoke-role', [UserController::class, 'revokeRole'])->name('users.revoke-role');
            Route::put('{id}/activate-user', [UserController::class, 'activateUser'])->name('users.activate-user');
            Route::put('{id}/deactivate-user', [UserController::class, 'deactivateUser'])->name('users.deactivate-user');
            Route::get('{id}/get-user-password', [UserController::class, 'getUserPassword'])->name('users.get-user-password');
            Route::get('/action/get-status', [UserController::class, 'getStatus'])->name('users.get-status');
        });

        // Role API
        Route::group(['prefix' => 'roles'], function () {
            Route::apiResource('', RoleController::class)->parameters(['' => 'roles']);
            Route::get('{id}/without-permissions', [RoleController::class, 'showWithoutPermissions'])->name('roles.without-permissions');
            Route::post('{id}/assign-permissions', [RoleController::class, 'assignPermissions'])->name('roles.assign-permissions');
        });

        // Permission API
        Route::group(['prefix' => 'permissions'], function() {
            Route::get('', [PermissionController::class, 'index']);
            Route::get('{id}', [PermissionController::class, 'show']);
        });

        // Visitor API
        Route::group(['prefix' => 'visitors'], function () { {
            }
            Route::apiResource('', VisitorDetectionController::class)->parameters(['' => 'visitors']);
            Route::post('{id}/restore', [VisitorDetectionController::class, 'restore'])->name('visitors.restore');
            Route::get('{id}/get-match', [VisitorDetectionController::class, 'getMatch'])->name('visitors.get-match');
            Route::post('{id}/revert', [VisitorDetectionController::class, 'revert'])->name('visitors.revert');
            Route::post('{id}/revert-matched', [VisitorDetectionController::class, 'revertMatchedData'])->name('visitors.revert-matched');
            Route::delete('{id}/force-delete', [VisitorDetectionController::class, 'forceDelete'])->name('visitors.force-delete');
            // action route
            Route::group(['prefix' => 'action'], function () {
                Route::get('get-report', [VisitorDetectionController::class, 'getReport'])->name('visitors.get-report');
                Route::get('get-queues', [VisitorDetectionController::class, 'getQueues'])->name('visitors.get-queues');
                Route::get('get-match-data', [VisitorDetectionController::class, 'getMatchedData'])->name('visitors.get-match-data');
                Route::get('get-statistic', [VisitorDetectionController::class, 'getStatisticData'])->name('visitors.get-statistic-data');
                Route::delete('delete-bulk', [VisitorDetectionController::class, 'deleteBulk'])->name('visitors.delete-bulk');
            });
        });

        // Sidebar menu
        Route::get('get-sidebar-menu', [DashboardController::class, 'sidebar'])->name('dashboard.get-sidebar');

        Route::post('upload-content', function(Request $request) {
            $request->validate([
                'name' => 'required|string',
                'content' => 'required|file|max:2048'
            ]);

            $file = $request->file('content');
            $fileExtension = $file->getClientOriginalExtension();

            $fileName = time() . Str::slug($request->name) . '.' . $fileExtension;

            try {
                $fileMove = Storage::disk('minio')->putFileAs('content', $file, $fileName);
                $fileUrl = Storage::disk('minio')->url($fileMove);

                return json_encode([
                    'status' => true,
                    'message' => 'file successfully uploaded!',
                    'file_url' => $fileUrl
                ]);
            } catch (Exception $err) {
                return json_encode([
                    'status' => false,
                    'message' => 'Err uploaded file : ' . $err->getMessage()
                ]);
            }
        });
    },
);
