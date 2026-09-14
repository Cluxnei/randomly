<?php

declare(strict_types=1);

use App\Http\Controllers\DocsController;
use App\Http\Controllers\OgController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\WorksheetController;
use Illuminate\Support\Facades\Route;

/*
 * The machine-readable surfaces, registered first.
 *
 * `/g/{module}/{generator}.md` has to be declared ahead of the studio route or
 * the studio would match it and go looking for a generator called "perlin.md".
 *
 * All four documents are rendered from the live registry and the live entropy
 * pool, so a generator added to config/randomly.php documents itself on the next
 * request. Nothing here is hand-kept, because a hand-kept copy of a parameter
 * list is a copy that will be wrong by the end of the month — and a document that
 * lies about an API is worse than no document at all, since the reader has no way
 * to tell.
 */
Route::get('/llms.txt', [DocsController::class, 'index'])->name('llms');
Route::get('/llms-full.txt', [DocsController::class, 'full'])->name('llms.full');
Route::get('/api.md', [DocsController::class, 'api'])->name('docs.api');
Route::get('/g/{module}/{generator}.md', [DocsController::class, 'generator'])->name('docs.generator');

/*
 * robots.txt and sitemap.xml are routes rather than files in public/ because both
 * have to name absolute URLs and this project has no hostname baked into it. A
 * checked-in robots.txt would hardcode a domain that is wrong on every deployment
 * but one, and a hand-written sitemap would stop listing generators the moment
 * somebody added one.
 */
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

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
