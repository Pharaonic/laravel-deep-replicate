<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Unit;

use InvalidArgumentException;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use PHPUnit\Framework\TestCase;

class RelationRuleTest extends TestCase
{
    public function test_a_new_rule_is_empty(): void
    {
        $rule = new RelationRule;

        $this->assertTrue($rule->isEmpty());
        $this->assertFalse((new RelationRule)->withoutEvents()->isEmpty());
        $this->assertFalse((new RelationRule)->preserveTimestamps(false)->isEmpty());
    }

    public function test_settings_accumulate(): void
    {
        $first = function (array $attributes) {
            return $attributes;
        };
        $rule = (new RelationRule)
            ->exclude(['a'])
            ->exclude(['b', 'a'])
            ->with(['x' => 1])
            ->with(['y' => 2, 'x' => 3])
            ->transform($first)
            ->transform($first);

        $this->assertSame(['a', 'b'], $rule->excludedAttributes());
        $this->assertSame(['x' => 3, 'y' => 2], $rule->attributeOverrides());
        $this->assertCount(2, $rule->transforms());
    }

    public function test_a_generator_is_registered_once_per_attribute(): void
    {
        $rule = (new RelationRule)->generate('slug', 'strtoupper');

        $this->expectException(InvalidArgumentException::class);
        $rule->generate('slug', 'strtolower');
    }

    public function test_same_as_compares_values_and_callbacks_by_identity(): void
    {
        $callback = function () {
        };

        $this->assertTrue((new RelationRule)->with(['a' => 1])->generate('b', $callback)->sameAs((new RelationRule)->with(['a' => 1])->generate('b', $callback)));
        $this->assertFalse((new RelationRule)->generate('b', $callback)->sameAs((new RelationRule)->generate('b', function () {
        })));
        $this->assertFalse((new RelationRule)->with(['a' => 1])->sameAs((new RelationRule)->with(['a' => 2])));
        $this->assertFalse((new RelationRule)->exclude(['a'])->sameAs(new RelationRule));
    }

    public function test_over_gives_the_rule_precedence_per_setting(): void
    {
        $defaultTransform = function () {
        };
        $builderTransform = function () {
        };
        $defaults = (new RelationRule)->exclude(['d'])->with(['a' => 'default', 'b' => 'default'])->transform($defaultTransform)->withoutEvents();
        $builder = (new RelationRule)->with(['a' => 'builder'])->transform($builderTransform)->preserveTimestamps();

        $effective = $builder->over($defaults);

        $this->assertSame(['d'], $effective->excludedAttributes());
        $this->assertSame(['a' => 'builder', 'b' => 'default'], $effective->attributeOverrides());
        $this->assertSame([$builderTransform], $effective->transforms());
        $this->assertTrue($effective->eventsSuppressed());
        $this->assertTrue($effective->timestampsPreserved());
        $this->assertSame([$defaultTransform], (new RelationRule)->over($defaults)->transforms());
    }

    public function test_defaults_are_validated(): void
    {
        $rule = RelationRule::fromDefaults(['exclude' => ['a'], 'with' => ['b' => 1], 'generate' => ['c' => 'strtoupper'], 'without_events' => true, 'preserve_timestamps' => false], 'root');

        $this->assertSame(['a'], $rule->excludedAttributes());
        $this->assertTrue($rule->eventsSuppressed());
        $this->assertFalse($rule->timestampsPreserved());

        foreach ([['unknown' => 1], ['exclude' => 'a'], ['exclude' => [1]], ['with' => ['x']], ['generate' => ['a' => 'not callable']], ['transform' => 1], ['without_events' => 'yes'], ['preserve_timestamps' => 1]] as $invalid) {
            try {
                RelationRule::fromDefaults($invalid, 'root');
                $this->fail('Invalid defaults must throw: '.json_encode($invalid));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('deepReplicationDefaults()', $exception->getMessage());
            }
        }
    }
}
