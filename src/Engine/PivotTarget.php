<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

/**
 * The pivot table description of one many-to-many path for one parent class.
 *
 * @internal
 */
final class PivotTarget
{
    public string $path;

    public string $relationName;

    public string $table;

    public string $foreignPivotKey;

    public string $relatedPivotKey;

    public string $parentKey;

    public string $relatedKey;

    public ?string $morphType;

    public ?string $morphClass;

    /** @var class-string<\Illuminate\Database\Eloquent\Relations\Pivot> */
    public string $pivotClass;

    public bool $customPivot;

    public string $pivotKeyName;

    public bool $withTimestamps;

    public string $createdAt;

    public string $updatedAt;

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Relations\Pivot>  $pivotClass
     */
    public function __construct(
        string $path,
        string $relationName,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $morphType,
        ?string $morphClass,
        string $pivotClass,
        bool $customPivot,
        string $pivotKeyName,
        bool $withTimestamps,
        string $createdAt,
        string $updatedAt
    ) {
        $this->path = $path;
        $this->relationName = $relationName;
        $this->table = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
        $this->morphType = $morphType;
        $this->morphClass = $morphClass;
        $this->pivotClass = $pivotClass;
        $this->customPivot = $customPivot;
        $this->pivotKeyName = $pivotKeyName;
        $this->withTimestamps = $withTimestamps;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Columns the package manages and callers cannot set.
     *
     * @return list<string>
     */
    public function managedColumns(): array
    {
        return array_values(array_filter([
            $this->foreignPivotKey,
            $this->relatedPivotKey,
            $this->morphType,
            $this->pivotKeyName,
        ], static function (?string $column): bool {
            return $column !== null && $column !== '';
        }));
    }
}
