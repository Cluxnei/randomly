<?php

declare(strict_types=1);

namespace App\Random\Media;

/**
 * Raised when a requested representation cannot be produced.
 *
 * Always answerable with an honest response — the caller asked for a PNG of a
 * word generator, or Node is not installed on this host — never a 500 that leaves
 * them guessing which.
 */
final class MediaUnavailable extends \RuntimeException {}
