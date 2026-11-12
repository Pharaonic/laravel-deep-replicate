<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

/**
 * Something worth knowing about the planned graph that is not an error.
 */
final class PlanningWarning
{
    public const MISSING_TARGET = 'missing_target';

    public const UNLOADED_PIVOT_COLUMNS = 'unloaded_pivot_columns';

    public const GLOBAL_SCOPES_REMOVED = 'global_scopes_removed';

    private string $code;

    private ?string $path;

    private string $message;

    /**
     * @internal
     */
    public function __construct(string $code, ?string $path, string $message)
    {
        $this->code = $code;
        $this->path = $path;
        $this->message = $message;
    }

    /**
     * One of the PlanningWarning::* constants.
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * The relation path concerned, or null when the warning is operation-wide.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function __toString(): string
    {
        return $this->message;
    }
}
