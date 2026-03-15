<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authentication
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Divisions
    Route::get('/divisions', [DivisionController::class, 'index']);
    Route::get('/divisions/{division}', [DivisionController::class, 'show']);

    // Projects
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{id}', [ProjectController::class, 'show']);
    Route::get('/projects/{id}/feedback', [FeedbackController::class, 'indexByProject']);
    Route::post('/projects/{id}/summarize', [AnalysisController::class, 'summarizeProject']);

    // Feedback
    Route::get('/feedback', [FeedbackController::class, 'index']);
    Route::post('/feedback', [FeedbackController::class, 'store']);
    Route::get('/feedback/{feedback}', [FeedbackController::class, 'show']);
    Route::patch('/feedback/{feedback}/status', [FeedbackController::class, 'updateStatus']);
    Route::delete('/feedback/{feedback}', [FeedbackController::class, 'destroy']);

    // AI Analysis
    Route::post('/analysis/query', [AnalysisController::class, 'query']);

    // Metrics
    Route::get('/metrics', [MetricsController::class, 'index']);
});
