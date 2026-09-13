<?php

declare(strict_types=1);

use App\Random\Entropy\Sources\AnuQrngSource;
use App\Random\Entropy\Sources\AtmosphereSource;
use App\Random\Entropy\Sources\BitcoinSource;
use App\Random\Entropy\Sources\CsprngSource;
use App\Random\Entropy\Sources\DrandSource;
use App\Random\Entropy\Sources\IssSource;
use App\Random\Entropy\Sources\NistBeaconSource;
use App\Random\Entropy\Sources\RandomOrgSource;
use App\Random\Entropy\Sources\SeismicSource;
use App\Random\Entropy\Sources\SpaceWeatherSource;
use App\Random\Generators\Audio\ChordGenerator;
use App\Random\Generators\Audio\MelodyGenerator;
use App\Random\Generators\Audio\NoiseGenerator;
use App\Random\Generators\Audio\PluckGenerator;
use App\Random\Generators\Audio\RhythmGenerator;
use App\Random\Generators\Equations\ArithmeticGenerator;
use App\Random\Generators\Equations\CalculusGenerator;
use App\Random\Generators\Equations\ExpressionGenerator;
use App\Random\Generators\Equations\IdentityGenerator;
use App\Random\Generators\Equations\LinearGenerator;
use App\Random\Generators\Equations\MatrixGenerator;
use App\Random\Generators\Equations\QuadraticGenerator;
use App\Random\Generators\Equations\SequenceGenerator;
use App\Random\Generators\Equations\SystemGenerator;
use App\Random\Generators\Images\BlobGenerator;
use App\Random\Generators\Images\FlowFieldGenerator;
use App\Random\Generators\Images\IdenticonGenerator;
use App\Random\Generators\Numbers\CoordinatesGenerator;
use App\Random\Generators\Numbers\DiceGenerator;
use App\Random\Generators\Numbers\GaussianGenerator;
use App\Random\Generators\Numbers\IntegersGenerator;
use App\Random\Generators\Numbers\LotteryGenerator;
use App\Random\Generators\Numbers\PasswordGenerator;
use App\Random\Generators\Numbers\UuidGenerator;
use App\Random\Generators\Patterns\AutomatonGenerator;
use App\Random\Generators\Patterns\LsystemGenerator;
use App\Random\Generators\Patterns\MazeGenerator;
use App\Random\Generators\Patterns\PerlinGenerator;
use App\Random\Generators\Patterns\PoissonGenerator;
use App\Random\Generators\Patterns\ReactionGenerator;
use App\Random\Generators\Patterns\TruchetGenerator;
use App\Random\Generators\Patterns\VoronoiGenerator;
use App\Random\Generators\Patterns\WorleyGenerator;
use App\Random\Generators\Words\ArticleGenerator;
use App\Random\Generators\Words\BrandGenerator;
use App\Random\Generators\Words\LoremGenerator;
use App\Random\Generators\Words\PassphraseGenerator;
use App\Random\Generators\Words\PseudoWordsGenerator;
use App\Random\Generators\Words\SpeciesGenerator;
use App\Random\Generators\Words\SyllabicGenerator;

return [

    'entropy' => [

        /*
         * Every entropy source the pool knows about, in the order the UI shows them.
         *
         * Listed explicitly rather than auto-discovered: it is one line per source,
         * it is greppable, and the array order is the display order on /entropy —
         * the CSPRNG first because it is the floor everything else rests on.
         */
        'sources' => [
            // Ordered by how much they can be trusted, not by how good the story
            // is: Class A first, so /entropy reads top to bottom as strongest to
            // most decorative.
            CsprngSource::class,
            AnuQrngSource::class,
            RandomOrgSource::class,
            NistBeaconSource::class,
            DrandSource::class,
            BitcoinSource::class,
            SeismicSource::class,
            SpaceWeatherSource::class,
            AtmosphereSource::class,
            IssSource::class,
        ],

        /*
         * Which source to draw from when the caller does not ask for one.
         *
         * 'auto' rotates through the healthy external sources rather than pinning
         * the fastest, because variety in the receipts is part of the product.
         * Any source key here pins that source instead; if it is down the pool
         * still degrades to the CSPRNG and says so in the receipt.
         */
        'default_source' => env('RANDOMLY_DEFAULT_SOURCE', 'auto'),

    ],

    /*
     * The catalogue.
     *
     * Same reasoning as the source list: explicit beats filesystem scanning. One
     * line per generator, greppable, and this array's order is the order the
     * library page shows them in. Adding a generator is a class plus a line here —
     * no route, no controller, no view, because the studio panel, the API
     * validation and the catalogue entry are all built from the generator's own
     * schema().
     */
    'generators' => [
        IntegersGenerator::class,
        DiceGenerator::class,
        GaussianGenerator::class,
        CoordinatesGenerator::class,
        LotteryGenerator::class,
        UuidGenerator::class,
        PasswordGenerator::class,
        PassphraseGenerator::class,
        PseudoWordsGenerator::class,
        SyllabicGenerator::class,
        BrandGenerator::class,
        LoremGenerator::class,
        ArticleGenerator::class,
        SpeciesGenerator::class,
        ArithmeticGenerator::class,
        ExpressionGenerator::class,
        LinearGenerator::class,
        QuadraticGenerator::class,
        SystemGenerator::class,
        CalculusGenerator::class,
        MatrixGenerator::class,
        IdentityGenerator::class,
        SequenceGenerator::class,
        PerlinGenerator::class,
        WorleyGenerator::class,
        AutomatonGenerator::class,
        ReactionGenerator::class,
        TruchetGenerator::class,
        PoissonGenerator::class,
        VoronoiGenerator::class,
        MazeGenerator::class,
        LsystemGenerator::class,
        FlowFieldGenerator::class,
        BlobGenerator::class,
        IdenticonGenerator::class,
        NoiseGenerator::class,
        RhythmGenerator::class,
        MelodyGenerator::class,
        PluckGenerator::class,
        ChordGenerator::class,
    ],

];
