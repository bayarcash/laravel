<?php

use Bayarcash\Laravel\Http\Controllers\CallbackController;
use Bayarcash\Laravel\Http\Controllers\ReturnController;
use Bayarcash\Laravel\Http\Middleware\VerifyBayarcashSignature;
use Illuminate\Support\Facades\Route;

if (config('bayarcash.callback.enabled', true)) {
    Route::post(config('bayarcash.callback.path', 'bayarcash/callback'), CallbackController::class)
        ->middleware(array_merge(
            (array) config('bayarcash.callback.middleware', []),
            [VerifyBayarcashSignature::class]
        ))
        ->name('bayarcash.callback');
}

if (config('bayarcash.return.enabled', true)) {
    Route::get(config('bayarcash.return.path', 'bayarcash/return'), ReturnController::class)
        ->name('bayarcash.return');
}
