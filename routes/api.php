<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApiController;
use Illuminate\Support\Facades\Route;

/*
 * No key, no signup, no account — matching every source we draw from. Being
 * curl-able in one line is a feature of the product, not a convenience.
 */
Route::middleware('throttle:randomly')->prefix('v1')->group(function (): void {
    Route::get('/generators', [ApiController::class, 'generators'])->name('api.generators');
    Route::get('/sources', [ApiController::class, 'sources'])->name('api.sources');

    Route::post('/generate', [ApiController::class, 'generate'])->name('api.generate');

    // The GET shorthand exists purely so the documented one-liner works.
    Route::get('/g/{key}', [ApiController::class, 'generate'])->name('api.generate.get');

    Route::get('/replay/{token}', [ApiController::class, 'replay'])->name('api.replay');
});
