<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

use InvalidArgumentException;

/**
 * Attribute and event configuration for the copies made at one relation path.
 *
 * Received by ReplicationBuilder::configure(). The builder's own exclude(),
 * with(), transform() and generate() configure the root model the same way.
 */
final class RelationRule
{
    /** @var list<string>|null */
    private ?array $exclude = null;

    /** @var array<string, mixed> */
    private array $with = [];

    /** @var array<string, callable> */
    private array $generators = [];

    /** @var list<callable> */
    private array $transforms = [];

    private ?bool $withoutEvents = null;

    private ?bool $preserveTimestamps = null;

    /**
     * Leave these attributes out of the copies.
     *
     * Primary and linking keys managed by a selected relation cannot be excluded.
     *
     * @param  array<int, string>  $attributes
     */
    public function exclude(array $attributes): self
    {
        $this->exclude = array_values(array_unique(array_merge($this->exclude ?? [], array_values($attributes))));

        return $this;
    }

    /**
     * Set attributes on the copies, through the model's mutators and casts.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function with(array $attributes): self
    {
        foreach ($attributes as $attribute => $value) {
            $this->with[$attribute] = $value;
        }

        return $this;
    }

    /**
     * Transform the raw attributes of each copy before it is inserted.
     *
     * The callback receives the raw (stored) attributes, the source model and
     * the context, and returns the new raw attributes. Several transforms run
     * in registration order.
     *
     * @param  callable(array<string, mixed>, \Illuminate\Database\Eloquent\Model, ReplicationContext): array<string, mixed>  $callback
     */
    public function transform(callable $callback): self
    {
        $this->transforms[] = $callback;

        return $this;
    }

    /**
     * Generate a value for one attribute of each copy, once per copied record.
     *
     * @param  callable(\Illuminate\Database\Eloquent\Model, ReplicationContext): mixed  $callback
     */
    public function generate(string $attribute, callable $callback): self
    {
        if ($attribute === '') {
            throw new InvalidArgumentException('generate() expects an attribute name.');
        }

        if (isset($this->generators[$attribute])) {
            throw new InvalidArgumentException("A generator for attribute [{$attribute}] is already registered on this path.");
        }

        $this->generators[$attribute] = $callback;

        return $this;
    }

    /**
     * Suppress Eloquent model events for the copies made at this path only.
     */
    public function withoutEvents(): self
    {
        $this->withoutEvents = true;

        return $this;
    }

    /**
     * Override the builder's preserveTimestamps() setting for this path.
     */
    public function preserveTimestamps(bool $preserve = true): self
    {
        $this->preserveTimestamps = $preserve;

        return $this;
    }

