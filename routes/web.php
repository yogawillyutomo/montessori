<?php

use App\Http\Controllers\AlphaController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AlphaController::class, 'dashboard'])->name('alpha.dashboard');
Route::get('/master', [AlphaController::class, 'master'])->name('alpha.master');
Route::get('/process', [AlphaController::class, 'process'])->name('alpha.process');
Route::get('/reports', [AlphaController::class, 'reports'])->name('alpha.reports');

Route::post('/sessions/from-schedule', [AlphaController::class, 'createSession'])->name('alpha.sessions.create-from-schedule');
Route::post('/observations', [AlphaController::class, 'storeObservation'])->name('alpha.observations.store');
Route::post('/reports/generate', [AlphaController::class, 'generateReports'])->name('alpha.reports.generate');
