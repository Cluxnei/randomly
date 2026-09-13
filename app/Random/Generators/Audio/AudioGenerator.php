<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;

/**
 * The shared half of an audio generator.
 *
 * Same architecture as the canvas modules, one dimension down: the server is the
 * composer and the browser is the orchestra. What crosses the wire is a *score* —
 * events, envelopes and synthesis parameters — and the browser re-derives its
 * noise from the render key and makes the samples. A four-minute piece is about
 * two kilobytes of JSON, and no audio file is ever written, stored or served.
 *
 * The synthesis is plain JavaScript over Float32Array rather than a Web Audio
 * node graph, which is what lets the identical code run under Node in
 * scripts/analyse-audio.mjs. Audio's failure modes are silent — pink noise that
 * is actually brown, a limiter that never engages, a string playing the wrong
 * note — so the output gets measured rather than listened to. Web Audio's only
 * job here is playing the finished buffer.
 */
abstract class AudioGenerator extends BaseGenerator
{
    /**
     * One rate for the whole module.
     *
     * 44.1 kHz because it is what every browser's AudioContext will happily
     * accept and what a WAV is expected to be. The renderer resamples nothing:
     * the score declares this and the analyser measures at the same number, so a
     * frequency asserted in a test is the frequency in the file.
     */
    public const SAMPLE_RATE = 44100;

    /**
     * Every generator starts here — docs/09 §9.
     *
     * −12 dBFS leaves 12 dB of headroom for layers that happen to align, and it
     * is quiet enough that the first play on unknown headphones is a surprise
     * rather than an injury.
     */
    public const DEFAULT_VOLUME_DB = -12.0;

    public function module(): Module
    {
        return Module::Audio;
    }

    public function renderer(): Renderer
    {
        return Renderer::Audio;
    }

    /** The volume control docs/09 §9 requires to be on screen at all times. */
    protected function volumeParam(ParamSchema $schema): ParamSchema
    {
        return $schema->float(
            'volume',
            'Volume (dBFS)',
            default: self::DEFAULT_VOLUME_DB,
            min: -36.0,
            max: 0.0,
            step: 1.0,
            help: 'Where the mix sits before the master limiter. 0 dBFS is full scale; everything starts 12 dB below it.',
        );
    }

    /** dBFS to a linear multiplier: 10^(dB/20), the amplitude form of the decibel. */
    protected function gain(float $db): float
    {
        return round(10 ** ($db / 20), 5);
    }

    /** Seconds in one bar of 4/4 at a given tempo. */
    protected function barSeconds(float $tempo): float
    {
        return 4 * 60 / max(1.0, $tempo);
    }

    /**
     * Round a duration to whole samples' worth of seconds.
     *
     * The score's `duration` is what the player's progress bar and the WAV length
     * are both built from, and a duration that does not land on a sample boundary
     * makes those two disagree by a frame. Cheap to avoid, confusing to debug.
     */
    protected function quantise(float $seconds): float
    {
        return round(round($seconds * self::SAMPLE_RATE) / self::SAMPLE_RATE, 6);
    }
}
