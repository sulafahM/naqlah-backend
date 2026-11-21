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
