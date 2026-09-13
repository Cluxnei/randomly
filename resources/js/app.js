import Alpine from 'alpinejs';
import { renderFromKey } from './audio/engine.js';
import { Player, downloadWav, peaks } from './audio/player.js';
import { toPng } from './canvas.js';
// Registers the `mathResult` component and renders the worksheet's static TeX.
import './math.js';
import { flowFieldPass, render as renderCanvas } from './renderers.js';

/**
 * Everything on this site talks to the same public API a third party would.
 * There is no private endpoint, so there is nothing here that a reader of
 * /api/v1 could not have written themselves.
 */
const api = async (url, options = {}) => {
    const response = await fetch(url, {
        headers: { Accept: 'application/json', ...(options.headers ?? {}) },
        ...options,
    });

    let payload = null;

    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (!response.ok) {
        throw new Error(
            payload?.message ??
                (response.status === 429
                    ? 'Rate limited — 60 requests a minute per IP. Give it a moment.'
                    : `The API answered ${response.status}.`)
        );
    }

    return payload;
};

/** base64url(JSON) with sorted keys — the same shape Generation::encodedParams() makes. */
const encodeParams = (params) => {
    const sorted = {};
    Object.keys(params)
        .sort()
        .forEach((key) => {
            sorted[key] = params[key];
        });

    return btoa(JSON.stringify(sorted)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const META_LABELS = {
    entropy_in_bytes: 'entropy in',
    stream_bytes_used: 'stream used',
    entropy_out_bits: 'entropy out',
    rejections: 'rejections',
    duration_us: 'duration',
    alphabet_size: 'alphabet',
    crack_time: 'crack time',
    crack_assumption: 'assumption',
    render_ms: 'browser render',
};

/**
 * Meta keys the strip does not show as a row.
 *
 * `note` is a sentence of explanation the generator wants printed next to its
 * result, not a measurement — see the identicon, which needs to say out loud that
 * it ignores the seed. The strip is instrumentation and a paragraph in it reads
 * as a number that got away.
 */
const META_ASIDES = ['note', 'latex'];

const META_ORDER = [
    'entropy_in_bytes',
    'stream_bytes_used',
    'entropy_out_bits',
    'rejections',
    'duration_us',
];

const formatMetaValue = (key, value) => {
    if (typeof value === 'boolean') return value ? 'yes' : 'no';
    if (value === null || value === undefined) return '—';

    if (key === 'duration_us') return `${Number(value).toLocaleString()} µs`;
    if (key === 'entropy_in_bytes' || key === 'stream_bytes_used') return `${Number(value).toLocaleString()} bytes`;
    if (key === 'entropy_out_bits') return `${Number(value).toLocaleString()} bits`;
    if (key === 'alphabet_size') return `${Number(value).toLocaleString()} chars`;
    if (key === 'render_ms') return `${Number(value).toLocaleString()} ms`;
    if (Array.isArray(value)) return value.join(' ');

    return typeof value === 'number' ? Number(value).toLocaleString() : String(value);
};

/**
 * Trim a receipt narrative down to the clause that names the draw.
 *
 * The narratives are written as sentences — "drand round 6,458,522 — no single
 * operator could have predicted this value." — and the half before the dash is
 * the provenance. That is what belongs in a caption burned into the corner of an
 * exported image; the editorial half belongs on the page.
 */
/*
 * How much caption a canvas can actually show.
 *
 * canvas.js draws it as one unwrapped line at 1.3% of the canvas width in a
 * monospace face, so the number of characters that fit is a constant — about a
 * hundred and twenty — no matter how large the image is.
 */
const CAPTION_BUDGET = 118;

const receiptClause = (narrative) =>
    String(narrative ?? '')
        .split('—')[0]
        .trim()
        .replace(/\.$/, '');

/**
 * The live entropy ticker.
 *
 * Reads /api/v1/sources, which is cache-only on the server — polling it never
 * triggers an outbound fetch, so leaving this page open costs the beacons
 * nothing. Server-rendered values are the pre-hydration state.
 */
Alpine.data('entropyTicker', (initial = []) => ({
    sources: initial,
    failed: false,

    init() {
        this.poll();
        setInterval(() => this.poll(), 5000);
    },

    async poll() {
        try {
            const payload = await api('/api/v1/sources');
            this.sources = payload.sources ?? this.sources;
            this.failed = false;
        } catch {
            // A ticker that lies is worse than a ticker that pauses.
            this.failed = true;
        }
    },

    current(index) {
        return this.sources[index]?.current ?? '—';
    },

    isUp(index) {
        return (this.sources[index]?.status ?? 'up') === 'up';
    },
}));

/**
 * The studio.
 *
 * Two actions, deliberately not the same button:
 *   generate()  — new entropy, new receipt.
 *   rerender()  — the same 16 bytes, different parameters.
 * That distinction is the seed concept, taught without a paragraph of copy.
 */
Alpine.data('studio', (config) => ({
    key: config.key,
    version: config.version,
    sensitive: config.sensitive,
    params: { ...config.params },
    source: config.source ?? 'auto',
    display: config.display,
    meta: config.meta ?? {},
    receipt: config.receipt ?? {},
    token: config.token ?? null,
    permalink: config.permalink ?? null,
    replayed: config.replayed ?? false,
    busy: false,
    mode: null,
    dirty: false,
    error: null,
    copied: false,

    // Canvas generators ship a spec and a render key instead of pixels. The
    // browser owns the drawing from here on: a slider move redraws locally and
    // never waits on the network.
    renderer: config.renderer ?? 'text',
    spec: config.spec ?? null,
    renderKey: config.render_key ?? null,
    drawing: false,
    rendered: null,
    exported: null,

    // Audio works exactly as canvas does — a score and a render key instead of
    // pixels and a render key — with one extra state: the samples, once made,
    // outlive the drawing of them. They are what gets played, exported and
    // drawn as a waveform.
    audio: null,
    player: null,
    playing: false,
    progress: 0,
    monitor: 0.8,
    muted: false,

    init() {
        // $refs are populated as Alpine walks the tree, and this component's
        // root is initialised before the canvas inside it exists.
        if (this.isCanvas) this.$nextTick(() => this.paint());
        if (this.isAudio) this.$nextTick(() => this.synthesise());
    },

    get isCanvas() {
        return this.renderer === 'canvas' && this.spec !== null;
    },

    get isAudio() {
        return this.renderer === 'audio' && this.spec !== null;
    },

    /** A generator's aside — a sentence about the result, not a measurement. */
    get note() {
        return this.meta.note ?? null;
    },

    get metaRows() {
        const shown = (key) => !META_ASIDES.includes(key);

        const keys = [
            ...META_ORDER.filter((k) => k in this.meta),
            ...Object.keys(this.meta).filter((k) => !META_ORDER.includes(k) && shown(k)),
        ];

        const rows = keys.map((key) => ({
            key,
            label: META_LABELS[key] ?? key.replace(/_/g, ' '),
            value: formatMetaValue(key, this.meta[key]),
            // A palette is better seen than read. Every visual generator
            // reports one, so the strip draws it rather than printing seven hex
            // codes at a reader who wanted to know what colour it was.
            swatches: key === 'palette' && Array.isArray(this.meta[key]) ? this.meta[key] : null,
        }));

        if (this.rendered === null) return rows;

        // Sat immediately after the server's own duration, because the two
        // numbers are only interesting next to each other: a few hundred
        // microseconds to decide what to draw, tens of milliseconds to draw it.
        const row = {
            key: 'render_ms',
            label: META_LABELS.render_ms,
            value: formatMetaValue('render_ms', this.rendered.milliseconds),
            swatches: null,
        };

        const after = rows.findIndex((r) => r.key === 'duration_us');
        rows.splice(after === -1 ? rows.length : after + 1, 0, row);

        return rows;
    },

    /** Pixels, formatted, for the loading state and the export line. */
    get dimensions() {
        if (!this.spec) return '';

        return `${Number(this.spec.width).toLocaleString()} × ${Number(this.spec.height).toLocaleString()}`;
    },

    /**
     * Draw the current spec.
     *
     * Every path into the result area comes through here: the server-rendered
     * first load, a Generate that changed the render key, and a Re-render that
     * kept it. Re-deriving the stream each time is a handful of milliseconds and
     * means there is no cached Rng to get out of step with the spec beside it.
     */
    async paint() {
        if (!this.isCanvas) return;

        const canvas = this.$refs.canvas;

        if (!canvas) return;

        this.drawing = true;
        this.exported = null;

        // Hand the browser a frame to actually show the loading state before
        // the renderer takes the main thread for the next 50ms.
        await this.$nextTick();
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        try {
            this.rendered = await renderCanvas(canvas, { value: this.spec, render_key: this.renderKey });
        } catch (e) {
            this.rendered = null;
            this.error = `The browser could not draw this: ${e.message}`;
        } finally {
            this.drawing = false;
        }
    },

    /**
     * Make the samples.
     *
     * The same contract the canvas renderers keep: the server decided *what* to
     * play and the browser works out what that sounds like, so moving a slider
     * re-synthesises locally and never waits on the network. A four-minute piece
     * arrived as about two kilobytes of JSON.
     */
    async synthesise() {
        if (!this.isAudio) return;

        this.drawing = true;
        this.exported = null;
        this.stop();

        // Let the browser paint the working state before the render takes the
        // main thread — a chord progression is a few hundred milliseconds of
        // solid arithmetic.
        await this.$nextTick();
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        try {
            this.audio = await renderFromKey({ value: this.spec, render_key: this.renderKey });
            this.rendered = { milliseconds: this.audio.milliseconds };
            this.drawWaveform();
        } catch (e) {
            this.audio = null;
            this.rendered = null;
            this.error = `The browser could not synthesise this: ${e.message}`;
        } finally {
            this.drawing = false;
        }
    },

    /**
     * Draw the render as a waveform.
     *
     * Min and max per pixel column rather than one sample per column — see
     * peaks() in player.js. An audio generator has nothing else to show while it
     * is not playing, and a flat rectangle reads as a failure.
     */
    drawWaveform() {
        const canvas = this.$refs.waveform;

        if (!canvas || !this.audio) return;

        // The backing store follows the element's real size, so the waveform is
        // crisp on a retina display instead of a 2× blur.
        const ratio = window.devicePixelRatio || 1;
        const width = Math.max(1, Math.round(canvas.clientWidth * ratio));
        const height = Math.max(1, Math.round(canvas.clientHeight * ratio));

        canvas.width = width;
        canvas.height = height;

        const ctx = canvas.getContext('2d');
        const columns = peaks(this.audio.channels, Math.floor(width / ratio));
        const middle = height / 2;
        const styles = getComputedStyle(document.documentElement);

        ctx.clearRect(0, 0, width, height);
        ctx.fillStyle = styles.getPropertyValue('--color-signal')?.trim() || '#7dd3fc';

        columns.forEach(([low, high], i) => {
            const x = i * ratio;
            const top = middle - high * middle * 0.94;
            const bottom = middle - low * middle * 0.94;

            // A minimum of one device pixel: silence is a line, not a gap. A
            // waveform that disappears during a rest looks like a render that
            // stopped early.
            ctx.fillRect(x, top, ratio, Math.max(ratio, bottom - top));
        });
    },

    /** Play, or stop if it is already playing. Never anything on page load — docs/09 §9. */
    async togglePlay() {
        if (this.playing) return this.stop();
        if (!this.audio) return;

        if (!this.player) {
            this.player = new Player();
            this.player.onended = () => {
                this.playing = false;
                this.progress = 0;
            };
        }

        this.player.setVolume(this.muted ? 0 : this.monitor);
        this.player.load(this.audio);

        try {
            await this.player.play({ loop: Boolean(this.spec.loop) });
        } catch (e) {
            this.error = `The browser refused to play this: ${e.message}`;
            return;
        }

        this.playing = true;
        this.tick();
    },

    stop() {
        if (this.player) this.player.stop();

        this.playing = false;
        this.progress = 0;
    },

    /** One rAF per frame while playing — the progress bar and the playhead. */
    tick() {
        if (!this.playing || !this.player) return;

        this.progress = this.audio ? this.player.position() / this.audio.duration : 0;

        requestAnimationFrame(() => this.tick());
    },

    setMonitor(value) {
        this.monitor = Number(value);
        this.muted = false;

        if (this.player) this.player.setVolume(this.monitor);
    },

    toggleMute() {
        this.muted = !this.muted;

        if (this.player) this.player.setVolume(this.muted ? 0 : this.monitor);
    },

    get elapsed() {
        if (!this.audio) return '0:00';

        return this.clock(this.progress * this.audio.duration);
    },

    get total() {
        return this.audio ? this.clock(this.audio.duration) : '0:00';
    },

    clock(seconds) {
        const whole = Math.max(0, Math.floor(seconds));

        return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
    },

    get wavFilename() {
        const stem = this.key.replace(/\./g, '-');

        return `randomly-${stem}${this.token ? `-${this.token.toLowerCase()}` : ''}.wav`;
    },

    downloadWav() {
        if (!this.audio) return;

        try {
            const bytes = downloadWav(this.audio, this.wavFilename);

            this.exported = `${(bytes / 1024 / 1024).toFixed(1)} MB`;
        } catch (e) {
            this.error = `The browser refused to export the audio: ${e.message}`;
        }
    },

    /**
     * The line burned into the corner of an exported PNG.
     *
     * Built from this result's own receipt and its own permalink, so a shared
     * image carries the provenance of the thing in it and a link back to the
     * exact seed that made it. Nothing here is decorative.
     */
    get receiptLine() {
        const clause = receiptClause(this.receipt.narrative) || this.receipt.source_label;
        const full = ['Randomly', clause, this.permalink].filter(Boolean).join(' · ');

        if (!this.permalink || full.length <= CAPTION_BUDGET) return full;

        /*
         * A permalink carrying fifteen base64'd parameters runs past three
         * hundred characters and would be cut off mid-URL, which is worse than
         * no URL at all — nobody can retype the missing half. So the corner
         * carries what a reader could actually act on: where it came from, which
         * generator, and the seed. The token *is* the seed, which is the whole
         * premise of the permalink it replaces; the full link is in the Copy
         * link field, and the exact caption is printed under the button so
         * there is no surprise in the file.
         */
        const host = new URL(this.permalink).host;
        const short = ['Randomly', clause, this.key, `seed ${this.token}`, host].join(' · ');

        return short.length <= CAPTION_BUDGET
            ? short
            : ['Randomly', this.receipt.source_label, this.key, `seed ${this.token}`, host].join(' · ');
    },

    get pngFilename() {
        const stem = this.key.replace(/\./g, '-');

        return `randomly-${stem}${this.token ? `-${this.token.toLowerCase()}` : ''}.png`;
    },

    async downloadPng() {
        if (!this.$refs.canvas) return;

        try {
            const bytes = await toPng(this.$refs.canvas, {
                filename: this.pngFilename,
                caption: this.receiptLine,
            });

            this.exported = `${(bytes / 1024).toFixed(0)} KB`;
        } catch (e) {
            this.error = `The browser refused to export the image: ${e.message}`;
        }
    },

    get observedAt() {
        if (!this.receipt.observed_at) return '—';

        return new Date(this.receipt.observed_at).toISOString().replace('.000', '').replace('T', ' ').replace('Z', 'Z');
    },

    /** New entropy: a different seed, a different receipt, a different answer. */
    async generate() {
        await this.run('generate', () =>
            api('/api/v1/generate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    generator: this.key,
                    params: this.params,
                    source: this.source,
                }),
            })
        );
    },

    /**
     * Same seed, new parameters. Replay recomputes from the token, so the
     * randomness is byte-for-byte the one already on screen.
     */
    async rerender() {
        if (this.sensitive || !this.token) {
            return this.generate();
        }

        const query = new URLSearchParams({
            g: this.key,
            v: String(this.version),
            p: encodeParams(this.params),
        });

        if (this.receipt.source) query.set('s', this.receipt.source);
        if (this.receipt.reference) query.set('r', this.receipt.reference);

        await this.run('rerender', () => api(`/api/v1/replay/${this.token}?${query.toString()}`));
    },

    async run(mode, request) {
        if (this.busy) return;

        this.busy = true;
        this.mode = mode;
        this.error = null;

        try {
            this.apply(await request());
            this.dirty = false;
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
            this.mode = null;
        }
    },

    apply(payload) {
        if (!payload) return;

        this.display = payload.display ?? this.display;
        this.meta = payload.meta ?? {};
        this.receipt = payload.receipt ?? {};
        this.token = payload.seed?.token ?? null;
        this.permalink = payload.seed?.permalink ?? null;
        this.replayed = Boolean(payload.replayed);

        if (this.isCanvas || this.isAudio) {
            // Generate brings a new render key; Re-render brings the same one
            // and a different spec. The redraw below does not care which — but
            // that the key is identical across a Re-render is the whole claim
            // the two buttons are making, so it is read from the payload rather
            // than assumed either way.
            const audio = this.isAudio;

            this.spec = payload.value ?? this.spec;
            this.renderKey = payload.render_key ?? this.renderKey;

            if (audio) this.synthesise();
            else this.paint();
        }

        // 120ms fade and a 4px rise. Results arrive; they never bounce.
        const node = this.$refs.result;

        if (node) {
            node.classList.remove('animate-rise');
            void node.offsetWidth;
            node.classList.add('animate-rise');
        }
    },

    /** A dragged slider should not fire a request per pixel. */
    paramsChanged(immediate = false) {
        this.dirty = true;

        if (this.sensitive) return;

        clearTimeout(this._debounce);
        this._debounce = setTimeout(() => this.rerender(), immediate ? 0 : 280);
    },

    async copy(text = null) {
        try {
            await navigator.clipboard.writeText(text ?? this.display);
            this.copied = true;
            setTimeout(() => (this.copied = false), 1400);
        } catch {
            this.error = 'The browser refused clipboard access.';
        }
    },

    /** Space regenerates, C copies — docs/10-ui-brand.md §6. */
    shortcut(event) {
        const tag = (document.activeElement?.tagName ?? '').toLowerCase();

        // A focused button already answers Space; firing twice would draw two seeds.
        if (['input', 'textarea', 'select', 'button', 'a'].includes(tag) || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (event.code === 'Space') {
            event.preventDefault();
            this.generate();
        }

        if (event.key === 'c' || event.key === 'C') {
            event.preventDefault();
            this.copy();
        }
    },
}));

