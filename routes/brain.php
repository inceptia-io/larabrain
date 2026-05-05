<?php

declare(strict_types=1);

use Arafat\Brain\Http\Controllers\BrainUIController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BrainUIController::class, 'index'])->name('brain.index');
Route::post('/ask', [BrainUIController::class, 'ask'])->name('brain.ask');
