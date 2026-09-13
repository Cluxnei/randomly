<?php

declare(strict_types=1);

use App\Http\Controllers\OgController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\WorksheetController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'landing'])->name('home');
Route::get('/library', [PageController::class, 'library'])->name('library');
Route::get('/entropy', [PageController::class, 'entropy'])->name('entropy');
Route::get('/credits', [PageController::class, 'credits'])->name('credits');

/*
 * Share cards, drawn with GD and cached under a hash of what is on them.
 *
 * Same trick as the permalink: the card for a result is recomputed from the URL
 * rather than stored, so a crawler that arrives long after the share still gets
 * the right image and there is still no database.
 */
Route::get('/og/site.png', [OgController::class, 'site'])->name('og.site');
Route::get('/og/r/{token}.png', [OgController::class, 'result'])->name('og.result')->where('token', '[0-9A-Za-z]+');
Route::get('/og/g/{module}/{generator}.png', [OgController::class, 'generator'])->name('og.generator');

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
