<?php

declare(strict_types=1);

/**
 * The shipped skill describes the API to an agent that has never seen this repo.
 *
 * It is documentation that lives outside the code, which is exactly the kind that
 * rots — so the parts that can be checked against the code are checked. A skill
 * confidently describing a generator that no longer exists is worse than none.
 */
function skillText(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/.claude/skills/randomly-api/SKILL.md');
}

it('carries the frontmatter a skill needs to be loadable', function (): void {
    $text = skillText();

    expect($text)->toStartWith('---');

    preg_match('/^---\n(.*?)\n---/s', $text, $matches);

    expect($matches[1] ?? '')->toContain('name: randomly-api')
        ->and($matches[1])->toContain('description:');

    // The description is what a model matches against when deciding whether the
    // skill applies, so an empty or generic one makes the skill invisible.
    preg_match('/description: (.+)/', $matches[1], $description);
    expect(strlen(trim($description[1] ?? '')))->toBeGreaterThan(80);
});

it('names only modules that exist', function (): void {
    $text = skillText();

    foreach (['numbers', 'words', 'equations', 'patterns', 'images', 'audio'] as $module) {
        expect($text)->toContain($module);
    }
});

it('names only generators that are actually registered', function (): void {
    $registered = array_map(
        fn (string $class): string => (new $class)->key(),
        (require dirname(__DIR__, 2).'/config/randomly.php')['generators'],
    );

    // Any `module.generator` token in the skill has to resolve. This is the check
    // that catches a rename or a removal silently orphaning the documentation.
    preg_match_all('/\b(numbers|words|equations|patterns|images|audio)\.[a-z]+\b/', skillText(), $mentions);

    foreach (array_unique($mentions[0]) as $key) {
        expect($key)->toBeIn($registered);
    }
});

it('describes the entropy classes the pool actually implements', function (): void {
    $text = skillText();

    foreach (['csprng', 'anu-qrng', 'random-org', 'nist-beacon', 'drand', 'bitcoin', 'seismic'] as $source) {
        expect($text)->toContain($source);
    }

    // The honesty rules are the part most worth carrying into someone else's
    // context: an agent that reads this must not reach for Class C for a secret.
    expect($text)->toContain('mixed_with_csprng')
        ->and($text)->toContain('sensitive')
        ->and($text)->toContain('reproducible');
});

it('states the rate limits the server enforces', function (): void {
    $limits = (require dirname(__DIR__, 2).'/config/randomly.php')['limits'];

    expect(skillText())
        ->toContain((string) $limits['per_minute'])
        ->toContain((string) $limits['media_per_minute']);
});