/**
 * One at a time.
 *
 * Every card on the library page draws a real canvas, and some of the renderers
 * behind them are genuinely expensive — a reaction–diffusion thumbnail is a few
 * thousand iterations of a PDE. Eight of those racing each other on the main
 * thread is a locked-up page, so previews queue and the queue yields a frame
 * between each one. The page stays scrollable the whole time it fills in.
 */
let previewQueue = Promise.resolve();

const queuePreview = (job) => {
    previewQueue = previewQueue
        .then(job)
        .catch(() => {})
        .then(() => new Promise((resolve) => requestAnimationFrame(resolve)));

    return previewQueue;
};

/**
 * A still thumbnail of one generator, drawn from a spec the page was served with.
 *
 * Deliberately not animated. One moving canvas on a page is a focal point; six of
 * them is a jank budget nobody can afford and a card grid nobody can read.
 */
Alpine.data('canvasPreview', (payload) => ({
    failed: false,

    init() {
        // Nothing below the fold draws until it is looked at. A visitor who never
        // scrolls past the first row pays for the first row.
        const observer = new IntersectionObserver((entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) return;

            observer.disconnect();
            queuePreview(() => this.paint());
        }, { rootMargin: '200px' });

        observer.observe(this.$el);
    },

    async paint() {
        try {
            await renderCanvas(this.$refs.canvas, payload);
        } catch {
            // A thumbnail that cannot draw leaves the card exactly as the server
            // sent it. There is nothing here worth an error message about.
            this.failed = true;
        }
    },
}));

