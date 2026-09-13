@props(['param', 'value' => null])

@php
    /**
     * One control, rendered from one declared Param.
     *
     * No generator writes UI. Everything on the studio's right-hand side comes
     * through here, which is why adding a generator stays a one-file job.
     */
    $name = $param->name;
    $current = $value ?? $param->default;
    $model = "params['{$name}']";
    $id = 'param-'.$name;

    // A slider needs both ends. Without them we fall back to a plain number field
    // rather than inventing a range the generator never declared.
    $isNumeric = in_array($param->type, ['int', 'float'], true);
    $hasRange = $isNumeric && $param->min !== null && $param->max !== null;
    $step = $param->step ?? ($param->type === 'int' ? 1 : 0.1);
@endphp

<div class="px-5 py-4" data-param="{{ $name }}">
    <div class="flex items-baseline justify-between gap-3">
        <label for="{{ $id }}" class="text-sm text-text">{{ $param->label }}</label>

        @if ($isNumeric)
            <input type="number"
                   id="{{ $id }}-value"
                   aria-label="{{ $param->label }} (exact value)"
                   x-model.number="{{ $model }}"
                   @change="paramsChanged()"
                   @if ($param->min !== null) min="{{ $param->min + 0 }}" @endif
                   @if ($param->max !== null) max="{{ $param->max + 0 }}" @endif
                   step="{{ $step + 0 }}"
                   value="{{ $current }}"
                   class="tap w-24 border border-line bg-surface/60 px-2 py-1.5 text-right font-mono text-xs text-signal num
                          focus:border-signal focus:outline-none">
        @endif
    </div>

    @if ($isNumeric)
        @if ($hasRange)
            <input type="range"
                   id="{{ $id }}"
                   x-model.number="{{ $model }}"
                   @change="paramsChanged()"
                   min="{{ $param->min + 0 }}" max="{{ $param->max + 0 }}" step="{{ $step + 0 }}"
                   value="{{ $current }}"
                   class="mt-3 w-full">
            <div class="mt-1.5 flex justify-between font-mono text-[0.62rem] text-muted num">
                <span>{{ $param->min + 0 }}</span>
                <span>{{ $param->max + 0 }}</span>
            </div>
        @else
            <p class="mt-2 font-mono text-[0.62rem] text-muted num">
                unbounded — type a value
            </p>
        @endif

    @elseif ($param->type === 'bool')
        <div class="mt-3">
            <button type="button"
                    id="{{ $id }}"
                    role="switch"
                    @click="{{ $model }} = !{{ $model }}; paramsChanged()"
                    :aria-checked="{{ $model }} ? 'true' : 'false'"
                    aria-checked="{{ $current ? 'true' : 'false' }}"
                    :class="{{ $model }} ? 'border-signal' : 'border-line'"
                    class="tap group flex w-full items-center justify-between gap-3 border px-3 py-2 text-left transition-colors">
                <span class="font-mono text-xs text-muted num"
                      x-text="{{ $model }} ? 'on' : 'off'">{{ $current ? 'on' : 'off' }}</span>
                <span class="relative block h-4 w-8 shrink-0 border transition-colors"
                      :class="{{ $model }} ? 'border-signal bg-signal/20' : 'border-line'"
                      aria-hidden="true">
                    <span class="absolute top-1/2 block size-2 -translate-y-1/2 transition-all"
                          :class="{{ $model }} ? 'left-[1.125rem] bg-signal' : 'left-1 bg-muted'"></span>
                </span>
            </button>
        </div>

    @elseif ($param->type === 'enum')
        <div class="mt-3 flex flex-wrap gap-px border border-line bg-line" role="group" aria-labelledby="{{ $id }}-label">
            <span id="{{ $id }}-label" class="sr-only">{{ $param->label }}</span>
            @foreach ($param->options as $optionValue => $optionLabel)
                <button type="button"
                        @click="{{ $model }} = '{{ $optionValue }}'; paramsChanged()"
                        :aria-pressed="String({{ $model }}) === '{{ $optionValue }}' ? 'true' : 'false'"
                        aria-pressed="{{ (string) $current === (string) $optionValue ? 'true' : 'false' }}"
                        :class="String({{ $model }}) === '{{ $optionValue }}' ? 'bg-surface text-signal' : 'bg-ground text-muted hover:text-text'"
                        class="tap flex flex-1 items-center justify-center px-3 py-2.5 text-center text-xs leading-snug transition-colors
                               {{ (string) $current === (string) $optionValue ? 'bg-surface text-signal' : 'bg-ground text-muted' }}">
                    {{ $optionLabel }}
                </button>
            @endforeach
        </div>

    @else
        <input type="text"
               id="{{ $id }}"
               x-model="{{ $model }}"
               @change="paramsChanged()"
               @keydown.enter.prevent="paramsChanged(true)"
               @if ($param->max !== null) maxlength="{{ (int) $param->max }}" @endif
               value="{{ $current }}"
               autocomplete="off" spellcheck="false"
               class="tap mt-3 w-full border border-line bg-surface/60 px-3 py-2.5 font-mono text-sm text-text
                      focus:border-signal focus:outline-none">
    @endif

    @if ($param->help)
        <p class="mt-2 text-xs leading-relaxed text-muted">{{ $param->help }}</p>
    @endif
</div>
