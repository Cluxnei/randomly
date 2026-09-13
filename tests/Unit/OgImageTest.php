<?php

declare(strict_types=1);

use App\Random\Og\OgCard;
use App\Random\Og\OgImage;
use App\Random\Studio\Generation;

/**
 * The share card is the one part of the site most people will ever see, because
 * it is what a link looks like in somebody else's timeline. These tests are
 * about the two ways it could fail silently: a generator whose display string is
 * an odd shape (a page of Latin, a single word, a line of Greek) taking the
 * renderer down inside a crawler's request, and a cached file getting served for
 * a card it is not.
 *
 * Framework-free, like everything else under tests/Unit — render() needs GD, a
 * font and nothing else.
 */
function ogImage(): OgImage
{
    return new OgImage(__DIR__.'/../../resources/fonts');
}

/** @return array{0:int,1:int} */
function ogSize(string $png): array
{
    $size = getimagesizefromstring($png);

    expect($size)->not->toBeFalse();

    return [$size[0], $size[1]];
}

it('renders a PNG at the size every social card reader expects', function (): void {
    $png = ogImage()->render(new OgCard(
        title: '12, 5, 28, 20, 66, 42',
        narrative: 'NIST Beacon pulse #1,939,041 — chained to every pulse before it, and signed.',
        badge: 'Class B · Public beacon',
        footerLeft: 'Integers · numbers.integers',
        footerRight: 'randomly.test/r/Z630FBSRNTX615R2GPW3',
    ));

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
    expect(ogSize($png))->toBe([OgImage::WIDTH, OgImage::HEIGHT]);
});

/*
 * Every shape a display string actually arrives in. The lorem generator returns
 * fifteen hundred characters in one paragraph; the equations generators return
 * numbered lines; the dice generator returns six numbers. One renderer draws all
 * of them, and the only thing standing between it and a 500 inside somebody's
 * link preview is that it never assumes which one it got.
 */
it('survives every shape of display string a generator produces', function (string $title): void {
    $png = ogImage()->render(new OgCard(title: $title, narrative: 'Drawn from the kernel entropy pool.'));

    expect(ogSize($png))->toBe([OgImage::WIDTH, OgImage::HEIGHT]);
})->with([
    'one word' => 'Passwords',
    'six numbers' => '6, 13, 13, 11, 14, 13',
    'numbered lines' => "1. x^2 - 8x - 9 = 0   →   x = -1  or  x = 9\n2. 4x^2 - 4x = 0   →   x = 0  or  x = 1",
    'blank lines between entries' => "Abrodictyum clathratum\n\n  Plantae › Tracheophyta\n\nPredatoroonops rocha",
    'a page of latin' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. '.str_repeat('Habitant consequat integer nec urna leo lorem semper ipsum accumsan venenatis posuere lacus mattis. ', 14),
    'one enormous unbroken token' => str_repeat('7JyYzqlewn', 60),
    'multibyte throughout' => 'It was 22.5°C in Suva · m 9, n₁ 0.60 · 192² grid · Karplus–Strong',
    'a single space' => ' ',
    'empty' => '',
]);

it('draws a palette strip without running off the card', function (): void {
    $palette = ['#0d0b2b', '#2b2a6b', '#7a4fa3', '#b86fb0', '#e79ec4', '#f9cfd8', '#fdeef0'];

    expect(ogSize(ogImage()->render(new OgCard(title: 'Clouds · 5 octaves', palette: $palette))))
        ->toBe([OgImage::WIDTH, OgImage::HEIGHT]);
});

it('ignores a palette entry that is not a colour rather than failing to draw', function (): void {
    // Palettes come from generator meta, and a generator is free to report
    // whatever it likes there. A preview is not the place to find out.
    expect(ogSize(ogImage()->render(new OgCard(title: 'Clouds', palette: ['#ff0000', 'chartreuse', '']))))
        ->toBe([OgImage::WIDTH, OgImage::HEIGHT]);
});