/**
 * The landing hero: a flow field, grown rather than drawn.
 *
 * The trails advance a few steps per frame instead of all at once, so the page
 * paints immediately and the picture arrives over the following second or so —
 * the one place on the site where a canvas moves. Under
 * `prefers-reduced-motion` it is completed in a single blocking pass and simply
 * appears, which is what docs/10 §6 asks for.
 */
Alpine.data('heroFlowField', (payload) => ({
    async init() {
        if (!payload) return;

        let pass;

        try {
            pass = await flowFieldPass(this.$el, payload);
        } catch {
            // The hero is decoration over a gradient that stands on its own.
            return;
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            pass.run();
            pass.commit();

            return;
        }

        // Around a hundred and fifty frames, whatever the trail length: the field
        // grows over a couple of seconds and then stops. It is not a loop. A
        // canvas that keeps moving after it has finished saying what it had to
        // say is a space heater with a headline on it.
        const perFrame = Math.max(1, Math.ceil(payload.value.trail / 150));
        const startedAt = performance.now();

        const tick = () => {
            // A slow machine does not get a slower animation, it gets a shorter
            // one: past three seconds the rest of the pass is finished in a
            // single blocking run and the picture simply lands.
            if (performance.now() - startedAt > 3000) {
                pass.run();
                pass.commit();

                return;
            }

            for (let i = 0; i < perFrame && !pass.done; i++) pass.step();

            pass.commit();

            if (!pass.done) requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
    },
}));

window.Alpine = Alpine;

Alpine.start();
