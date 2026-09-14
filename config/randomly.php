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
use App\Random\Generators\Audio\AmbientGenerator;
use App\Random\Generators\Audio\BleepGenerator;
use App\Random\Generators\Audio\ChordGenerator;
use App\Random\Generators\Audio\DroneGenerator;
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
use App\Random\Generators\Images\CirclesGenerator;
use App\Random\Generators\Images\FlowFieldGenerator;
use App\Random\Generators\Images\GradientGenerator;
use App\Random\Generators\Images\IdenticonGenerator;
use App\Random\Generators\Images\MondrianGenerator;
use App\Random\Generators\Images\SprayGenerator;
use App\Random\Generators\Images\StrataGenerator;
use App\Random\Generators\Images\TilesGenerator;
use App\Random\Generators\Numbers\BytesGenerator;
use App\Random\Generators\Numbers\CoinGenerator;
use App\Random\Generators\Numbers\CoordinatesGenerator;
use App\Random\Generators\Numbers\DecimalsGenerator;
use App\Random\Generators\Numbers\DiceGenerator;
use App\Random\Generators\Numbers\DistributionGenerator;
use App\Random\Generators\Numbers\GaussianGenerator;
use App\Random\Generators\Numbers\IntegersGenerator;
use App\Random\Generators\Numbers\LotteryGenerator;
use App\Random\Generators\Numbers\PasswordGenerator;
use App\Random\Generators\Numbers\PrimeGenerator;
use App\Random\Generators\Numbers\TimestampGenerator;
use App\Random\Generators\Numbers\UuidGenerator;
use App\Random\Generators\Patterns\AutomatonGenerator;
use App\Random\Generators\Patterns\DlaGenerator;
use App\Random\Generators\Patterns\LifeGenerator;
use App\Random\Generators\Patterns\LsystemGenerator;
use App\Random\Generators\Patterns\MazeGenerator;
use App\Random\Generators\Patterns\PerlinGenerator;
use App\Random\Generators\Patterns\PoissonGenerator;
use App\Random\Generators\Patterns\ReactionGenerator;
use App\Random\Generators\Patterns\SimplexGenerator;
use App\Random\Generators\Patterns\SpectralGenerator;
use App\Random\Generators\Patterns\TruchetGenerator;
use App\Random\Generators\Patterns\VoronoiGenerator;
use App\Random\Generators\Patterns\WalkGenerator;
use App\Random\Generators\Patterns\WfcGenerator;
use App\Random\Generators\Patterns\WorleyGenerator;
use App\Random\Generators\Words\ArticleGenerator;
use App\Random\Generators\Words\BrandGenerator;
use App\Random\Generators\Words\IdentityGenerator as FictionalPeopleGenerator;
use App\Random\Generators\Words\LoremGenerator;
use App\Random\Generators\Words\PassphraseGenerator;
use App\Random\Generators\Words\PseudoWordsGenerator;
use App\Random\Generators\Words\RelatedGenerator;
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
     * Rate limiting.
     *
     * Two buckets, because the endpoints cost wildly different amounts. A JSON
     * response is microseconds of pure PHP; a PNG or WAV spawns a Node process and
     * can take half a second of CPU. Charging both against one allowance would mean
     * either throttling cheap calls needlessly or letting sixty renders a minute per
     * IP saturate the box.
     *
     * A note on "in memory": PHP shares nothing between requests, so a genuinely
     * in-process counter would reset on every call and limit nothing. APCu is the
     * real in-memory option and is used when the extension is present; otherwise
     * this falls back to the file cache, which needs no service, no extension and no
     * database round trip. Either way it is per-server — good enough for a showcase,
     * and worth saying out loud rather than implying a distributed guarantee.
     */
    'limits' => [
        'store' => env('RANDOMLY_LIMIT_STORE', extension_loaded('apcu') ? 'apc' : 'file'),
        'per_minute' => (int) env('RANDOMLY_LIMIT_PER_MINUTE', 60),
        'media_per_minute' => (int) env('RANDOMLY_LIMIT_MEDIA_PER_MINUTE', 20),
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
        DistributionGenerator::class,
        DecimalsGenerator::class,
        CoordinatesGenerator::class,
        LotteryGenerator::class,
        CoinGenerator::class,
        UuidGenerator::class,
        PasswordGenerator::class,
        BytesGenerator::class,
        PrimeGenerator::class,
        TimestampGenerator::class,
        PassphraseGenerator::class,
        PseudoWordsGenerator::class,
        SyllabicGenerator::class,
        BrandGenerator::class,
        LoremGenerator::class,
        ArticleGenerator::class,
        SpeciesGenerator::class,
        RelatedGenerator::class,
        // Aliased in the imports: the equations module has an IdentityGenerator
        // too, and both are named after their own key — equations.identity is a
        // trigonometric identity, words.identity is a person who does not exist.
        FictionalPeopleGenerator::class,
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
        SimplexGenerator::class,
        WorleyGenerator::class,
        SpectralGenerator::class,
        AutomatonGenerator::class,
        LifeGenerator::class,
        ReactionGenerator::class,
        TruchetGenerator::class,
        PoissonGenerator::class,
        VoronoiGenerator::class,
        MazeGenerator::class,
        LsystemGenerator::class,
        WfcGenerator::class,
        WalkGenerator::class,
        DlaGenerator::class,
        FlowFieldGenerator::class,
        BlobGenerator::class,
        IdenticonGenerator::class,
        CirclesGenerator::class,
        MondrianGenerator::class,
        GradientGenerator::class,
        SprayGenerator::class,
        TilesGenerator::class,
        StrataGenerator::class,
        NoiseGenerator::class,
        RhythmGenerator::class,
        MelodyGenerator::class,
        PluckGenerator::class,
        ChordGenerator::class,
        DroneGenerator::class,
        BleepGenerator::class,
        AmbientGenerator::class,
    ],

];
