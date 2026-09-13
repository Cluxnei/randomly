@php
    $params = $generation->params->all();
    $token = $generation->seed->token();
    $receipt = $generation->receipt;

    // The link back to this exact sheet. Built from the token rather than from
    // the current URL so that a sheet generated without a seed still prints one
    // that can be typed back in.
    $permalink = route('worksheet', ['generator' => \Illuminate\Support\Str::after($generator->key(), '.')])
        .'?'.http_build_query([...$params, 'seed' => $token]);

    $columns = max(1, min(3, $columns));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $generator->name() }} worksheet · Randomly</title>

    @fonts
    {{-- The JS entry is here for KaTeX and its stylesheet; the sheet's own look
         is the <style> block below, which deliberately does not inherit the
         site's dark theme. Paper is white. --}}
    @vite(['resources/js/app.js'])

    <style>
        :root {
            --ink: #16181d;
            --faint: #6b7280;
            --rule: #d6d8dd;
            --accent: #1f7a4d;
            --paper: #ffffff;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #23262d;
            color: var(--ink);
            font-family: 'Inter', ui-sans-serif, system-ui, sans-serif;
            line-height: 1.5;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            justify-content: center;
            padding: 1.25rem 1rem;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.72rem;
            color: #9aa0ac;
        }

        .toolbar a, .toolbar button {
            background: none;
            border: 1px solid #3a3f49;
            color: #d7dae0;
            padding: 0.4rem 0.8rem;
            font: inherit;
            cursor: pointer;
            text-decoration: none;
        }

        .toolbar a:hover, .toolbar button:hover { border-color: var(--accent); color: #7ee2ab; }

        .sheet {
            background: var(--paper);
            width: 210mm;
            max-width: calc(100vw - 2rem);
            min-height: 297mm;
            margin: 0 auto 1.5rem;
            padding: 18mm 16mm 14mm;
            display: flex;
            flex-direction: column;
        }

        .masthead {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px solid var(--ink);
            padding-bottom: 0.6rem;
        }

        .masthead h1 { margin: 0; font-size: 1.35rem; letter-spacing: -0.02em; }
        .masthead p { margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--faint); max-width: 42ch; }

        .brand {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.66rem;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--faint);
            text-align: right;
            white-space: nowrap;
        }

        .fields {
            display: flex;
            gap: 2rem;
            margin: 1.1rem 0 1.6rem;
            font-size: 0.78rem;
            color: var(--faint);
        }

        .fields span { flex: 1; border-bottom: 1px solid var(--rule); padding-bottom: 0.3rem; }

        .problems {
            list-style: none;
            margin: 0;
            padding: 0;
            flex: 1;
            display: grid;
            grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr));
            gap: 0.4rem 2rem;
            align-content: start;
        }

        .problem {
            display: flex;
            gap: 0.6rem;
            padding: 0.55rem 0 0.75rem;
            border-bottom: 1px solid var(--rule);
            /* A problem split across a column or a page is unreadable and, worse,
               looks like a printing fault rather than a layout one. */
            break-inside: avoid;
            min-height: 3.1rem;
        }

        .problem .n {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.7rem;
            color: var(--faint);
            padding-top: 0.25rem;
            min-width: 1.6rem;
            text-align: right;
        }

        .problem .body { min-width: 0; overflow-x: auto; }

        .answers { break-before: page; }

        .key {
            list-style: none;
            margin: 0;
            padding: 0;
            flex: 1;
            display: grid;
            grid-template-columns: repeat({{ max(2, $columns) }}, minmax(0, 1fr));
            gap: 0.15rem 2rem;
            align-content: start;
            font-size: 0.85rem;
        }

        .key li {
            display: flex;
            gap: 0.6rem;
            padding: 0.3rem 0;
            border-bottom: 1px dotted var(--rule);
            break-inside: avoid;
        }

        .key .n {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.7rem;
            color: var(--faint);
            min-width: 1.6rem;
            text-align: right;
        }

        .key .a { color: var(--accent); min-width: 0; overflow-x: auto; }

        .colophon {
            margin-top: 1.4rem;
            padding-top: 0.6rem;
            border-top: 1px solid var(--rule);
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem 1.4rem;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 0.6rem;
            color: var(--faint);
        }

        /* The permalink in the footer is a long unbroken URL, and it has to be
           readable on paper — so it wraps rather than running off the sheet. */
        .colophon span {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .colophon .seed { color: var(--ink); }

        /* KaTeX sizes itself from the surrounding text; display mode adds margins
           this layout supplies itself. */
        .katex { font-size: 1.05em; }
        .katex-display { margin: 0; text-align: left; }

        /*
         * On a phone the sheet is not a sheet.
         *
         * A4 margins are 16mm — sixty pixels a side on a 360px screen, which
         * leaves two hundred for the maths. So the page keeps its paper look and
         * gives the margins up, and two columns of quadratics collapse to one: a
         * worksheet is read here and printed elsewhere, and the print rules below
         * are untouched by any of this.
         */
        @media screen and (max-width: 640px) {
            .sheet {
                width: auto;
                max-width: 100%;
                min-height: 0;
                margin: 0 0 1rem;
                padding: 1.25rem 1rem 1.5rem;
            }

            .problems,
            .key {
                grid-template-columns: minmax(0, 1fr);
                gap: 0.2rem;
            }

            .masthead { flex-wrap: wrap; }
            .brand { text-align: left; white-space: normal; }
            .masthead h1 { font-size: 1.15rem; }
            .fields { gap: 1rem; }
            .toolbar { padding: 0.9rem 0.75rem; }
            .toolbar a, .toolbar button { min-height: 2.75rem; display: inline-flex; align-items: center; }
        }

        @media print {
            @page { size: A4; margin: 14mm; }

            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet {
                width: auto;
                max-width: none;
                min-height: 0;
                margin: 0;
                padding: 0;
            }
            .answers { break-before: page; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('studio', ['module' => 'equations', 'generator' => \Illuminate\Support\Str::after($generator->key(), '.')]) }}">Back to the studio</a>
    <a href="{{ route('worksheet', ['generator' => \Illuminate\Support\Str::after($generator->key(), '.')]) }}?{{ http_build_query($params) }}">New seed</a>
    <span>{{ count($problems) }} problems · seed {{ $token }}</span>
</div>

<article class="sheet">
    <header class="masthead">
        <div>
            <h1>{{ $generator->name() }}</h1>
            <p>{{ $generator->tagline() }}</p>
        </div>
        <div class="brand">Randomly<br>{{ $generator->key() }}</div>
    </header>

    <div class="fields">
        <span>Name</span>
        <span>Class</span>
        <span>Date</span>
    </div>

    <ol class="problems">
        @foreach ($problems as $index => $problem)
            <li class="problem">
                <span class="n">{{ $index + 1 }}.</span>
                <div class="body">
                    {{-- data-tex is rendered by resources/js/math.js on load. The
                         plain form is the element's own text, so a sheet printed
                         before the script runs is still a readable sheet. --}}
                    <span data-tex="{{ $problem['prompt_latex'] }}" data-tex-display>{{ $problem['prompt'] }}</span>
                </div>
            </li>
        @endforeach
    </ol>

    @include('pages.partials.worksheet-colophon', ['page' => 'Problems'])
</article>

<article class="sheet answers">
    <header class="masthead">
        <div>
            <h1>Answer key</h1>
            <p>{{ $generator->name() }} — seed {{ $token }}</p>
        </div>
        <div class="brand">Randomly<br>{{ $generator->key() }}</div>
    </header>

    <ol class="key">
        @foreach ($problems as $index => $problem)
            <li>
                <span class="n">{{ $index + 1 }}.</span>
                <span class="a" data-tex="{{ $problem['answer_latex'] }}">{{ $problem['answer'] }}</span>
            </li>
        @endforeach
    </ol>

    @include('pages.partials.worksheet-colophon', ['page' => 'Answers'])
</article>

</body>
</html>
