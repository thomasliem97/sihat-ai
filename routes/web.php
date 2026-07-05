<?php

use App\Http\Controllers\AiFileController;
use App\Http\Controllers\AiWebhookController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\Patient\DashboardController as PatientDashboardController;
use App\Http\Controllers\Physician\DashboardController as PhysicianDashboardController;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('/api/ai/webhook', AiWebhookController::class)->name('ai.webhook');
Route::get('/api/ai/files/{record}', AiFileController::class)
    ->middleware('signed')
    ->name('ai.file');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        $user = auth()->user();

        if ($user->isPhysician()) {
            return redirect()->route('physician.dashboard');
        }

        return redirect()->route('patient.dashboard');
    })->name('dashboard');

    Route::middleware(EnsureUserRole::class.':physician')->prefix('physician')->name('physician.')->group(function () {
        Route::get('dashboard', PhysicianDashboardController::class)->name('dashboard');
    });

    Route::middleware(EnsureUserRole::class.':patient')->prefix('patient')->name('patient.')->group(function () {
        Route::get('dashboard', PatientDashboardController::class)->name('dashboard');
    });

    Route::prefix('records')->name('records.')->group(function () {
        Route::get('/', [MedicalRecordController::class, 'index'])->name('index');
        Route::get('create', [MedicalRecordController::class, 'create'])->name('create');
        Route::post('/', [MedicalRecordController::class, 'store'])->name('store');
        Route::get('{record}', [MedicalRecordController::class, 'show'])->name('show');
        Route::patch('{record}/report', [MedicalRecordController::class, 'updateReport'])->name('report.update');
        Route::post('{record}/sign', [MedicalRecordController::class, 'sign'])->name('sign');
        Route::get('{record}/file', [MedicalRecordController::class, 'file'])->name('file');
    });
});

require __DIR__.'/settings.php';
