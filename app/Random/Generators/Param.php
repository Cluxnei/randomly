<?php

declare(strict_types=1);

namespace App\Random\Generators;

/**
 * One declared control. The studio panel, the API validation rules and the
 * catalogue metadata are all rendered from these — a generator never writes UI.
 */
final readonly class Param
{
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public mixed $default,
        public ?float $min = null,
        public ?float $max = null,
        public ?float $step = null,
        public array $options = [],
        public ?string $help = null,
    ) {}

    public function rules(): array
    {
        $rules = ['sometimes'];

        match ($this->type) {
            'int' => $rules[] = 'integer',
            'float' => $rules[] = 'numeric',
            'bool' => $rules[] = 'boolean',
            'enum' => $rules[] = 'in:'.implode(',', array_keys($this->options)),
            default => $rules[] = 'string',
        };

        if ($this->min !== null) {
            $rules[] = 'min:'.$this->min;
        }

        if ($this->max !== null) {
            $rules[] = 'max:'.$this->max;
        }

        return $rules;
    }

    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }

    /** Clamp rather than reject: a slider dragged past its bound should saturate, not 422. */
    public function clamp(mixed $value): mixed
    {
        if ($this->type !== 'int' && $this->type !== 'float') {
            return $value;
        }

        if ($this->min !== null) {
            $value = max($value, $this->type === 'int' ? (int) $this->min : $this->min);
        }

        if ($this->max !== null) {
            $value = min($value, $this->type === 'int' ? (int) $this->max : $this->max);
        }

        return $value;
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'default' => $this->default,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'options' => $this->options ?: null,
            'help' => $this->help,
        ], fn ($v) => $v !== null);
    }
}
