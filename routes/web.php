<?php

declare(strict_types=1);

use App\Http\Controllers\PageController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\WorksheetController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'landing'])->name('home');
Route::get('/library', [PageController::class, 'library'])->name('library');
Route::get('/entropy', [PageController::class, 'entropy'])->name('entropy');

/*
 * The studio. There is one route for every generator in the registry rather than
 * a page per generator, because the control panel is rendered from the
 * generator's declared schema.
 */
Route::get('/g/{module}/{generator}', [StudioController::class, 'show'])->name('studio');

/*
 * A permalink. The token is the seed, so this recomputes rather than looks up —
 * which is why the project needs no database.
 */
Route::get('/r/{token}', [StudioController::class, 'replay'])->name('replay');

/*
 * A printable problem sheet. `?seed=` makes it reproducible, which is the whole
 * point — the same link prints the same sheet, and a different seed prints a
 * different one of the same shape.
 */
Route::get('/worksheet/{generator}', WorksheetController::class)->name('worksheet');
