<?php

use App\Http\Controllers\ApiSessionController;
use App\Http\Controllers\SkillExtractionController;
use App\Http\Controllers\StudentSkillController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('session', [ApiSessionController::class, 'show']);
    Route::post('session', [ApiSessionController::class, 'store'])->middleware('throttle:10,1');

    Route::middleware('auth:web')->group(function () {
        Route::delete('session', [ApiSessionController::class, 'destroy']);

        Route::prefix('skill-extraction')->group(function () {
            Route::post('cv', [SkillExtractionController::class, 'cv']);
            Route::post('practical-work', [SkillExtractionController::class, 'practicalWork']);
            Route::post('course', [SkillExtractionController::class, 'course']);
            Route::get('{source}/{sourceId}', [SkillExtractionController::class, 'show'])->whereNumber('sourceId');
        });

        Route::prefix('student-skills')->group(function () {
            Route::get('/', [StudentSkillController::class, 'index']);
            Route::get('candidates', [StudentSkillController::class, 'candidates']);
            Route::get('confirmed', [StudentSkillController::class, 'confirmed']);
            Route::post('candidates/{candidateId}/confirm', [StudentSkillController::class, 'confirm'])->whereNumber('candidateId');
            Route::post('candidates/{candidateId}/reject', [StudentSkillController::class, 'reject'])->whereNumber('candidateId');
        });
    });
});
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected authentication routes
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        return response()->json([
            'success' => true,
            'user' => $request->user(),
        ]);
    });
Route::get('/profile', [ProfileController::class, 'show']);
Route::patch('/profile', [ProfileController::class, 'update']);

// Projects API
Route::post('/projects', [ProjectController::class, 'store']);
Route::get('/projects', [ProjectController::class, 'index']);
Route::get('/projects/{id}', [ProjectController::class, 'show']);
Route::patch('/projects/{id}', [ProjectController::class, 'update']);
Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);

});