    /**
     * @internal
     *
     * @return list<string>|null
     */
    public function excludedAttributes(): ?array
    {
        return $this->exclude;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function attributeOverrides(): array
    {
        return $this->with;
    }

    /**
     * @internal
     *
     * @return array<string, callable>
     */
    public function generators(): array
    {
        return $this->generators;
    }

    /**
     * @internal
     *
     * @return list<callable>
     */
    public function transforms(): array
    {
        return $this->transforms;
    }

    /**
     * @internal
     */
    public function eventsSuppressed(): ?bool
    {
        return $this->withoutEvents;
    }

    /**
     * @internal
     */
    public function timestampsPreserved(): ?bool
    {
        return $this->preserveTimestamps;
    }

    /**
     * @internal
     */
    public function isEmpty(): bool
    {
        return $this->exclude === null
            && $this->with === []
            && $this->generators === []
            && $this->transforms === []
            && $this->withoutEvents === null
            && $this->preserveTimestamps === null;
    }

    /**
     * Whether two rules configure a copy identically (callbacks by identity).
     *
     * @internal
     */
    public function sameAs(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if ($this->exclude !== $other->exclude
            || $this->with != $other->with
            || $this->withoutEvents !== $other->withoutEvents
            || $this->preserveTimestamps !== $other->preserveTimestamps
            || array_keys($this->generators) !== array_keys($other->generators)
            || count($this->transforms) !== count($other->transforms)) {
            return false;
        }

        foreach ($this->generators as $attribute => $generator) {
            if ($generator !== $other->generators[$attribute]) {
                return false;
            }
        }

        foreach ($this->transforms as $index => $transform) {
            if ($transform !== $other->transforms[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the effective rule: this rule's settings win over the fallback's.
     *
     * exclude, transform, withoutEvents and preserveTimestamps replace the
     * fallback when set here; with and generate merge per attribute.
     *
     * @internal
     */
    public function over(self $fallback): self
    {
        $rule = new self;
        $rule->exclude = $this->exclude ?? $fallback->exclude;
        $rule->with = array_merge($fallback->with, $this->with);
        $rule->generators = array_merge($fallback->generators, $this->generators);
        $rule->transforms = $this->transforms !== [] ? $this->transforms : $fallback->transforms;
        $rule->withoutEvents = $this->withoutEvents ?? $fallback->withoutEvents;
        $rule->preserveTimestamps = $this->preserveTimestamps ?? $fallback->preserveTimestamps;

        return $rule;
    }

    /**
     * Build a rule from a deepReplicationDefaults() entry.
     *
     * @internal
     *
     * @param  array<mixed>  $defaults
     */
    public static function fromDefaults(array $defaults, string $where): self
    {
        $rule = new self;

        foreach ($defaults as $key => $value) {
            switch ($key) {
                case 'exclude':
                    $names = [];

                    foreach (self::arrayOption($value, $key, $where) as $attribute) {
                        if (! is_string($attribute)) {
                            throw new InvalidArgumentException("deepReplicationDefaults() {$where}.exclude must list attribute names.");
                        }

                        $names[] = $attribute;
                    }

                    $rule->exclude($names);

                    break;
                case 'with':
                    $overrides = [];

                    foreach (self::arrayOption($value, $key, $where) as $attribute => $override) {
                        if (! is_string($attribute)) {
                            throw new InvalidArgumentException("deepReplicationDefaults() {$where}.with must be keyed by attribute name.");
                        }

                        $overrides[$attribute] = $override;
                    }

                    $rule->with($overrides);

                    break;
                case 'generate':
                    foreach (self::arrayOption($value, $key, $where) as $attribute => $generator) {
                        if (! is_string($attribute) || ! is_callable($generator)) {
                            throw new InvalidArgumentException("deepReplicationDefaults() {$where}.generate must map attribute names to callables.");
                        }

                        $rule->generate($attribute, $generator);
                    }

                    break;
                case 'transform':
                    if (! is_callable($value)) {
                        throw new InvalidArgumentException("deepReplicationDefaults() {$where}.transform must be a callable.");
                    }
                    $rule->transform($value);

                    break;
                case 'without_events':
                    if (! is_bool($value)) {
                        throw new InvalidArgumentException("deepReplicationDefaults() {$where}.without_events must be a boolean.");
                    }
                    $rule->withoutEvents = $value;

                    break;
                case 'preserve_timestamps':
                    if (! is_bool($value)) {
                        throw new InvalidArgumentException("deepReplicationDefaults() {$where}.preserve_timestamps must be a boolean.");
                    }
                    $rule->preserveTimestamps = $value;

                    break;
                default:
                    $shown = is_string($key) ? $key : (string) $key;

                    throw new InvalidArgumentException(
                        "deepReplicationDefaults() {$where} has unknown key [{$shown}]. Allowed: exclude, with, generate, transform, without_events, preserve_timestamps."
                    );
            }
        }

        return $rule;
    }

    /**
     * @return array<mixed>
     */
    private static function arrayOption(mixed $value, string $key, string $where): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("deepReplicationDefaults() {$where}.{$key} must be an array.");
        }

        return $value;
    }
}
