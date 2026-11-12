<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

/**
 * Resolves a relation method safely.
 *
 * Only public, non-static, parameterless methods declared by the application
 * are called; framework and package methods (save, delete, replicate, …)
 * are never invoked, and methods whose declared return type cannot be a
 * relation are rejected without being called.
 *
 * @internal
 */
final class RelationResolver
{
    /** @var array<string, true> */
    private array $verified = [];

    public function resolve(Model $model, string $name, string $path): Relation
    {
        $class = get_class($model);

        if (! isset($this->verified[$class.'::'.$name])) {
            $this->verify($model, $name, $path);
            $this->verified[$class.'::'.$name] = true;
        }

        try {
            $relation = $model->{$name}();
        } catch (Throwable $exception) {
            throw new InvalidRelationshipPathException(
                "Relation path [{$path}] cannot be resolved on [{$class}]: calling {$name}() threw ".get_class($exception).'.',
                0,
                $exception
            );
        }

        if (! $relation instanceof Relation) {
            throw InvalidRelationshipPathException::notARelation(
                $class,
                $path,
                "{$name}() returned ".get_debug_type($relation).', not an Eloquent relation'
            );
        }

        return $relation;
    }

    private function verify(Model $model, string $name, string $path): void
    {
        $class = get_class($model);

        if (! method_exists($model, $name)) {
            throw InvalidRelationshipPathException::notARelation($class, $path, "method {$name}() does not exist");
        }

        $method = new ReflectionMethod($model, $name);

        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            throw InvalidRelationshipPathException::notARelation($class, $path, "{$name}() is not a public, non-static method without required parameters");
        }

        if ($this->isFrameworkMethod($model, $method)) {
            throw InvalidRelationshipPathException::notARelation($class, $path, "{$name}() is a framework or package method, not a relation");
        }

        $type = $method->getReturnType();

        if ($type !== null && ! $this->mayReturnRelation($type)) {
            throw InvalidRelationshipPathException::notARelation($class, $path, "{$name}() declares a return type that is not an Eloquent relation");
        }
    }

    private function isFrameworkMethod(Model $model, ReflectionMethod $method): bool
    {
        if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
            return true;
        }

        foreach (array_filter(class_uses_recursive($model), 'is_string') as $trait) {
            if ((str_starts_with($trait, 'Illuminate\\') || $trait === DeepReplicates::class)
                && method_exists($trait, $method->getName())) {
                return true;
            }
        }

        return false;
    }

    private function mayReturnRelation(\ReflectionType $type): bool
    {
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($types as $candidate) {
            if (! $candidate instanceof ReflectionNamedType) {
                return true;
            }

            $name = $candidate->getName();

            if (in_array($name, ['mixed', 'object'], true)) {
                return true;
            }

            if (! $candidate->isBuiltin() && (in_array($name, ['self', 'static'], true) === false)
                && (is_a($name, Relation::class, true) || interface_exists($name))) {
                return true;
            }
        }

        return false;
    }
}
