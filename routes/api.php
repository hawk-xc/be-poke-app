<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PokemonController;
use App\Http\Controllers\Auth\AuthController;

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

        // Pokemon API
        Route::group(['prefix' => 'pokemons'], function () {
            Route::apiResource('', PokemonController::class)->parameters(['' => 'pokemons']);
        });

        // Fetch Data
        Route::get('fetch-data', [PokemonController::class, 'fetchData']);
    },
);
