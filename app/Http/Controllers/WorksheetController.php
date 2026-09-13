<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Random\GeneratorRegistry;
use App\Random\Generators\Module;
use App\Random\Studio\Generation;
use App\Random\Studio\Studio;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A printable problem sheet for any Equations generator.
 *
 * This exists because it falls out of the seed architecture for nothing. The URL
 * carries a token, the token *is* the randomness, so the same link prints the
 * same sheet in a year's time and a link with one character changed prints a
 * different sheet of the same shape. A teacher can hand thirty students thirty
 * sheets and still have every answer key.
 *
 * The seed is printed in the footer for exactly that reason: a sheet that cannot
 * be regenerated from the paper it is on is not reproducible in any way that
 * helps the person holding it.
 */
final class WorksheetController extends Controller
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly Studio $studio,
    ) {}

    public function __invoke(string $generator, Request $request): View
    {
        $key = 'equations.'.$generator;

        if (! $this->registry->has($key) || $this->registry->findOrFail($key)->module() !== Module::Equations) {
            throw new NotFoundHttpException("No worksheet for [{$key}].");
        }

        $generation = $this->generation($key, $request);

        return view('pages.worksheet', [
            'generator' => $generation->generator,
            'generation' => $generation,
            'problems' => $generation->result->value['problems'] ?? [],
            'columns' => $request->integer('columns', 2),
        ]);
    }

    private function generation(string $key, Request $request): Generation
    {
        $input = $request->query();
        $token = (string) $request->query('seed', '');

        if ($token === '') {
            return $this->studio->generate($key, $input);
        }

        try {
            return $this->studio->replay(
                token: $token,
                generatorKey: $key,
                version: $this->registry->findOrFail($key)->version(),
                input: $input,
                sourceKey: $request->query('s'),
            );
        } catch (\InvalidArgumentException) {
            // A hand-edited or truncated token should print a sheet, not a 500.
            // The footer will carry whatever seed actually got used, so the page
            // never claims a provenance it does not have.
            return $this->studio->generate($key, $input);
        }
    }
}