it('names the cached file after what is drawn on it', function (): void {
    $card = new OgCard(title: 'Same', narrative: 'Same narrative.', badge: 'Class A · Cryptographic');
    $same = new OgCard(title: 'Same', narrative: 'Same narrative.', badge: 'Class A · Cryptographic');

    expect($card->fingerprint())->toBe($same->fingerprint());

    // Every field has to move the hash, or a card would be served under a name
    // belonging to a different result — the one cache bug that would put the
    // wrong receipt under the right headline.
    expect(collect([
        new OgCard(title: 'Different', narrative: 'Same narrative.', badge: 'Class A · Cryptographic'),
        new OgCard(title: 'Same', narrative: 'Different narrative.', badge: 'Class A · Cryptographic'),
        new OgCard(title: 'Same', narrative: 'Same narrative.', badge: 'Class C · Observational'),
        new OgCard(title: 'Same', narrative: 'Same narrative.', badge: 'Class A · Cryptographic', tone: 'warn'),
        new OgCard(title: 'Same', narrative: 'Same narrative.', badge: 'Class A · Cryptographic', palette: ['#ffffff']),
        new OgCard(title: 'Same', narrative: 'Same narrative.', badge: 'Class A · Cryptographic', footerLeft: 'x'),
        new OgCard(title: 'Same', narrative: 'Same narrative.', badge: 'Class A · Cryptographic', footerRight: 'x'),
    ])->map(fn (OgCard $other): string => $other->fingerprint())->push($card->fingerprint())->unique())
        ->toHaveCount(8);
});

it('gives the same card the same bytes every time', function (): void {
    // Content-addressed caching is only safe if the content is a function of the
    // card: a renderer that reached for the clock or the RNG would serve one
    // image under a name earned by another.
    $card = new OgCard(title: 'Deterministic', narrative: 'Same in, same out.', palette: ['#123456', '#abcdef']);

    expect(hash('sha256', ogImage()->render($card)))->toBe(hash('sha256', ogImage()->render($card)));
});

/**
 * The preview card is the only part of a link most people ever see, so text on it
 * has to be ours. Carrying the receipt sentence in the URL is what makes a shared
 * card worth sharing; signing it is what stops the same mechanism from becoming a
 * forgery generator for cards bearing the Randomly wordmark.
 */
const OG_TEST_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

it('accepts a narrative it signed itself', function (): void {
    $narrative = 'A magnitude 3.6 earthquake, 67 km N of Culebra, Puerto Rico.';

    expect(Generation::verifyNarrative($narrative, Generation::signNarrative($narrative, OG_TEST_KEY), OG_TEST_KEY))
        ->toBe($narrative);
});

it('rejects a narrative that was altered in the link', function (string $tampered): void {
    $original = 'A magnitude 3.6 earthquake, 67 km N of Culebra, Puerto Rico.';
    $signature = Generation::signNarrative($original, OG_TEST_KEY);

    expect(Generation::verifyNarrative($tampered, $signature, OG_TEST_KEY))->toBeNull();
})->with([
    'substituted' => ['Randomly endorses this completely fabricated claim.'],
    'appended' => ['A magnitude 3.6 earthquake, 67 km N of Culebra, Puerto Rico. And more.'],
    'one character' => ['A magnitude 3.7 earthquake, 67 km N of Culebra, Puerto Rico.'],
    'emptied' => [''],
]);

it('rejects a narrative with no signature at all', function (): void {
    expect(Generation::verifyNarrative('Anything at all.', null, OG_TEST_KEY))->toBeNull()
        ->and(Generation::verifyNarrative('Anything at all.', '', OG_TEST_KEY))->toBeNull()
        ->and(Generation::verifyNarrative(null, 'deadbeef', OG_TEST_KEY))->toBeNull();
});

it('changes the cached filename when the narrative changes', function (): void {
    // The card is cached by fingerprint, so a swapped-in narrative that did not
    // change it would serve the old image and quietly undo the whole feature.
    $card = new OgCard(title: '20, 22, 26', narrative: 'Reconstructed.');

    expect($card->withNarrative('The original sentence.')->fingerprint())
        ->not->toBe($card->fingerprint());
});
