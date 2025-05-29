<?php

use App\Http\Controllers\Api\ProcessAdScriptResultController;
use App\Http\Controllers\Api\StoreAdScriptTaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['auth:sanctum', 'throttle:api'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/ad-scripts', StoreAdScriptTaskController::class)
    ->middleware(['throttle:ad-script-submission', 'throttle:ad-script-submission-hourly']);

Route::post('/ad-scripts/{task}/result', ProcessAdScriptResultController::class)
    ->middleware(['webhook.signature', 'throttle:result-processing', 'throttle:result-processing-hourly']);

// Simple health check endpoint for our development setup script
Route::get('/health-check', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
});
