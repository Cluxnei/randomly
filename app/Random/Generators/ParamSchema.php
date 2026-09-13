<?php

declare(strict_types=1);

namespace App\Random\Generators;

/**
 * A generator's declared parameters.
 *
 * This is the hinge of the whole architecture. Declare the controls once and the
 * studio panel, the API's validation, the catalogue entry and the permalink
 * encoding all follow from it. Adding a generator stays a one-file job.
 */
final class ParamSchema
{
    /** @var array<string, Param> */
    private array $params = [];

    public static function make(): self
    {
        return new self;
    }

    public function int(string $name, string $label, int $default, ?int $min = null, ?int $max = null, ?string $help = null): self
    {
        return $this->add(new Param($name, 'int', $label, $default, $min, $max, 1, [], $help));
    }

    public function float(string $name, string $label, float $default, ?float $min = null, ?float $max = null, float $step = 0.1, ?string $help = null): self
    {
        return $this->add(new Param($name, 'float', $label, $default, $min, $max, $step, [], $help));
    }

    public function bool(string $name, string $label, bool $default = false, ?string $help = null): self
    {
        return $this->add(new Param($name, 'bool', $label, $default, null, null, null, [], $help));
    }

    /** @param array<string, string> $options value => label */
    public function enum(string $name, string $label, array $options, string $default, ?string $help = null): self
    {
        return $this->add(new Param($name, 'enum', $label, $default, null, null, null, $options, $help));
    }

    public function string(string $name, string $label, string $default = '', ?int $max = null, ?string $help = null): self
    {
        return $this->add(new Param($name, 'string', $label, $default, null, $max, null, [], $help));
    }

    private function add(Param $param): self
    {
        $this->params[$param->name] = $param;

        return $this;
    }

    /** @return array<string, Param> */
    public function params(): array
    {
        return $this->params;
    }

    public function defaults(): array
    {
        return array_map(fn (Param $p) => $p->default, $this->params);
    }

    public function rules(): array
    {
        return array_map(fn (Param $p) => $p->rules(), $this->params);
    }

    /**
     * Turn arbitrary input into a complete, in-range parameter set.
     *
     * Unknown keys are dropped and missing ones fall back to defaults, so a
     * generator's generate() never has to defend itself against the request.
     */
    public function coerce(array $input): Params
    {
        $out = [];

        foreach ($this->params as $name => $param) {
            $value = array_key_exists($name, $input) ? $param->cast($input[$name]) : $param->default;
            $out[$name] = $param->clamp($value);
        }

        return new Params($out);
    }

    public function toArray(): array
    {
        return array_values(array_map(fn (Param $p) => $p->toArray(), $this->params));
    }
}
